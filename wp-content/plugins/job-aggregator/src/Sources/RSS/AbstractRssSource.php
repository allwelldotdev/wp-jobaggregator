<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Batch\SourceBatchResult;
use JobAggregator\Jobs\JobData;
use JobAggregator\Sources\AbstractSource;
use RuntimeException;

/**
 * Handles shared RSS batching mechanics and delegates per-item mapping to subclasses.
 */
abstract class AbstractRssSource extends AbstractSource {
	public function initial_checkpoint() {
		return array(
			'offset' => 0,
		);
	}

	public function fetch_batch( array $checkpoint ) {
		include_once ABSPATH . WPINC . '/feed.php';

		$url  = $this->require_config( 'url' );
		$feed = fetch_feed( $url );

		if ( is_wp_error( $feed ) ) {
			throw new RuntimeException( $feed->get_error_message() );
		}

		$max_items  = isset( $this->config['limit'] ) ? (int) $this->config['limit'] : 20;
		$offset     = isset( $checkpoint['offset'] ) ? max( 0, (int) $checkpoint['offset'] ) : 0;
		$batch_size = $this->get_batch_size();
		$items      = $feed->get_items( 0, max( $max_items, $offset + $batch_size ) );
		$slice      = array_slice( $items, $offset, $batch_size );
		$jobs       = array();

		foreach ( $slice as $item ) {
			$job = $this->map_item_to_job( $item );
			if ( $job instanceof JobData ) {
				$jobs[] = $job;
			}
		}

		$next_offset = $offset + count( $slice );
		$has_more    = $next_offset < count( $items ) && $next_offset < $max_items;

		return SourceBatchResult::success(
			$jobs,
			$has_more,
			array(
				'offset' => $next_offset,
			)
		);
	}

	abstract protected function map_item_to_job( $item );

	protected function item_text( $item, $tag ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_item_tags' ) ) {
			return '';
		}

		$nodes = $item->get_item_tags( '', (string) $tag );
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return '';
		}

		foreach ( $nodes as $node ) {
			$value = $this->extract_tag_value( $node );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	protected function normalize_text( $value ) {
		return trim( (string) $value );
	}

	protected function parse_date_timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? 0 : (int) $timestamp;
	}

	private function extract_tag_value( $node ) {
		if ( is_scalar( $node ) ) {
			return trim( (string) $node );
		}

		if ( ! is_array( $node ) ) {
			return '';
		}

		if ( isset( $node['data'] ) && is_scalar( $node['data'] ) ) {
			return trim( (string) $node['data'] );
		}

		if ( isset( $node['#text'] ) && is_scalar( $node['#text'] ) ) {
			return trim( (string) $node['#text'] );
		}

		foreach ( $node as $child ) {
			$value = $this->extract_tag_value( $child );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
