<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Jobs\JobData;
use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Support\Logger;

/**
 * Implements MyJobMag-specific RSS parsing, filtering, and normalization into JobData batches.
 */
class MyJobMagRssSource extends AbstractRssSource {
	const DEFAULT_EMPLOYMENT_TYPE = 'Full Time';

	private static $allowed_locations = array(
		'abia'        => true,
		'anambra'     => true,
		'ebonyi'      => true,
		'enugu'       => true,
		'imo'         => true,
		'all'         => true,
		'rivers'      => true,
		'akwa ibom'   => true,
		'cross river' => true,
		'delta'       => true,
		'benue'       => true,
		'kogi'        => true,
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
		$external_id = $this->item_text( $item, 'id' );
		$source_url  = $this->item_text( $item, 'link' );
		$title       = $this->item_text( $item, 'title' );
		$position    = $this->item_text( $item, 'position' );
		$intro       = $this->item_text( $item, 'introduction' );
		$company     = $this->item_text( $item, 'company' );
		$description = $this->item_text( $item, 'description' );
		$contract    = $this->item_text( $item, 'contract' );
		$working     = $this->item_text( $item, 'working_hours' );
		$location    = $this->item_text( $item, 'location' );
		$salary_raw  = $this->item_text( $item, 'salary' );
		$pub_date    = $this->item_text( $item, 'pubDate' );
		$expiry_date = $this->item_text( $item, 'expiryDate' );

		if ( '' === $external_id ) {
			$external_id = (string) ( method_exists( $item, 'get_id' ) ? $item->get_id() : '' );
		}

		if ( '' === $source_url ) {
			$source_url = (string) ( method_exists( $item, 'get_link' ) ? $item->get_link() : '' );
		}

		if ( '' === $title ) {
			$title = (string) ( method_exists( $item, 'get_title' ) ? $item->get_title() : '' );
		}

		if ( '' === $description ) {
			$description = (string) ( method_exists( $item, 'get_content' ) ? $item->get_content() : '' );
		}

		if ( '' === $description ) {
			$description = (string) ( method_exists( $item, 'get_description' ) ? $item->get_description() : '' );
		}

		if ( '' === $description ) {
			$description = $intro;
		}

		if ( ! $this->is_allowed_location( $location ) ) {
			return null;
		}

		$published_timestamp = $this->parse_date_timestamp( $pub_date );
		$employment_types    = $this->map_employment_types( $working, $contract, $external_id, $title );

		$job_payload = array(
			'source_key'       => $this->get_key(),
			'external_id'      => (string) $external_id,
			'title'            => '' !== $title ? (string) $title : (string) $position,
			'description'      => (string) $description,
			'source_url'       => (string) $source_url,
			'application_url'  => (string) $source_url,
			'company_name'     => '' !== $company ? (string) $company : ( isset( $defaults['company_name'] ) ? (string) $defaults['company_name'] : '' ),
			'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
			'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
			'location'         => '' !== $location ? (string) $location : ( isset( $defaults['location'] ) ? (string) $defaults['location'] : '' ),
			'employment_types' => $employment_types,
			'remote_position'  => $this->is_remote_position(
				array(
					$title,
					$position,
					$intro,
					$contract,
					$working,
				)
			) || ! empty( $defaults['remote_position'] ),
			'published_at'     => $published_timestamp > 0 ? gmdate( 'c', $published_timestamp ) : '',
			'expires_at'       => $this->derive_expires_at( $expiry_date, $pub_date ),
		);

		$job_payload = $this->apply_salary_mapping( $job_payload, $salary_raw );

		return JobData::from_array( $job_payload );
	}

	protected function is_allowed_location( $location ) {
		$location = $this->normalize_text( $location );
		if ( '' === $location ) {
			return false;
		}

		$parts = false !== strpos( $location, ',' ) ? explode( ',', $location ) : array( $location );

		foreach ( $parts as $part ) {
			$token = $this->normalize_token( $part );
			if ( isset( self::$allowed_locations[ $token ] ) ) {
				return true;
			}
		}

		return false;
	}

	protected function is_remote_position( array $values ) {
		foreach ( $values as $value ) {
			$value = strtolower( $this->normalize_text( $value ) );
			if ( '' !== $value && false !== strpos( $value, 'remote' ) ) {
				return true;
			}
		}

		return false;
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

	protected function map_employment_types( $working_hours, $contract, $external_id, $title ) {
		$working_hours = $this->normalize_text( $working_hours );
		$contract      = $this->normalize_text( $contract );

		$employment_types = $this->resolve_employment_types_from_raw( $working_hours );
		if ( ! empty( $employment_types ) ) {
			return $employment_types;
		}

		$employment_types = $this->resolve_employment_types_from_raw( $contract );
		if ( ! empty( $employment_types ) ) {
			return $employment_types;
		}

		$raw_for_signal = '' !== $working_hours ? $working_hours : $contract;
		if ( '' !== $raw_for_signal ) {
			$this->normalization_signals->record(
				$this->get_key(),
				'employment_type_unmatched',
				$raw_for_signal,
				$this->normalize_token( $raw_for_signal ),
				$external_id,
				$title
			);
		}

		return array( self::DEFAULT_EMPLOYMENT_TYPE );
	}

	protected function apply_salary_mapping( array $job_payload, $salary_raw ) {
		$salary_raw = trim( (string) $salary_raw );
		if ( '' === $salary_raw ) {
			return $job_payload;
		}

		$job_payload['salary']          = $salary_raw;
		$job_payload['salary_currency'] = 'NGN';
		$job_payload['salary_unit']     = 'Monthly';

		return $job_payload;
	}

	private function resolve_employment_types_from_raw( $raw_value ) {
		$raw_value = $this->normalize_text( $raw_value );
		if ( '' === $raw_value ) {
			return array();
		}

		$parts      = false !== strpos( $raw_value, ',' ) ? explode( ',', $raw_value ) : array( $raw_value );
		$recognized = array();

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

	private function normalize_token( $value ) {
		$value = strtolower( $this->normalize_text( $value ) );
		$value = str_replace( array( '-', '_' ), ' ', $value );

		$normalized = preg_replace( '/\s+/', ' ', $value );

		return is_string( $normalized ) ? $normalized : $value;
	}
}
