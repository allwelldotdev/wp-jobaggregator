<?php

namespace JobAggregator\Jobs;

/**
 * Represents the normalized job payload shared between sources and WordPress persistence.
 */
class JobData {
	public $source_key        = '';
	public $external_id       = '';
	public $title             = '';
	public $description       = '';
	public $source_url        = '';
	public $application_url   = '';
	public $application_email = '';
	public $company_name      = '';
	public $company_website   = '';
	public $company_tagline   = '';
	public $company_logo_url  = null;
	public $company_logo_id   = null;
	public $location          = '';
	public $employment_types  = array();
	public $job_categories    = array( 'other-automated' );
	public $remote_position   = false;
	public $salary            = '';
	public $salary_currency   = '';
	public $salary_unit       = '';
	public $published_at      = '';
	public $expires_at        = '';
	public $metadata          = array();

	public static function from_array( array $data ) {
		$job = new self();

		foreach ( $data as $key => $value ) {
			if ( property_exists( $job, $key ) ) {
				$job->{$key} = $value;
			}
		}

		return $job;
	}
}
