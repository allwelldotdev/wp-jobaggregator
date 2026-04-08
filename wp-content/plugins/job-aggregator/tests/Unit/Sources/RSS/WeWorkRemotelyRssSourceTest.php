<?php

namespace JobAggregator\Tests\Unit\Sources\RSS;

use JobAggregator\Sources\RSS\WeWorkRemotelyRssSource;
use JobAggregator\Support\Logger;
use JobAggregator\Tests\Support\FakeRssItem;
use JobAggregator\Tests\Support\MemoryNormalizationSignalStore;
use PHPUnit\Framework\TestCase;

class TestableWeWorkRemotelyRssSource extends WeWorkRemotelyRssSource {
	public function map_item_to_job_for_test( $item ) {
		return $this->map_item_to_job( $item );
	}

	public function split_title_and_company_for_test( $title ) {
		return $this->split_title_and_company( $title );
	}

	public function map_employment_types_for_test( $type_raw, $external_id, $title ) {
		return $this->map_employment_types( $type_raw, $external_id, $title );
	}

	public function derive_expires_at_for_test( $expires_at, $pub_date ) {
		return $this->derive_expires_at( $expires_at, $pub_date );
	}
}

class WeWorkRemotelyRssSourceTest extends TestCase {
	private $source;
	private $signals;

	protected function setUp(): void {
		$this->signals = new MemoryNormalizationSignalStore();
		$this->source  = new TestableWeWorkRemotelyRssSource(
			array(
				'key'      => 'weworkremotely',
				'label'    => 'We Work Remotely',
				'url'      => 'https://example.test/wwr.xml',
				'defaults' => array(
					'location'         => 'Anywhere in the World',
					'company_name'     => 'Default Company',
					'employment_types' => array( 'Full Time' ),
					'remote_position'  => true,
				),
			),
			new Logger(),
			$this->signals
		);
	}

	public function test_title_split_and_no_colon_fallback() {
		$split = $this->source->split_title_and_company_for_test( 'Teamflect: Growth Marketing Lead' );
		$plain = $this->source->split_title_and_company_for_test( 'Backend Engineer' );

		$this->assertSame( 'Teamflect', $split['company_name'] );
		$this->assertSame( 'Growth Marketing Lead', $split['title'] );
		$this->assertSame( '', $plain['company_name'] );
		$this->assertSame( 'Backend Engineer', $plain['title'] );
	}

	public function test_maps_guid_state_location_type_and_logo() {
		$item = new FakeRssItem(
			array(
				'title'       => 'Acme: Backend Engineer',
				'state'       => 'California',
				'type'        => 'Full-Time',
				'pubDate'     => 'Tue, 31 Mar 2026 08:24:37 +0000',
				'expires_at'  => 'Thu, 30 Apr 2026 08:24:37 +0000',
				'guid'        => 'https://example.test/wwr-123',
				'link'        => 'https://example.test/wwr-123',
				'description' => 'Description',
			),
			array(
				'http://search.yahoo.com/mrss|content' => array( '@url' => 'https://example.test/wwr-logo.gif' ),
			)
		);
		$job  = $this->source->map_item_to_job_for_test( $item );

		$this->assertSame( 'https://example.test/wwr-123', $job->external_id );
		$this->assertSame( 'Acme', $job->company_name );
		$this->assertSame( 'Backend Engineer', $job->title );
		$this->assertSame( 'Anywhere in the World, California', $job->location );
		$this->assertSame( array( 'Full Time' ), $job->employment_types );
		$this->assertSame( 'https://example.test/wwr-logo.gif', $job->company_logo_url );
		$this->assertSame( '2026-04-30', $job->expires_at );
	}

	public function test_employment_type_fallback_records_signal() {
		$employment_types = $this->source->map_employment_types_for_test( 'Permanent', 'wwr-2', 'Backend Engineer' );

		$this->assertSame( array( 'Full Time' ), $employment_types );
		$this->assertCount( 1, $this->signals->records );
		$this->assertSame( 'employment_type_unmatched', $this->signals->records[0]['signal_type'] );
		$this->assertSame( 'Permanent', $this->signals->records[0]['raw_value'] );
	}

	public function test_expiry_falls_back_to_pubdate_plus_31_days() {
		$expires_at = $this->source->derive_expires_at_for_test( '', 'Tue, 31 Mar 2026 08:24:37 +0000' );

		$this->assertSame( gmdate( 'Y-m-d', strtotime( 'Tue, 31 Mar 2026 08:24:37 +0000' ) + ( 86400 * 31 ) ), $expires_at );
	}

	public function test_filters_blocked_title_tokens() {
		$item = new FakeRssItem(
			array(
				'title'       => 'Acme: Senior Backend Engineer',
				'type'        => 'Full-Time',
				'guid'        => 'wwr-3',
				'link'        => 'https://example.test/wwr-3',
				'description' => 'Description',
			)
		);

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}
}
