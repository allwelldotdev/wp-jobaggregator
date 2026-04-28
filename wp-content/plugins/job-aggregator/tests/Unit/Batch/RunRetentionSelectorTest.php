<?php

namespace JobAggregator\Tests\Unit\Batch;

use JobAggregator\Batch\RunRetentionSelector;
use PHPUnit\Framework\TestCase;

class RunRetentionSelectorTest extends TestCase {
	protected function setUp(): void {
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	public function test_select_runs_to_archive_respects_age_cutoff_and_keep_minimum() {
		$selector = new RunRetentionSelector();
		$now      = strtotime( '2026-04-28 00:00:00 UTC' );

		$to_archive = $selector->select_runs_to_archive(
			array(
				array( 'id' => 10, 'terminal_at' => '2025-12-01 00:00:00' ),
				array( 'id' => 9, 'terminal_at' => '2025-12-05 00:00:00' ),
				array( 'id' => 8, 'terminal_at' => '2025-12-10 00:00:00' ),
				array( 'id' => 7, 'terminal_at' => '2026-04-20 00:00:00' ),
			),
			62,
			2,
			$now
		);

		$this->assertSame( array( 8 ), $to_archive );
	}

	public function test_select_archived_runs_to_delete_uses_archive_grace_window() {
		$selector = new RunRetentionSelector();
		$now      = strtotime( '2026-04-28 00:00:00 UTC' );

		$to_delete = $selector->select_archived_runs_to_delete(
			array(
				array( 'id' => 21, 'archived_at' => '2026-03-01 00:00:00' ),
				array( 'id' => 22, 'archived_at' => '2026-04-15 00:00:00' ),
			),
			30,
			$now
		);

		$this->assertSame( array( 21 ), $to_delete );
	}
}
