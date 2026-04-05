<?php

namespace JobAggregator\Cron;

use JobAggregator\Support\Settings;

class Scheduler {
	const START_HOOK        = 'job_aggregator_start_batch';
	const PROCESS_HOOK      = 'job_aggregator_process_batch';
	const EVERY_EIGHT_HOURS = 'job_aggregator_every_8_hours';

	public function register_callbacks( $plugin ) {
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
		add_action( self::START_HOOK, array( $plugin, 'start_batch' ) );
		add_action( self::PROCESS_HOOK, array( $plugin, 'process_batch' ), 10, 1 );
	}

	public function schedule_recurring_start( $force_reschedule = false ) {
		if ( false === has_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
		}

		$settings = Settings::all();
		$event    = wp_get_scheduled_event( self::START_HOOK );

		if ( empty( $settings['enable_recurring'] ) ) {
			wp_clear_scheduled_hook( self::START_HOOK );

			return;
		}

		$recurrence    = $this->normalize_recurrence( $settings['recurrence'] );
		$same_schedule =
			$event &&
			! empty( $event->schedule ) &&
			$event->schedule === $recurrence;

		if ( $same_schedule && ! $force_reschedule ) {
			return;
		}

		wp_clear_scheduled_hook( self::START_HOOK );
		wp_schedule_event( time() + 300, $recurrence, self::START_HOOK );
	}

	public function schedule_process_event( $run_id, $timestamp = 0 ) {
		$run_id    = (int) $run_id;
		$delay     = max( 1, (int) Settings::all()['process_delay'] );
		$timestamp = $timestamp > 0 ? (int) $timestamp : time() + $delay;
		$args      = array( $run_id );

		if ( ! wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
			wp_schedule_single_event( $timestamp, self::PROCESS_HOOK, $args );
		}
	}

	public function clear_all_events() {
		wp_clear_scheduled_hook( self::START_HOOK );
		wp_clear_scheduled_hook( self::PROCESS_HOOK );
	}

	public function add_custom_schedules( $schedules ) {
		$schedules[ self::EVERY_EIGHT_HOURS ] = array(
			'interval' => 8 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 8 Hours', 'job-aggregator' ),
		);

		return $schedules;
	}

	private function normalize_recurrence( $recurrence ) {
		$recurrence = sanitize_key( (string) $recurrence );
		$schedules  = wp_get_schedules();

		if ( empty( $recurrence ) || ! isset( $schedules[ $recurrence ] ) ) {
			return self::EVERY_EIGHT_HOURS;
		}

		return $recurrence;
	}
}
