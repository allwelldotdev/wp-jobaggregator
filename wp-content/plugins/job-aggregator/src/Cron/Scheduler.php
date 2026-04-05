<?php

namespace JobAggregator\Cron;

class Scheduler {
	const START_HOOK   = 'job_aggregator_start_batch';
	const PROCESS_HOOK = 'job_aggregator_process_batch';

	public function register_callbacks( $plugin ) {
		add_action( self::START_HOOK, array( $plugin, 'start_batch' ) );
		add_action( self::PROCESS_HOOK, array( $plugin, 'process_batch' ), 10, 1 );
	}

	public function schedule_recurring_start() {
		if ( ! wp_next_scheduled( self::START_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::START_HOOK );
		}
	}

	public function schedule_process_event( $run_id, $timestamp = 0 ) {
		$run_id    = (int) $run_id;
		$timestamp = $timestamp > 0 ? (int) $timestamp : time() + 5;
		$args      = array( $run_id );

		if ( ! wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
			wp_schedule_single_event( $timestamp, self::PROCESS_HOOK, $args );
		}
	}

	public function clear_all_events() {
		wp_clear_scheduled_hook( self::START_HOOK );
		wp_clear_scheduled_hook( self::PROCESS_HOOK );
	}
}
