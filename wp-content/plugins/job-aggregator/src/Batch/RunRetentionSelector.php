<?php

namespace JobAggregator\Batch;

/**
 * Selects terminal runs to archive and archived runs to permanently delete.
 */
class RunRetentionSelector {
	public function select_runs_to_archive( array $terminal_runs, $retention_days, $keep_min, $now_timestamp = 0 ) {
		$retention_days = max( 1, (int) $retention_days );
		$keep_min       = max( 0, (int) $keep_min );
		$now_timestamp  = $now_timestamp > 0 ? (int) $now_timestamp : time();
		$cutoff         = $now_timestamp - ( $retention_days * DAY_IN_SECONDS );

		$sorted_runs = $terminal_runs;
		usort(
			$sorted_runs,
			static function ( $left, $right ) {
				$left_id  = isset( $left['id'] ) ? (int) $left['id'] : 0;
				$right_id = isset( $right['id'] ) ? (int) $right['id'] : 0;

				return $right_id <=> $left_id;
			}
		);

		$protected_ids     = array();
		$sorted_runs_count = count( $sorted_runs );
		for ( $index = 0; $index < $keep_min && $index < $sorted_runs_count; $index++ ) {
			$protected_ids[] = isset( $sorted_runs[ $index ]['id'] ) ? (int) $sorted_runs[ $index ]['id'] : 0;
		}
		$protected_ids = array_values( array_filter( array_unique( $protected_ids ) ) );

		$to_archive = array();
		foreach ( $sorted_runs as $run ) {
			$run_id = isset( $run['id'] ) ? (int) $run['id'] : 0;
			if ( $run_id < 1 || in_array( $run_id, $protected_ids, true ) ) {
				continue;
			}

			$terminal_at = isset( $run['terminal_at'] ) ? strtotime( (string) $run['terminal_at'] ) : false;
			if ( false === $terminal_at || $terminal_at > $cutoff ) {
				continue;
			}

			$to_archive[] = $run_id;
		}

		return array_values( array_unique( $to_archive ) );
	}

	public function select_archived_runs_to_delete( array $archived_runs, $grace_days, $now_timestamp = 0 ) {
		$grace_days    = max( 1, (int) $grace_days );
		$now_timestamp = $now_timestamp > 0 ? (int) $now_timestamp : time();
		$cutoff        = $now_timestamp - ( $grace_days * DAY_IN_SECONDS );
		$to_delete     = array();

		foreach ( $archived_runs as $run ) {
			$run_id = isset( $run['id'] ) ? (int) $run['id'] : 0;
			if ( $run_id < 1 ) {
				continue;
			}

			$archived_at = isset( $run['archived_at'] ) ? strtotime( (string) $run['archived_at'] ) : false;
			if ( false === $archived_at || $archived_at > $cutoff ) {
				continue;
			}

			$to_delete[] = $run_id;
		}

		return array_values( array_unique( $to_delete ) );
	}
}
