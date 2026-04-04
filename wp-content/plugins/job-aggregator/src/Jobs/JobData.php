<?php

namespace JobAggregator\Jobs;

class JobData {
    public $source_key = '';
    public $external_id = '';
    public $title = '';
    public $description = '';
    public $source_url = '';
    public $application_url = '';
    public $application_email = '';
    public $company_name = '';
    public $company_website = '';
    public $company_tagline = '';
    public $location = '';
    public $employment_types = [];
    public $remote_position = false;
    public $salary = '';
    public $salary_currency = '';
    public $salary_unit = '';
    public $published_at = '';
    public $expires_at = '';
    public $metadata = [];

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
