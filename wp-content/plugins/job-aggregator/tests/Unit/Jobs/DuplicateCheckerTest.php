<?php

namespace JobAggregator\Tests\Unit\Jobs;

use JobAggregator\Jobs\DuplicateChecker;
use JobAggregator\Jobs\JobData;
use JobAggregator\Jobs\ListingOriginStore;
use PHPUnit\Framework\TestCase;

class FakeListingOriginStore extends ListingOriginStore {
	public $find_calls = array();
	public $upsert_calls = array();
	public $next_post_id = 0;

	public function __construct() {}

	public function find_matching_post_id( $group_key, $title_norm, $company_norm, array $source_keys, $exclude_source_key = '' ) {
		$this->find_calls[] = array(
			'group_key'          => $group_key,
			'title_norm'         => $title_norm,
			'company_norm'       => $company_norm,
			'source_keys'        => $source_keys,
			'exclude_source_key' => $exclude_source_key,
		);

		return (int) $this->next_post_id;
	}

	public function upsert_origin( $post_id, $source_key, $group_key, $title_norm, $company_norm ) {
		$this->upsert_calls[] = array(
			'post_id'      => $post_id,
			'source_key'   => $source_key,
			'group_key'    => $group_key,
			'title_norm'   => $title_norm,
			'company_norm' => $company_norm,
		);
	}
}

class DuplicateCheckerTest extends TestCase {
	public function test_identity_key_prefers_external_id() {
		$job              = new JobData();
		$job->source_key  = 'myjobmag';
		$job->external_id = 'abc-123';
		$job->source_url  = 'https://example.test/jobs/123/';

		$checker = new DuplicateChecker();

		$this->assertSame(
			'myjobmag|external_id|abc-123',
			$checker->build_identity_key( $job )
		);
	}

	public function test_identity_key_falls_back_to_normalized_source_url() {
		$job             = new JobData();
		$job->source_key = 'remoteok';
		$job->source_url = 'HTTPS://Example.TEST/jobs/remoteok-001/';

		$checker = new DuplicateChecker();

		$this->assertSame(
			'remoteok|source_url|https://example.test/jobs/remoteok-001',
			$checker->build_identity_key( $job )
		);
	}

	public function test_identity_key_is_empty_without_external_id_or_source_url() {
		$job             = new JobData();
		$job->source_key = 'remoteok';

		$checker = new DuplicateChecker();

		$this->assertSame( '', $checker->build_identity_key( $job ) );
	}

	public function test_cross_source_duplicate_lookup_normalizes_title_and_company() {
		$store              = new FakeListingOriginStore();
		$store->next_post_id = 88;
		$checker            = new DuplicateChecker(
			$store,
			array(
				'nigeria' => array(
					'source_keys' => array( 'myjobmag', 'hotnigerianjobs' ),
				),
			)
		);
		$job                = new JobData();
		$job->source_key    = 'myjobmag';
		$job->title         = '  Nutritionist   / Quality Control (Offshore) ';
		$job->company_name  = ' CASTEL   RESOURCES   CONSULTANCY LIMITED ';

		$this->assertSame( 88, $checker->find_cross_source_duplicate_id( $job ) );
		$this->assertCount( 1, $store->find_calls );
		$this->assertSame( 'nigeria', $store->find_calls[0]['group_key'] );
		$this->assertSame( 'nutritionist / quality control (offshore)', $store->find_calls[0]['title_norm'] );
		$this->assertSame( 'castel resources consultancy limited', $store->find_calls[0]['company_norm'] );
		$this->assertSame( 'myjobmag', $store->find_calls[0]['exclude_source_key'] );
	}

	public function test_cross_source_duplicate_lookup_skips_sources_outside_configured_group() {
		$store           = new FakeListingOriginStore();
		$checker         = new DuplicateChecker(
			$store,
			array(
				'nigeria' => array(
					'source_keys' => array( 'myjobmag', 'hotnigerianjobs' ),
				),
			)
		);
		$job             = new JobData();
		$job->source_key = 'remoteok';
		$job->title      = 'Remote Engineer';
		$job->company_name = 'Acme';

		$this->assertSame( 0, $checker->find_cross_source_duplicate_id( $job ) );
		$this->assertCount( 0, $store->find_calls );
	}

	public function test_sync_cross_source_origin_persists_normalized_values() {
		$store             = new FakeListingOriginStore();
		$checker           = new DuplicateChecker(
			$store,
			array(
				'nigeria' => array(
					'source_keys' => array( 'myjobmag', 'hotnigerianjobs' ),
				),
			)
		);
		$job               = new JobData();
		$job->source_key   = 'hotnigerianjobs';
		$job->title        = 'Nutritionist / Quality Control (Offshore)';
		$job->company_name = 'Castel Resources Consultancy Limited';

		$checker->sync_cross_source_origin( $job, 42 );

		$this->assertCount( 1, $store->upsert_calls );
		$this->assertSame( 42, $store->upsert_calls[0]['post_id'] );
		$this->assertSame( 'hotnigerianjobs', $store->upsert_calls[0]['source_key'] );
		$this->assertSame( 'nigeria', $store->upsert_calls[0]['group_key'] );
	}
}
