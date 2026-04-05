<?php

namespace JobAggregator\Support;

use JobAggregator\Cron\Scheduler;

class Settings {
	const OPTION_KEY = 'job_aggregator_settings';

	public static function defaults() {
		return array(
			'enable_recurring' => 1,
			'recurrence'       => Scheduler::EVERY_EIGHT_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		$settings['enable_recurring'] = empty( $settings['enable_recurring'] ) ? 0 : 1;
		$settings['process_delay']    = max( 5, min( 300, (int) $settings['process_delay'] ) );
		$settings['runs_per_page']    = max( 5, min( 100, (int) $settings['runs_per_page'] ) );

		$schedules = wp_get_schedules();
		if ( empty( $settings['recurrence'] ) || ! isset( $schedules[ $settings['recurrence'] ] ) ) {
			$settings['recurrence'] = Scheduler::EVERY_EIGHT_HOURS;
		}

		return $settings;
	}

	public static function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$defaults  = self::defaults();
		$schedules = wp_get_schedules();

		$recurrence = isset( $input['recurrence'] ) ? sanitize_key( $input['recurrence'] ) : $defaults['recurrence'];
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$recurrence = $defaults['recurrence'];
		}

		return array(
			'enable_recurring' => empty( $input['enable_recurring'] ) ? 0 : 1,
			'recurrence'       => $recurrence,
			'process_delay'    => max( 5, min( 300, isset( $input['process_delay'] ) ? (int) $input['process_delay'] : (int) $defaults['process_delay'] ) ),
			'runs_per_page'    => max( 5, min( 100, isset( $input['runs_per_page'] ) ? (int) $input['runs_per_page'] : (int) $defaults['runs_per_page'] ) ),
		);
	}
}
