<?php

namespace JobAggregator\Admin;

use JobAggregator\Admin\Pages\DashboardPage;
use JobAggregator\Admin\Pages\MonitoringPage;
use JobAggregator\Admin\Pages\RunsPage;
use JobAggregator\Admin\Support\AdminView;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Cron\Scheduler;
use JobAggregator\Plugin;
use JobAggregator\SourceRegistry;
use JobAggregator\Support\Settings;

class AdminPages {
	const MENU_SLUG       = 'job-aggregator';
	const RUNS_SLUG       = 'job-aggregator-runs';
	const MONITORING_SLUG = 'job-aggregator-monitoring';
	const SETTINGS_SLUG   = 'job-aggregator-settings';
	const ACTION_START    = 'job_aggregator_start_manual_import';

	private $settings_registrar;
	private $manual_run_controller;
	private $dashboard_page;
	private $runs_page;
	private $monitoring_page;

	public function __construct(
		Plugin $plugin,
		BatchRunManager $run_manager,
		CheckpointStore $checkpoint_store,
		SourceRegistry $source_registry,
		Scheduler $scheduler
	) {
		$view = new AdminView( self::RUNS_SLUG, self::ACTION_START );

		$this->settings_registrar    = new SettingsRegistrar( self::SETTINGS_SLUG, $scheduler, $source_registry );
		$this->manual_run_controller = new ManualRunController( $plugin, self::RUNS_SLUG, self::ACTION_START );
		$this->dashboard_page        = new DashboardPage( $run_manager, $source_registry, $view, self::RUNS_SLUG );
		$this->runs_page             = new RunsPage( $run_manager, $checkpoint_store, $view, self::RUNS_SLUG );
		$this->monitoring_page       = new MonitoringPage( $run_manager, $checkpoint_store, $view );
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings_registrar, 'register' ) );
		add_action( 'admin_post_' . self::ACTION_START, array( $this->manual_run_controller, 'handle_manual_start' ) );
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this->settings_registrar, 'handle_settings_updated' ), 10, 2 );
		add_action( 'admin_notices', array( $this->manual_run_controller, 'render_notice' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Job Aggregator', 'job-aggregator' ),
			__( 'Job Aggregator', 'job-aggregator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' ),
			'dashicons-rss',
			31
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'job-aggregator' ),
			__( 'Dashboard', 'job-aggregator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Runs', 'job-aggregator' ),
			__( 'Runs', 'job-aggregator' ),
			'manage_options',
			self::RUNS_SLUG,
			array( $this->runs_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Monitoring', 'job-aggregator' ),
			__( 'Monitoring', 'job-aggregator' ),
			'manage_options',
			self::MONITORING_SLUG,
			array( $this->monitoring_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'job-aggregator' ),
			__( 'Settings', 'job-aggregator' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this->settings_registrar, 'render_page' )
		);
	}
}
