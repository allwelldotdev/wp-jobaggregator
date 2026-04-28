<?php

namespace JobAggregator\Tests\Unit\Sources\RSS;

use JobAggregator\Sources\RSS\MyJobMagRssSource;
use JobAggregator\Support\Logger;
use JobAggregator\Tests\Support\MemoryNormalizationSignalStore;
use PHPUnit\Framework\TestCase;

class TestableMyJobMagRssSource extends MyJobMagRssSource {
	public function is_allowed_location_for_test( $location ) {
		return $this->is_allowed_location( $location );
	}

	public function map_location_for_test( $location, array $defaults ) {
		return $this->map_location( $location, $defaults );
	}

	public function derive_expires_at_for_test( $expiry_date, $pub_date ) {
		return $this->derive_expires_at( $expiry_date, $pub_date );
	}

	public function is_remote_position_for_test( array $values ) {
		return $this->is_remote_position( $values );
	}

	public function map_employment_types_for_test( $working_hours, $contract, $external_id, $title ) {
		return $this->map_employment_types( $working_hours, $contract, $external_id, $title );
	}

	public function apply_salary_mapping_for_test( array $job_payload, $salary_raw ) {
		return $this->apply_salary_mapping( $job_payload, $salary_raw );
	}

	public function normalize_title_for_test( $title ) {
		return $this->normalize_title( $title );
	}
}

class MyJobMagRssSourceTest extends TestCase {
	private $source;
	private $signals;

	protected function setUp(): void {
		$this->signals = new MemoryNormalizationSignalStore();
		$this->source  = new TestableMyJobMagRssSource(
			array(
				'key'   => 'myjobmag',
				'label' => 'MyJobMag',
				'url'   => 'https://example.test/feed.xml',
			),
			new Logger(),
			$this->signals
		);
	}

	public function test_location_filter_accepts_single_and_comma_joined_target_states() {
		$this->assertTrue( $this->source->is_allowed_location_for_test( 'Abia' ) );
		$this->assertTrue( $this->source->is_allowed_location_for_test( 'Lagos, Enugu' ) );
		$this->assertTrue( $this->source->is_allowed_location_for_test( 'all' ) );
	}

	public function test_title_normalization_splits_standalone_at_delimiter() {
		$this->assertSame(
			'Nutritionist / Quality Control (Offshore)',
			$this->source->normalize_title_for_test( 'Nutritionist / Quality Control (Offshore) at Castel Resources Consultancy Limited' )
		);
	}

	public function test_title_normalization_keeps_title_without_standalone_delimiter() {
		$this->assertSame(
			'Platform Engineer',
			$this->source->normalize_title_for_test( 'Platform Engineer' )
		);
		$this->assertSame(
			'Senior Data Analyst',
			$this->source->normalize_title_for_test( 'Senior Data Analyst' )
		);
	}

	public function test_location_filter_rejects_non_target_locations() {
		$this->assertFalse( $this->source->is_allowed_location_for_test( 'Lagos' ) );
		$this->assertFalse( $this->source->is_allowed_location_for_test( '' ) );
	}

	public function test_location_mapping_uses_default_for_all_source_location() {
		$defaults = array( 'location' => 'Nigeria' );

		$this->assertSame( 'Nigeria', $this->source->map_location_for_test( 'All', $defaults ) );
		$this->assertSame( 'Nigeria', $this->source->map_location_for_test( ' all ', $defaults ) );
		$this->assertSame( 'Abia', $this->source->map_location_for_test( 'Abia', $defaults ) );
	}

	public function test_expires_at_prefers_expiry_date_when_present() {
		$result = $this->source->derive_expires_at_for_test( 'Mon, 20 Apr 2026 00:00:00', 'Tue, 31 Mar 2026 12:19:24' );

		$this->assertSame( '2026-04-20', $result );
	}

	public function test_expires_at_falls_back_to_pub_date_plus_31_days() {
		$result   = $this->source->derive_expires_at_for_test( '', 'Tue, 31 Mar 2026 12:19:24' );
		$expected = gmdate( 'Y-m-d', strtotime( 'Tue, 31 Mar 2026 12:19:24' ) + ( 86400 * 31 ) );

		$this->assertSame( $expected, $result );
	}

	public function test_remote_detection_checks_designated_fields() {
		$this->assertTrue(
			$this->source->is_remote_position_for_test(
				array(
					'Backend Engineer',
					'',
					'',
					'Contract',
					'Remote',
				)
			)
		);
		$this->assertFalse(
			$this->source->is_remote_position_for_test(
				array(
					'Backend Engineer',
					'',
					'',
					'Contract',
					'Full Time',
				)
			)
		);
	}

	public function test_employment_type_mapping_handles_allowlist_and_compound_values() {
		$this->assertSame(
			array( 'Hybrid' ),
			$this->source->map_employment_types_for_test( 'Full Time, Hybrid', 'Contract', 'job-1', 'Hybrid Role' )
		);
		$this->assertSame(
			array( 'Contract' ),
			$this->source->map_employment_types_for_test( 'Contract', 'Permanent', 'job-2', 'Contract Role' )
		);
		$this->assertSame(
			array( 'Full Time' ),
			$this->source->map_employment_types_for_test( 'Full Time, Remote', '', 'job-remote-1', 'Remote Full Time Role' )
		);
	}

	public function test_employment_type_mapping_uses_default_for_remote_only_without_signal_noise() {
		$employment_types = $this->source->map_employment_types_for_test( 'Remote', '', 'job-remote-2', 'Remote Role' );

		$this->assertSame( array( 'Full Time' ), $employment_types );
		$this->assertCount( 0, $this->signals->records );
	}

	public function test_employment_type_mapping_defaults_and_records_unmatched_values() {
		$employment_types = $this->source->map_employment_types_for_test( 'Permanent', 'Seasonal', 'job-3', 'Fallback Role' );

		$this->assertSame( array( 'Full Time' ), $employment_types );
		$this->assertCount( 1, $this->signals->records );
		$this->assertSame( 'employment_type_unmatched', $this->signals->records[0]['signal_type'] );
		$this->assertSame( 'Permanent', $this->signals->records[0]['raw_value'] );
	}

	public function test_employment_type_mapping_records_only_unknown_tokens_after_remote_handling() {
		$employment_types = $this->source->map_employment_types_for_test( 'Remote, Seasonal', '', 'job-4', 'Remote Seasonal Role' );

		$this->assertSame( array( 'Full Time' ), $employment_types );
		$this->assertCount( 1, $this->signals->records );
		$this->assertSame( 'Seasonal', $this->signals->records[0]['raw_value'] );
	}

	public function test_salary_mapping_sets_currency_and_unit_only_when_salary_has_value() {
		$without_salary = $this->source->apply_salary_mapping_for_test( array(), '' );
		$with_salary    = $this->source->apply_salary_mapping_for_test( array(), '500000' );

		$this->assertArrayNotHasKey( 'salary', $without_salary );
		$this->assertSame( '500000', $with_salary['salary'] );
		$this->assertSame( 'NGN', $with_salary['salary_currency'] );
		$this->assertSame( 'Monthly', $with_salary['salary_unit'] );
	}
}
