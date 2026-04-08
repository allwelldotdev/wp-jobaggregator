<?php

namespace JobAggregator\Tests\Unit\Sources\RSS;

use JobAggregator\Sources\RSS\RemoteOkRssSource;
use JobAggregator\Support\Logger;
use JobAggregator\Tests\Support\FakeRssItem;
use PHPUnit\Framework\TestCase;

class TestableRemoteOkRssSource extends RemoteOkRssSource {
	public function map_item_to_job_for_test( $item ) {
		return $this->map_item_to_job( $item );
	}

	public function is_allowed_location_for_test( $location ) {
		return $this->is_allowed_location( $location );
	}

	public function derive_expires_at_for_test( $expiry_date, $pub_date ) {
		return $this->derive_expires_at( $expiry_date, $pub_date );
	}
}

class RemoteOkRssSourceTest extends TestCase {
	private $source;

	protected function setUp(): void {
		$this->source = new TestableRemoteOkRssSource(
			array(
				'key'      => 'remoteok',
				'label'    => 'RemoteOK',
				'url'      => 'https://example.test/remoteok.xml',
				'defaults' => array(
					'location'         => 'Worldwide',
					'company_name'     => '',
					'employment_types' => array( 'Full Time' ),
					'remote_position'  => true,
				),
			),
			new Logger()
		);
	}

	public function test_filters_blocked_title_and_tag_tokens() {
		$title_blocked = new FakeRssItem(
			array(
				'title'       => 'Senior Backend Engineer',
				'tags'        => 'php, remote',
				'guid'        => 'ro-1',
				'link'        => 'https://example.test/ro-1',
				'location'    => null,
				'description' => 'desc',
			)
		);
		$tag_blocked   = new FakeRssItem(
			array(
				'title'       => 'Backend Engineer',
				'tags'        => 'management, php',
				'guid'        => 'ro-2',
				'link'        => 'https://example.test/ro-2',
				'location'    => null,
				'description' => 'desc',
			)
		);

		$this->assertNull( $this->source->map_item_to_job_for_test( $title_blocked ) );
		$this->assertNull( $this->source->map_item_to_job_for_test( $tag_blocked ) );
	}

	public function test_location_rule_allows_null_and_remote_only() {
		$this->assertTrue( $this->source->is_allowed_location_for_test( '' ) );
		$this->assertTrue( $this->source->is_allowed_location_for_test( 'REMOTE' ) );
		$this->assertFalse( $this->source->is_allowed_location_for_test( 'San Francisco' ) );
	}

	public function test_maps_guid_expiry_and_image_logo() {
		$item = new FakeRssItem(
			array(
				'title'       => 'Backend Engineer',
				'company'     => 'Acme',
				'tags'        => 'php,remote',
				'location'    => null,
				'pubDate'     => 'Tue, 31 Mar 2026 08:24:37 +0000',
				'guid'        => 'remoteok-123',
				'link'        => 'https://example.test/remoteok-123',
				'image'       => array( 'url' => 'https://example.test/logo.png' ),
				'description' => 'Description',
			)
		);
		$job  = $this->source->map_item_to_job_for_test( $item );

		$this->assertSame( 'remoteok-123', $job->external_id );
		$this->assertSame( 'Worldwide', $job->location );
		$this->assertSame( 'https://example.test/logo.png', $job->company_logo_url );
		$this->assertTrue( $job->remote_position );
		$this->assertSame( gmdate( 'Y-m-d', strtotime( 'Tue, 31 Mar 2026 08:24:37 +0000' ) + ( 86400 * 31 ) ), $job->expires_at );
	}

	public function test_expires_at_prefers_expiry_date_when_present() {
		$expires_at = $this->source->derive_expires_at_for_test(
			'Thu, 30 Apr 2026 08:24:37 +0000',
			'Tue, 31 Mar 2026 08:24:37 +0000'
		);

		$this->assertSame( '2026-04-30', $expires_at );
	}
}
