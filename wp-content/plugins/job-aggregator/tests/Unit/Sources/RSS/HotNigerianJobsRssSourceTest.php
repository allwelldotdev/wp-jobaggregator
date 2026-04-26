<?php

namespace JobAggregator\Tests\Unit\Sources\RSS;

use JobAggregator\Sources\RSS\HotNigerianJobsRssSource;
use JobAggregator\Support\Logger;
use JobAggregator\Tests\Support\FakeRssItem;
use PHPUnit\Framework\TestCase;

class TestableHotNigerianJobsRssSource extends HotNigerianJobsRssSource {
	public function map_item_to_job_for_test( $item ) {
		return $this->map_item_to_job( $item );
	}

	public function derive_expires_at_for_test( $expiry_date, $pub_date ) {
		return $this->derive_expires_at( $expiry_date, $pub_date );
	}

	public function contains_any_whole_word_for_test( $value, array $tokens ) {
		return $this->contains_any_whole_word( $value, $tokens );
	}

	public function parse_location_for_test( $description ) {
		return $this->parse_location( $description );
	}
}

class HotNigerianJobsRssSourceTest extends TestCase {
	private $source;

	protected function setUp(): void {
		$this->source = new TestableHotNigerianJobsRssSource(
			array(
				'key'      => 'hotnigerianjobs',
				'label'    => 'Hot Nigerian Jobs',
				'url'      => 'https://example.test/hotnigerianjobs.xml',
				'defaults' => array(
					'location'         => 'Nigeria',
					'company_name'     => '',
					'employment_types' => array( 'Full Time' ),
					'remote_position'  => false,
				),
			),
			new Logger()
		);
	}

	public function test_maps_valid_item_payload() {
		$item = $this->valid_item();
		$job  = $this->source->map_item_to_job_for_test( $item );

		$this->assertSame( 'https://example.test/hotjobs/1', $job->external_id );
		$this->assertSame( 'Nutritionist / Quality Control (Offshore)', $job->title );
		$this->assertSame( 'Castel Resources Consultancy Limited', $job->company_name );
		$this->assertSame( 'Abia', $job->location );
		$this->assertSame( 'https://example.test/hotjobs/1', $job->source_url );
		$this->assertSame( 'https://example.test/hotjobs/1', $job->application_url );
		$this->assertSame( 'Role description. The position is located in Abia State. Apply today.', $job->description );
		$this->assertSame( array( 'Full Time' ), $job->employment_types );
		$this->assertFalse( $job->remote_position );
		$this->assertSame( gmdate( 'c', strtotime( 'Thu, 09 Apr 2026 18:34:29 +0100' ) ), $job->published_at );
		$this->assertSame( gmdate( 'Y-m-d', strtotime( 'Thu, 09 Apr 2026 18:34:29 +0100' ) + ( 86400 * 31 ) ), $job->expires_at );
	}

	public function test_expires_at_prefers_payload_expiry_field() {
		$expires_at = $this->source->derive_expires_at_for_test(
			'Thu, 30 Apr 2026 08:24:37 +0000',
			'Thu, 09 Apr 2026 18:34:29 +0100'
		);

		$this->assertSame( '2026-04-30', $expires_at );
	}

	public function test_skips_multi_position_roundup_titles() {
		$item = $this->valid_item( array( 'title' => 'Ibadan Business School Limited Job Recruitment (5 Positions)' ) );

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}

	public function test_skips_blocked_whole_word_title_tokens() {
		$item = $this->valid_item( array( 'title' => 'Senior Backend Engineer at Example Limited' ) );

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}

	public function test_blocked_title_tokens_do_not_match_inside_words() {
		$this->assertFalse( $this->source->contains_any_whole_word_for_test( 'Leadership Trainer at Example Limited', array( 'lead' ) ) );
	}

	public function test_skips_descriptions_without_allowed_location() {
		$item = $this->valid_item(
			array(
				'description' => 'Role description. The position is located in Lagos State. Apply today.',
			)
		);

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}

	public function test_skips_when_formal_location_parser_fails() {
		$item = $this->valid_item(
			array(
				'description' => 'Role description mentioning Abia State without the expected parser sentence.',
			)
		);

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}

	public function test_skips_title_without_standalone_at_separator() {
		$item = $this->valid_item( array( 'title' => 'Nutritionist / Quality Control for Castel Resources Consultancy Limited' ) );

		$this->assertNull( $this->source->map_item_to_job_for_test( $item ) );
	}

	public function test_parses_multi_word_state_location() {
		$description = 'Example Limited is recruiting to fill the position. The position is located in Uyo, Akwa Ibom State. Apply today.';

		$this->assertSame( 'Uyo, Akwa Ibom', $this->source->parse_location_for_test( $description ) );
	}

	private function valid_item( array $overrides = array() ) {
		$fields = array_merge(
			array(
				'title'       => 'Nutritionist / Quality Control (Offshore) at Castel Resources Consultancy Limited',
				'link'        => 'https://example.test/hotjobs/1',
				'guid'        => 'https://example.test/hotjobs/1',
				'category'    => array( null, null ),
				'description' => 'Role description. The position is located in Abia State. Apply today.',
				'pubDate'     => 'Thu, 09 Apr 2026 18:34:29 +0100',
			),
			$overrides
		);

		return new FakeRssItem( $fields );
	}
}
