<?php

namespace JobAggregator\Tests\Unit\Jobs;

use JobAggregator\Jobs\DuplicateChecker;
use JobAggregator\Jobs\JobData;
use PHPUnit\Framework\TestCase;

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
}
