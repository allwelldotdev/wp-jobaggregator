<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Jobs\JobData;

/**
 * Implements Hot Nigerian Jobs-specific RSS parsing, filtering, and normalization into JobData batches.
 */
class HotNigerianJobsRssSource extends AbstractRssSource {
	const DEFAULT_EXPIRY_DAYS = 31;

	private static $allowed_locations = array(
		'abia',
		'anambra',
		'ebonyi',
		'enugu',
		'imo',
		'rivers',
		'akwa ibom',
		'cross river',
		'delta',
		'benue',
		'kogi',
	);

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

	protected function map_item_to_job( $item ) {
		$defaults    = $this->defaults();
		$title       = $this->item_text( $item, 'title' );
		$description = $this->item_text( $item, 'description' );
		$guid        = $this->item_text( $item, 'guid' );
		$link        = $this->item_text( $item, 'link' );
		$pub_date    = $this->item_text( $item, 'pubDate' );
		$expiry_date = $this->first_present_item_text( $item, array( 'expiryDate', 'expires_at' ) );

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

		if ( $this->is_roundup_title( $title ) || $this->contains_any_whole_word( $title, self::$blocked_title_tokens ) ) {
			return null;
		}

		$title_company = $this->parse_title_company( $title );
		if ( null === $title_company ) {
			return null;
		}

		if ( ! $this->description_mentions_allowed_location( $description ) ) {
			return null;
		}

		$location = $this->parse_location( $description );
		if ( '' === $location ) {
			return null;
		}

		return JobData::from_array(
			array(
				'source_key'       => $this->get_key(),
				'external_id'      => (string) $guid,
				'title'            => $title_company['title'],
				'description'      => (string) $description,
				'source_url'       => (string) $link,
				'application_url'  => (string) $link,
				'company_name'     => $title_company['company'],
				'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
				'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
				'company_logo_url' => isset( $defaults['company_logo_url'] ) ? (string) $defaults['company_logo_url'] : null,
				'company_logo_id'  => isset( $defaults['company_logo_id'] ) ? (int) $defaults['company_logo_id'] : null,
				'location'         => $location,
				'employment_types' => isset( $defaults['employment_types'] ) ? (array) $defaults['employment_types'] : array(),
				'remote_position'  => ! empty( $defaults['remote_position'] ),
				'published_at'     => $this->format_datetime( $pub_date ),
				'expires_at'       => $this->derive_expires_at( $expiry_date, $pub_date ),
			)
		);
	}

	protected function parse_title_company( $title ) {
		$parts = preg_split( '/\s+at\s+/i', $this->normalize_text( $title ), 2 );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return null;
		}

		$job_title = trim( (string) $parts[0] );
		$company   = trim( (string) $parts[1] );

		if ( '' === $job_title || '' === $company ) {
			return null;
		}

		return array(
			'title'   => $job_title,
			'company' => $company,
		);
	}

	protected function is_roundup_title( $title ) {
		return 1 === preg_match( '/\(\s*\d+\s+positions?\s*\)/i', $this->normalize_text( $title ) );
	}

	protected function contains_any_whole_word( $value, array $tokens ) {
		$value = strtolower( $this->normalize_text( $value ) );
		if ( '' === $value ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			$token = strtolower( trim( (string) $token ) );
			if ( '' === $token ) {
				continue;
			}

			if ( 1 === preg_match( '/(?<![a-z0-9])' . preg_quote( $token, '/' ) . '(?![a-z0-9])/i', $value ) ) {
				return true;
			}
		}

		return false;
	}

	protected function description_mentions_allowed_location( $description ) {
		$description = strtolower( $this->normalize_text( $description ) );
		if ( '' === $description ) {
			return false;
		}

		foreach ( self::$allowed_locations as $location ) {
			if ( 1 === preg_match( '/(?<![a-z0-9])' . preg_quote( $location, '/' ) . '(?![a-z0-9])/i', $description ) ) {
				return true;
			}
		}

		return false;
	}

	protected function parse_location( $description ) {
		$description = $this->normalize_text( $description );
		$first_dot   = strpos( $description, '.' );
		if ( false === $first_dot ) {
			return '';
		}

		$after_first_sentence = substr( $description, $first_dot + 1 );
		if ( false === $after_first_sentence ) {
			return '';
		}

		$matches = array();
		if ( 1 !== preg_match( '/\bthe position is located in\s+(.+?)\s+states?\b/i', $after_first_sentence, $matches ) ) {
			return '';
		}

		$location = trim( (string) $matches[1] );
		$location = trim( $location, " \t\n\r\0\x0B.,;:-" );

		return $location;
	}

	protected function derive_expires_at( $expiry_date, $pub_date ) {
		$expiry_timestamp = $this->parse_date_timestamp( $expiry_date );
		if ( $expiry_timestamp > 0 ) {
			return gmdate( 'Y-m-d', $expiry_timestamp );
		}

		$published_timestamp = $this->parse_date_timestamp( $pub_date );
		if ( $published_timestamp > 0 ) {
			return gmdate( 'Y-m-d', $published_timestamp + ( 86400 * self::DEFAULT_EXPIRY_DAYS ) );
		}

		return '';
	}

	private function first_present_item_text( $item, array $tags ) {
		foreach ( $tags as $tag ) {
			$value = $this->item_text( $item, $tag );
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
}
