<?php

namespace JobAggregator\Tests\Unit;

use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Sources\RSS\RemoteOkRssSource;
use JobAggregator\Sources\RSS\WeWorkRemotelyRssSource;
use JobAggregator\Support\Logger;
use PHPUnit\Framework\TestCase;

class MemoryNormalizationSignalStoreForRemoteSources extends NormalizationSignalStore {
	public $records = array();

	public function __construct() {}

	public function record( $source_key, $signal_type, $raw_value, $normalized_value = '', $example_external_id = '', $example_title = '' ) {
		$this->records[] = array(
			'source_key'          => (string) $source_key,
			'signal_type'         => (string) $signal_type,
			'raw_value'           => (string) $raw_value,
			'normalized_value'    => (string) $normalized_value,
			'example_external_id' => (string) $example_external_id,
			'example_title'       => (string) $example_title,
		);
	}
}

class FakeRssItem {
	private $fields;
	private $namespaced_fields;

	public function __construct( array $fields = array(), array $namespaced_fields = array() ) {
		$this->fields           = $fields;
		$this->namespaced_fields = $namespaced_fields;
	}

	public function get_item_tags( $namespace, $tag ) {
		$namespace = (string) $namespace;
		$tag       = (string) $tag;

		if ( '' === $namespace ) {
			if ( ! array_key_exists( $tag, $this->fields ) ) {
				return array();
			}

			return $this->to_nodes( $this->fields[ $tag ] );
		}

		$key = $namespace . '|' . $tag;
		if ( ! array_key_exists( $key, $this->namespaced_fields ) ) {
			return array();
		}

		return $this->to_nodes( $this->namespaced_fields[ $key ] );
	}

	public function get_title() {
		return isset( $this->fields['title'] ) ? (string) $this->fields['title'] : '';
	}

	public function get_content() {
		return isset( $this->fields['description'] ) ? (string) $this->fields['description'] : '';
	}

	public function get_description() {
		return isset( $this->fields['description'] ) ? (string) $this->fields['description'] : '';
	}

	public function get_id() {
		return isset( $this->fields['guid'] ) ? (string) $this->fields['guid'] : '';
	}

	public function get_link() {
		return isset( $this->fields['link'] ) ? (string) $this->fields['link'] : '';
	}

	private function to_nodes( $value ) {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) && isset( $value['__nodes'] ) && is_array( $value['__nodes'] ) ) {
			return $value['__nodes'];
		}

		if ( is_array( $value ) ) {
			return array( $value );
		}

		return array(
			array(
				'data' => (string) $value,
			),
		);
	}
}

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

class RemoteSourceRssSourceTest extends TestCase {
	private $remote_ok;
	private $weworkremotely;
	private $signals;

	protected function setUp(): void {
		$this->signals         = new MemoryNormalizationSignalStoreForRemoteSources();
		$this->remote_ok       = new TestableRemoteOkRssSource(
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
		$this->weworkremotely = new TestableWeWorkRemotelyRssSource(
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

	public function test_remoteok_filters_blocked_title_and_tag_tokens() {
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

		$this->assertNull( $this->remote_ok->map_item_to_job_for_test( $title_blocked ) );
		$this->assertNull( $this->remote_ok->map_item_to_job_for_test( $tag_blocked ) );
	}

	public function test_remoteok_location_rule_allows_null_and_remote_only() {
		$this->assertTrue( $this->remote_ok->is_allowed_location_for_test( '' ) );
		$this->assertTrue( $this->remote_ok->is_allowed_location_for_test( 'REMOTE' ) );
		$this->assertFalse( $this->remote_ok->is_allowed_location_for_test( 'San Francisco' ) );
	}

	public function test_remoteok_maps_guid_expiry_and_image_logo() {
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
		$job  = $this->remote_ok->map_item_to_job_for_test( $item );

		$this->assertSame( 'remoteok-123', $job->external_id );
		$this->assertSame( 'Worldwide', $job->location );
		$this->assertSame( 'https://example.test/logo.png', $job->company_logo_url );
		$this->assertTrue( $job->remote_position );
		$this->assertSame( gmdate( 'Y-m-d', strtotime( 'Tue, 31 Mar 2026 08:24:37 +0000' ) + ( 86400 * 31 ) ), $job->expires_at );
	}

	public function test_remoteok_expires_at_prefers_expiry_date_when_present() {
		$expires_at = $this->remote_ok->derive_expires_at_for_test(
			'Thu, 30 Apr 2026 08:24:37 +0000',
			'Tue, 31 Mar 2026 08:24:37 +0000'
		);

		$this->assertSame( '2026-04-30', $expires_at );
	}

	public function test_weworkremotely_title_split_and_no_colon_fallback() {
		$split = $this->weworkremotely->split_title_and_company_for_test( 'Teamflect: Growth Marketing Lead' );
		$plain = $this->weworkremotely->split_title_and_company_for_test( 'Backend Engineer' );

		$this->assertSame( 'Teamflect', $split['company_name'] );
		$this->assertSame( 'Growth Marketing Lead', $split['title'] );
		$this->assertSame( '', $plain['company_name'] );
		$this->assertSame( 'Backend Engineer', $plain['title'] );
	}

	public function test_weworkremotely_maps_guid_state_location_type_and_logo() {
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
		$job  = $this->weworkremotely->map_item_to_job_for_test( $item );

		$this->assertSame( 'https://example.test/wwr-123', $job->external_id );
		$this->assertSame( 'Acme', $job->company_name );
		$this->assertSame( 'Backend Engineer', $job->title );
		$this->assertSame( 'Anywhere in the World, California', $job->location );
		$this->assertSame( array( 'Full Time' ), $job->employment_types );
		$this->assertSame( 'https://example.test/wwr-logo.gif', $job->company_logo_url );
		$this->assertSame( '2026-04-30', $job->expires_at );
	}

	public function test_weworkremotely_employment_type_fallback_records_signal() {
		$employment_types = $this->weworkremotely->map_employment_types_for_test( 'Permanent', 'wwr-2', 'Backend Engineer' );

		$this->assertSame( array( 'Full Time' ), $employment_types );
		$this->assertCount( 1, $this->signals->records );
		$this->assertSame( 'employment_type_unmatched', $this->signals->records[0]['signal_type'] );
		$this->assertSame( 'Permanent', $this->signals->records[0]['raw_value'] );
	}

	public function test_weworkremotely_expiry_falls_back_to_pubdate_plus_31_days() {
		$expires_at = $this->weworkremotely->derive_expires_at_for_test( '', 'Tue, 31 Mar 2026 08:24:37 +0000' );

		$this->assertSame( gmdate( 'Y-m-d', strtotime( 'Tue, 31 Mar 2026 08:24:37 +0000' ) + ( 86400 * 31 ) ), $expires_at );
	}

	public function test_weworkremotely_filters_blocked_title_tokens() {
		$item = new FakeRssItem(
			array(
				'title'       => 'Acme: Senior Backend Engineer',
				'type'        => 'Full-Time',
				'guid'        => 'wwr-3',
				'link'        => 'https://example.test/wwr-3',
				'description' => 'Description',
			)
		);

		$this->assertNull( $this->weworkremotely->map_item_to_job_for_test( $item ) );
	}
}
