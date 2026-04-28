<?php

namespace JobAggregator\Tests\Unit\Support;

use JobAggregator\Cron\Scheduler;
use JobAggregator\Support\Settings;
use JobAggregator\Tests\Support\UnitWpState;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	protected function setUp(): void {
		UnitWpState::reset();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		UnitWpState::$schedules = array(
			Scheduler::EVERY_TWO_HOURS => array(
				'interval' => 2 * HOUR_IN_SECONDS,
				'display'  => 'Every 2 Hours',
			),
			Scheduler::EVERY_EIGHT_HOURS => array(
				'interval' => 8 * HOUR_IN_SECONDS,
				'display'  => 'Every 8 Hours',
			),
		);
	}

	public function test_defaults_are_opt_in_with_two_hour_recurrence() {
		$defaults = Settings::defaults();

		$this->assertSame( 0, $defaults['enable_recurring'] );
		$this->assertSame( Scheduler::EVERY_TWO_HOURS, $defaults['recurrence'] );
		$this->assertSame( 0, $defaults['delete_expired_job_listings'] );
		$this->assertSame( 62, $defaults['run_retention_days'] );
		$this->assertSame( 750, $defaults['run_keep_min'] );
		$this->assertSame( array(), $defaults['source_states'] );
	}

	public function test_sanitize_accepts_source_state_toggles_and_two_hour_recurrence() {
		$sanitized = Settings::sanitize(
			array(
				'enable_recurring' => 1,
				'recurrence'       => Scheduler::EVERY_TWO_HOURS,
				'process_delay'    => 15,
				'runs_per_page'    => 30,
				'delete_expired_job_listings' => 1,
				'run_retention_days' => 90,
				'run_keep_min' => 1200,
				'source_states'    => array(
					'myjobmag' => '1',
					'remoteok' => 0,
				),
			)
		);

		$this->assertSame( 1, $sanitized['enable_recurring'] );
		$this->assertSame( Scheduler::EVERY_TWO_HOURS, $sanitized['recurrence'] );
		$this->assertSame( 15, $sanitized['process_delay'] );
		$this->assertSame( 30, $sanitized['runs_per_page'] );
		$this->assertSame( 1, $sanitized['delete_expired_job_listings'] );
		$this->assertSame( 90, $sanitized['run_retention_days'] );
		$this->assertSame( 1200, $sanitized['run_keep_min'] );
		$this->assertSame(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			),
			$sanitized['source_states']
		);
	}

	public function test_initialize_for_activation_uses_all_sources_disabled_for_fresh_install() {
		Settings::initialize_for_activation(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			)
		);

		$settings = Settings::all();

		$this->assertSame( 0, $settings['enable_recurring'] );
		$this->assertSame( Scheduler::EVERY_TWO_HOURS, $settings['recurrence'] );
		$this->assertSame( 0, $settings['delete_expired_job_listings'] );
		$this->assertSame( 62, $settings['run_retention_days'] );
		$this->assertSame( 750, $settings['run_keep_min'] );
		$this->assertSame(
			array(
				'myjobmag' => 0,
				'remoteok' => 0,
			),
			$settings['source_states']
		);
	}

	public function test_initialize_for_activation_seeds_existing_site_from_config_enabled_values() {
		UnitWpState::$options[ Settings::OPTION_KEY ] = array(
			'enable_recurring' => 1,
			'recurrence'       => Scheduler::EVERY_EIGHT_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
		);

		Settings::initialize_for_activation(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			)
		);

		$settings = Settings::all();

		$this->assertSame(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			),
			$settings['source_states']
		);
	}

	public function test_ensure_source_states_adds_new_sources_as_disabled() {
		UnitWpState::$options[ Settings::OPTION_KEY ] = array(
			'enable_recurring' => 1,
			'recurrence'       => Scheduler::EVERY_EIGHT_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
			'source_states'    => array(
				'myjobmag' => 1,
			),
		);

		Settings::ensure_source_states(
			array(
				'myjobmag' => 1,
				'remoteok' => 1,
			)
		);

		$settings = Settings::all();
		$this->assertSame(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			),
			$settings['source_states']
		);
	}

	public function test_ensure_source_states_forces_catalog_disabled_sources_off() {
		UnitWpState::$options[ Settings::OPTION_KEY ] = array(
			'enable_recurring' => 1,
			'recurrence'       => Scheduler::EVERY_EIGHT_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
			'source_states'    => array(
				'myjobmag' => 1,
				'remoteok' => 1,
			),
		);

		Settings::ensure_source_states(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			)
		);

		$settings = Settings::all();
		$this->assertSame(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			),
			$settings['source_states']
		);
	}

	public function test_enforce_configured_source_states_rejects_catalog_disabled_override() {
		$settings = Settings::enforce_configured_source_states(
			array(
				'source_states' => array(
					'myjobmag' => 1,
					'remoteok' => 1,
				),
			),
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			)
		);

		$this->assertSame(
			array(
				'myjobmag' => 1,
				'remoteok' => 0,
			),
			$settings['source_states']
		);
	}

	public function test_sanitize_clamps_retention_settings() {
		$sanitized = Settings::sanitize(
			array(
				'run_retention_days' => -20,
				'run_keep_min'       => -5,
			)
		);

		$this->assertSame( 1, $sanitized['run_retention_days'] );
		$this->assertSame( 0, $sanitized['run_keep_min'] );
	}
}
