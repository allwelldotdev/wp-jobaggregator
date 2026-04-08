<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Jobs\JobData;

/**
 * Implements RemoteOK-specific RSS parsing, filtering, and normalization into JobData batches.
 */
class RemoteOkRssSource extends AbstractRssSource {
	private static $blocked_title_tokens = array(
		'senior',
		'staff',
		'manager',
		'specialist',
		'consultant',
		'director',
		'associate',
		'principal',
		'latam',
		'lead',
		'head',
		'expert',
	);

	private static $blocked_tag_tokens = array(
		'senior',
		'management',
		'manager',
		'leader',
		'director',
		'consulting',
		'expert',
	);

	protected function map_item_to_job( $item ) {
		$defaults    = $this->defaults();
		$title       = $this->item_text( $item, 'title' );
		$tags        = $this->item_text( $item, 'tags' );
		$company     = $this->item_text( $item, 'company' );
		$description = $this->item_text( $item, 'description' );
		$location    = $this->item_text( $item, 'location' );
		$guid        = $this->item_text( $item, 'guid' );
		$link        = $this->item_text( $item, 'link' );
		$pub_date    = $this->item_text( $item, 'pubDate' );
		$expiry_date = $this->item_text( $item, 'expiryDate' );
		$image_url   = $this->extract_image_url( $item );

		if ( '' === $title && method_exists( $item, 'get_title' ) ) {
			$title = (string) $item->get_title();
		}

		if ( '' === $description && method_exists( $item, 'get_content' ) ) {
			$description = (string) $item->get_content();
		}

		if ( '' === $description && method_exists( $item, 'get_description' ) ) {
			$description = (string) $item->get_description();
		}

		if ( '' === $guid && method_exists( $item, 'get_id' ) ) {
			$guid = (string) $item->get_id();
		}

		if ( '' === $link && method_exists( $item, 'get_link' ) ) {
			$link = (string) $item->get_link();
		}

		if ( $this->contains_any_token( $title, self::$blocked_title_tokens ) ) {
			return null;
		}

		if ( $this->contains_any_token( $tags, self::$blocked_tag_tokens ) ) {
			return null;
		}

		if ( ! $this->is_allowed_location( $location ) ) {
			return null;
		}

		$normalized_location = $this->normalize_text( $location );
		$location_value      = isset( $defaults['location'] ) ? (string) $defaults['location'] : '';

		if ( '' !== $normalized_location && 'remote' !== strtolower( $normalized_location ) ) {
			$location_value = $normalized_location;
		}

		if ( 'remote' === strtolower( $normalized_location ) && '' === $location_value ) {
			$location_value = 'remote';
		}

		$company_logo_url = isset( $defaults['company_logo_url'] ) ? (string) $defaults['company_logo_url'] : null;
		if ( '' !== trim( (string) $image_url ) ) {
			$company_logo_url = $image_url;
		}

		return JobData::from_array(
			array(
				'source_key'       => $this->get_key(),
				'external_id'      => (string) $guid,
				'title'            => (string) $title,
				'description'      => (string) $description,
				'source_url'       => (string) $link,
				'application_url'  => (string) $link,
				'company_name'     => '' !== $company ? (string) $company : ( isset( $defaults['company_name'] ) ? (string) $defaults['company_name'] : '' ),
				'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
				'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
				'company_logo_url' => $company_logo_url,
				'company_logo_id'  => isset( $defaults['company_logo_id'] ) ? (int) $defaults['company_logo_id'] : null,
				'location'         => $location_value,
				'employment_types' => isset( $defaults['employment_types'] ) ? (array) $defaults['employment_types'] : array(),
				'remote_position'  => ! empty( $defaults['remote_position'] ),
				'published_at'     => $this->format_datetime( $pub_date ),
				'expires_at'       => $this->derive_expires_at( $expiry_date, $pub_date ),
			)
		);
	}

	protected function is_allowed_location( $location ) {
		$location = $this->normalize_text( $location );
		if ( '' === $location ) {
			return true;
		}

		return 'remote' === strtolower( $location );
	}

	protected function derive_expires_at( $expiry_date, $pub_date ) {
		$expiry_timestamp = $this->parse_date_timestamp( $expiry_date );
		if ( $expiry_timestamp > 0 ) {
			return gmdate( 'Y-m-d', $expiry_timestamp );
		}

		$published_timestamp = $this->parse_date_timestamp( $pub_date );
		if ( $published_timestamp > 0 ) {
			return gmdate( 'Y-m-d', $published_timestamp + ( 86400 * 31 ) );
		}

		return '';
	}

	protected function contains_any_token( $value, array $tokens ) {
		$value = strtolower( $this->normalize_text( $value ) );
		if ( '' === $value ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( false !== strpos( $value, strtolower( (string) $token ) ) ) {
				return true;
			}
		}

		return false;
	}

	protected function extract_image_url( $item ) {
		$image = $this->item_text( $item, 'image' );
		if ( '' !== $image ) {
			return $image;
		}

		if ( ! is_object( $item ) || ! method_exists( $item, 'get_item_tags' ) ) {
			return '';
		}

		$nodes = $item->get_item_tags( '', 'image' );
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return '';
		}

		foreach ( $nodes as $node ) {
			$value = $this->extract_url_from_node( $node );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function format_datetime( $value ) {
		$timestamp = $this->parse_date_timestamp( $value );
		if ( $timestamp <= 0 ) {
			return '';
		}

		return gmdate( 'c', $timestamp );
	}

	private function extract_url_from_node( $node ) {
		if ( is_string( $node ) ) {
			return trim( $node );
		}

		if ( ! is_array( $node ) ) {
			return '';
		}

		if ( isset( $node['url'] ) && is_string( $node['url'] ) ) {
			return trim( $node['url'] );
		}

		if ( isset( $node['@url'] ) && is_string( $node['@url'] ) ) {
			return trim( $node['@url'] );
		}

		if ( isset( $node['data'] ) && is_string( $node['data'] ) ) {
			return trim( $node['data'] );
		}

		foreach ( $node as $child ) {
			$value = $this->extract_url_from_node( $child );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
