<?php

namespace JobAggregator\Jobs;

/**
 * Detects existing listings by a stable identity key so imports update instead of duplicating.
 */
class DuplicateChecker {
	const META_SOURCE_KEY          = '_job_aggregator_source_key';
	const META_EXTERNAL_ID         = '_job_aggregator_external_id';
	const META_SOURCE_URL          = '_job_aggregator_source_url';
	const META_IDENTITY_KEY        = '_job_aggregator_identity_key';
	const META_CONTENT_FINGERPRINT = '_job_aggregator_content_fingerprint';

	public function find_existing_id( JobData $job ) {
		$identity_key = $this->build_identity_key( $job );
		$meta_query   = array();

		if ( '' !== $identity_key ) {
			$meta_query[] = array(
				'key'   => self::META_IDENTITY_KEY,
				'value' => $identity_key,
			);
		}

		$legacy_meta_query = $this->legacy_meta_query( $job );
		if ( ! empty( $legacy_meta_query ) ) {
			$meta_query[] = $legacy_meta_query;
		}

		if ( empty( $meta_query ) ) {
			return 0;
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query = array_merge(
				array( 'relation' => 'OR' ),
				$meta_query
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'job_listing',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Stable identity matching requires post meta lookup.
				'meta_query'     => $meta_query,
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}

	public function build_identity_key( JobData $job ) {
		$source_key = sanitize_key( (string) $job->source_key );
		if ( '' === $source_key ) {
			return '';
		}

		$external_id = trim( (string) $job->external_id );
		if ( '' !== $external_id ) {
			return $source_key . '|external_id|' . $external_id;
		}

		$normalized_source_url = $this->normalize_source_url( $job->source_url );
		if ( '' === $normalized_source_url ) {
			return '';
		}

		return $source_key . '|source_url|' . $normalized_source_url;
	}

	public function normalize_source_url( $source_url ) {
		$normalized_url = esc_url_raw( trim( (string) $source_url ) );
		if ( '' === $normalized_url ) {
			return '';
		}

		return strtolower( untrailingslashit( $normalized_url ) );
	}

	private function legacy_meta_query( JobData $job ) {
		$source_key = sanitize_key( (string) $job->source_key );
		if ( '' === $source_key ) {
			return array();
		}

		$external_id = trim( (string) $job->external_id );
		if ( '' !== $external_id ) {
			return array(
				'relation' => 'AND',
				array(
					'key'   => self::META_SOURCE_KEY,
					'value' => $source_key,
				),
				array(
					'key'   => self::META_EXTERNAL_ID,
					'value' => $external_id,
				),
			);
		}

		$normalized_source_url = $this->normalize_source_url( $job->source_url );
		$raw_source_url        = trim( (string) $job->source_url );

		if ( '' === $normalized_source_url && '' === $raw_source_url ) {
			return array();
		}

		$url_meta_options = array(
			'relation' => 'OR',
		);

		if ( '' !== $raw_source_url ) {
			$url_meta_options[] = array(
				'key'   => self::META_SOURCE_URL,
				'value' => $raw_source_url,
			);
		}

		if ( '' !== $normalized_source_url && $normalized_source_url !== $raw_source_url ) {
			$url_meta_options[] = array(
				'key'   => self::META_SOURCE_URL,
				'value' => $normalized_source_url,
			);
		}

		if ( count( $url_meta_options ) < 2 ) {
			return array();
		}

		return array(
			'relation' => 'AND',
			array(
				'key'   => self::META_SOURCE_KEY,
				'value' => $source_key,
			),
			$url_meta_options,
		);
	}
}
