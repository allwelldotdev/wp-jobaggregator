<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Jobs\JobData;
use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Support\Logger;

/**
 * Implements We Work Remotely-specific RSS parsing, filtering, and normalization into JobData batches.
 */
class WeWorkRemotelyRssSource extends AbstractRssSource {
	const DEFAULT_EMPLOYMENT_TYPE = 'Full Time';

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

	private static $employment_type_map = array(
		'full time'  => 'Full Time',
		'freelance'  => 'Freelance',
		'internship' => 'Internship',
		'part time'  => 'Part Time',
		'temporary'  => 'Temporary',
		'contract'   => 'Contract',
		'onsite'     => 'Onsite',
		'on site'    => 'Onsite',
		'hybrid'     => 'Hybrid',
	);

	private $normalization_signals;

	public function __construct( array $config, Logger $logger, NormalizationSignalStore $normalization_signals ) {
		parent::__construct( $config, $logger );
		$this->normalization_signals = $normalization_signals;
	}

	protected function map_item_to_job( $item ) {
		$defaults    = $this->defaults();
		$title_raw   = $this->item_text( $item, 'title' );
		$description = $this->item_text( $item, 'description' );
		$state       = $this->item_text( $item, 'state' );
		$type_raw    = $this->item_text( $item, 'type' );
		$guid        = $this->item_text( $item, 'guid' );
		$link        = $this->item_text( $item, 'link' );
		$pub_date    = $this->item_text( $item, 'pubDate' );
		$expires_at  = $this->item_text( $item, 'expires_at' );

		if ( '' === $title_raw && method_exists( $item, 'get_title' ) ) {
			$title_raw = (string) $item->get_title();
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

		if ( $this->contains_any_token( $title_raw, self::$blocked_title_tokens ) ) {
			return null;
		}

		$title_company = $this->split_title_and_company( $title_raw );

		$location = isset( $defaults['location'] ) ? (string) $defaults['location'] : '';
		$state    = $this->normalize_text( $state );
		if ( '' !== $state ) {
			$location = '' !== $location ? $location . ', ' . $state : $state;
		}

		$employment_types = $this->map_employment_types( $type_raw, $guid, $title_company['title'] );
		$company_logo_url = $this->extract_media_logo_url( $item );

		if ( '' === $company_logo_url && isset( $defaults['company_logo_url'] ) ) {
			$company_logo_url = (string) $defaults['company_logo_url'];
		}

		return JobData::from_array(
			array(
				'source_key'       => $this->get_key(),
				'external_id'      => (string) $guid,
				'title'            => (string) $title_company['title'],
				'description'      => (string) $description,
				'source_url'       => (string) $link,
				'application_url'  => (string) $link,
				'company_name'     => '' !== $title_company['company_name'] ? $title_company['company_name'] : ( isset( $defaults['company_name'] ) ? (string) $defaults['company_name'] : '' ),
				'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
				'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
				'company_logo_url' => $company_logo_url,
				'company_logo_id'  => isset( $defaults['company_logo_id'] ) ? (int) $defaults['company_logo_id'] : null,
				'location'         => $location,
				'employment_types' => $employment_types,
				'remote_position'  => ! empty( $defaults['remote_position'] ),
				'published_at'     => $this->format_datetime( $pub_date ),
				'expires_at'       => $this->derive_expires_at( $expires_at, $pub_date ),
			)
		);
	}

	protected function derive_expires_at( $expires_at, $pub_date ) {
		$expiry_timestamp = $this->parse_date_timestamp( $expires_at );
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

	protected function split_title_and_company( $title ) {
		$title = $this->normalize_text( $title );
		if ( '' === $title ) {
			return array(
				'company_name' => '',
				'title'        => '',
			);
		}

		$parts = explode( ':', $title, 2 );
		if ( 2 !== count( $parts ) ) {
			return array(
				'company_name' => '',
				'title'        => $title,
			);
		}

		return array(
			'company_name' => $this->normalize_text( $parts[0] ),
			'title'        => $this->normalize_text( $parts[1] ),
		);
	}

	protected function map_employment_types( $type_raw, $external_id, $title ) {
		$type_raw = $this->normalize_text( $type_raw );
		if ( '' === $type_raw ) {
			return array( self::DEFAULT_EMPLOYMENT_TYPE );
		}

		$resolved = $this->resolve_employment_types_from_raw( $type_raw );
		if ( ! empty( $resolved ) ) {
			return $resolved;
		}

		$this->normalization_signals->record(
			$this->get_key(),
			'employment_type_unmatched',
			$type_raw,
			$this->normalize_token( $type_raw ),
			$external_id,
			$title
		);

		return array( self::DEFAULT_EMPLOYMENT_TYPE );
	}

	protected function extract_media_logo_url( $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_item_tags' ) ) {
			return '';
		}

		$nodes = $item->get_item_tags( 'http://search.yahoo.com/mrss', 'content' );
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return '';
		}

		foreach ( $nodes as $node ) {
			$url = $this->extract_media_url_from_node( $node );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	private function resolve_employment_types_from_raw( $raw_value ) {
		$raw_value = $this->normalize_text( $raw_value );
		if ( '' === $raw_value ) {
			return array();
		}

		$parts      = preg_split( '/[,\/|]/', $raw_value );
		$recognized = array();

		if ( ! is_array( $parts ) || empty( $parts ) ) {
			$parts = array( $raw_value );
		}

		foreach ( $parts as $part ) {
			$token = $this->normalize_token( $part );
			if ( isset( self::$employment_type_map[ $token ] ) ) {
				$recognized[] = self::$employment_type_map[ $token ];
			}
		}

		$recognized = array_values( array_unique( $recognized ) );
		if ( empty( $recognized ) ) {
			return array();
		}

		if ( in_array( self::DEFAULT_EMPLOYMENT_TYPE, $recognized, true ) && count( $recognized ) > 1 ) {
			foreach ( $recognized as $value ) {
				if ( self::DEFAULT_EMPLOYMENT_TYPE !== $value ) {
					return array( $value );
				}
			}
		}

		return $recognized;
	}

	private function format_datetime( $value ) {
		$timestamp = $this->parse_date_timestamp( $value );
		if ( $timestamp <= 0 ) {
			return '';
		}

		return gmdate( 'c', $timestamp );
	}

	private function normalize_token( $value ) {
		$value = strtolower( $this->normalize_text( $value ) );
		$value = str_replace( array( '-', '_' ), ' ', $value );

		$normalized = preg_replace( '/\s+/', ' ', $value );

		return is_string( $normalized ) ? trim( $normalized ) : trim( $value );
	}

	private function extract_media_url_from_node( $node ) {
		if ( is_string( $node ) ) {
			return '';
		}

		if ( ! is_array( $node ) ) {
			return '';
		}

		if ( isset( $node['@url'] ) && is_string( $node['@url'] ) ) {
			return trim( $node['@url'] );
		}

		if ( isset( $node['url'] ) && is_string( $node['url'] ) ) {
			return trim( $node['url'] );
		}

		if ( isset( $node['attribs'] ) && is_array( $node['attribs'] ) ) {
			foreach ( $node['attribs'] as $attrib_values ) {
				if ( ! is_array( $attrib_values ) ) {
					continue;
				}

				if ( isset( $attrib_values['url'] ) && is_string( $attrib_values['url'] ) ) {
					return trim( $attrib_values['url'] );
				}
			}
		}

		foreach ( $node as $child ) {
			$url = $this->extract_media_url_from_node( $child );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}
}
