<?php

namespace JobAggregator\Support;

use JobAggregator\Cron\Scheduler;

/**
 * Provides defaults, retrieval, and sanitization for plugin runtime settings.
 */
class Settings {

	const OPTION_KEY                      = 'job_aggregator_settings';
	const LEGACY_DEFAULT_ENABLE_RECURRING = 1;
	const LEGACY_DEFAULT_RECURRENCE       = Scheduler::EVERY_EIGHT_HOURS;

	public static function defaults() {
		return array(
			'enable_recurring' => 0,
			'recurrence'       => Scheduler::EVERY_TWO_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
			'source_states'    => array(),
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		$settings['enable_recurring'] = empty( $settings['enable_recurring'] )
			? 0
			: 1;
		$settings['process_delay']    = max(
			5,
			min( 300, (int) $settings['process_delay'] ),
		);
		$settings['runs_per_page']    = max(
			5,
			min( 100, (int) $settings['runs_per_page'] ),
		);
		$settings['source_states']    = self::normalize_source_states(
			isset( $settings['source_states'] ) ? $settings['source_states'] : array()
		);

		$schedules = wp_get_schedules();
		if (
			empty( $settings['recurrence'] ) ||
			! isset( $schedules[ $settings['recurrence'] ] )
		) {
			$settings['recurrence'] = self::defaults()['recurrence'];
		}

		return $settings;
	}

	public static function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$defaults  = self::defaults();
		$schedules = wp_get_schedules();
		$stored    = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$recurrence = isset( $input['recurrence'] )
			? sanitize_key( $input['recurrence'] )
			: $defaults['recurrence'];
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$recurrence = $defaults['recurrence'];
		}

		$source_states = isset( $input['source_states'] ) && is_array( $input['source_states'] )
			? $input['source_states']
			: ( isset( $stored['source_states'] ) && is_array( $stored['source_states'] )
				? $stored['source_states']
				: array() );

		return array(
			'enable_recurring' => empty( $input['enable_recurring'] ) ? 0 : 1,
			'recurrence'       => $recurrence,
			'process_delay'    => max(
				5,
				min(
					300,
					isset( $input['process_delay'] )
						? (int) $input['process_delay']
						: (int) $defaults['process_delay'],
				),
			),
			'runs_per_page'    => max(
				5,
				min(
					100,
					isset( $input['runs_per_page'] )
						? (int) $input['runs_per_page']
						: (int) $defaults['runs_per_page'],
				),
			),
			'source_states'    => self::normalize_source_states( $source_states ),
		);
	}

	public static function initialize_for_activation( array $configured_source_states ) {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) ) {
			$settings                  = self::defaults();
			$settings['source_states'] = self::seed_source_states( $configured_source_states, false );

			update_option( self::OPTION_KEY, $settings );

			return;
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		if ( ! isset( $stored['source_states'] ) || ! is_array( $stored['source_states'] ) ) {
			$settings['source_states'] = self::seed_source_states( $configured_source_states, true );
		} else {
			$settings['source_states'] = self::merge_source_states(
				$stored['source_states'],
				$configured_source_states
			);
		}

		update_option( self::OPTION_KEY, self::sanitize( $settings ) );
	}

	public static function ensure_source_states( array $configured_source_states ) {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) ) {
			$settings                     = self::defaults();
			$settings['enable_recurring'] = self::LEGACY_DEFAULT_ENABLE_RECURRING;
			$settings['recurrence']       = self::LEGACY_DEFAULT_RECURRENCE;
			$settings['source_states']    = self::seed_source_states( $configured_source_states, true );

			update_option( self::OPTION_KEY, $settings );

			return;
		}

		if ( ! isset( $stored['source_states'] ) || ! is_array( $stored['source_states'] ) ) {
			$stored['source_states'] = self::seed_source_states( $configured_source_states, true );
			update_option( self::OPTION_KEY, self::sanitize( $stored ) );

			return;
		}

		$merged_source_states = self::merge_source_states(
			$stored['source_states'],
			$configured_source_states
		);
		$normalized_stored    = self::normalize_source_states( $stored['source_states'] );

		if ( $merged_source_states !== $normalized_stored ) {
			$stored['source_states'] = $merged_source_states;
			update_option( self::OPTION_KEY, self::sanitize( $stored ) );
		}
	}

	private static function normalize_source_states( $states ) {
		if ( ! is_array( $states ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $states as $source_key => $value ) {
			$key = sanitize_key( (string) $source_key );
			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = empty( $value ) ? 0 : 1;
		}

		return $normalized;
	}

	private static function seed_source_states( array $configured_source_states, $seed_from_config ) {
		$seeded = array();

		foreach ( $configured_source_states as $source_key => $config_enabled ) {
			$key = sanitize_key( (string) $source_key );
			if ( '' === $key ) {
				continue;
			}

			$seeded[ $key ] = $seed_from_config && ! empty( $config_enabled ) ? 1 : 0;
		}

		return $seeded;
	}

	private static function merge_source_states( $existing_states, array $configured_source_states ) {
		$merged = self::normalize_source_states( $existing_states );

		foreach ( $configured_source_states as $source_key => $config_enabled ) {
			unset( $config_enabled );

			$key = sanitize_key( (string) $source_key );
			if ( '' === $key ) {
				continue;
			}

			if ( ! array_key_exists( $key, $merged ) ) {
				$merged[ $key ] = 0;
			}
		}

		return $merged;
	}
}
