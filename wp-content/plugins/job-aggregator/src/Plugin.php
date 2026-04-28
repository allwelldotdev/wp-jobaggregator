<?php

namespace JobAggregator;

use JobAggregator\Admin\AdminPages;
use JobAggregator\Batch\BatchProcessor;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Batch\RunLock;
use JobAggregator\Cron\Scheduler;
use JobAggregator\Jobs\DuplicateChecker;
use JobAggregator\Jobs\ListingOriginStore;
use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Jobs\PostWriter;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;
use JobAggregator\Support\Settings;

/**
 * Boots the plugin and wires ingestion, scheduling, persistence, and admin modules together.
 */
class Plugin {
	private Logger $logger;
	private Scheduler $scheduler;
	private BatchRunManager $run_manager;
	private CheckpointStore $checkpoint_store;
	private BatchProcessor $batch_processor;
	private SourceRegistry $source_registry;
	private AdminPages $admin_pages;
	private NormalizationSignalStore $normalization_signals;

	public function __construct() {
		$this->logger                = new Logger();
		$http                        = new HttpClient();
		$this->scheduler             = new Scheduler();
		$this->run_manager           = new BatchRunManager();
		$this->checkpoint_store      = new CheckpointStore();
		$this->normalization_signals = new NormalizationSignalStore();
		$config_path                 = apply_filters(
			'job_aggregator_sources_config_path',
			JOB_AGGREGATOR_PATH . 'config/sources.php'
		);
		$this->source_registry       = new SourceRegistry(
			config_path: (string) $config_path,
			logger: $this->logger,
			http: $http,
			normalization_signals: $this->normalization_signals
		);
		Settings::ensure_source_states( $this->source_registry->configured_source_states() );

		$this->batch_processor = new BatchProcessor(
			run_manager: $this->run_manager,
			checkpoint_store: $this->checkpoint_store,
			registry: $this->source_registry,
			post_writer: new PostWriter(
				new DuplicateChecker(
					new ListingOriginStore(),
					$this->source_registry->runtime_dedup_groups()
				),
				$this->logger
			),
			scheduler: $this->scheduler,
			logger: $this->logger,
			run_lock: new RunLock()
		);
		$this->admin_pages     = new AdminPages(
			plugin: $this,
			run_manager: $this->run_manager,
			checkpoint_store: $this->checkpoint_store,
			normalization_signals: $this->normalization_signals,
			source_registry: $this->source_registry,
			scheduler: $this->scheduler
		);
	}

	public function boot() {
		$this->scheduler->register_callbacks( $this );
		$this->scheduler->schedule_cleanup_history();
		add_filter( 'job_manager_delete_expired_jobs', array( $this, 'should_delete_expired_job_listings' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );

		if ( is_admin() ) {
			$this->admin_pages->register();
		}
	}

	public static function activate() {
		$run_manager = new BatchRunManager();
		$run_manager->install_schema();

		$logger                = new Logger();
		$http                  = new HttpClient();
		$normalization_signals = new NormalizationSignalStore();
		$config_path           = apply_filters(
			'job_aggregator_sources_config_path',
			JOB_AGGREGATOR_PATH . 'config/sources.php'
		);
		$source_registry       = new SourceRegistry(
			config_path: (string) $config_path,
			logger: $logger,
			http: $http,
			normalization_signals: $normalization_signals
		);
		Settings::initialize_for_activation( $source_registry->configured_source_states() );

		$scheduler = new Scheduler();
		$scheduler->schedule_recurring_start();
		$scheduler->schedule_cleanup_history();
	}

	public static function deactivate() {
		$scheduler = new Scheduler();
		$scheduler->clear_all_events();
	}

	public function start_batch() {
		$this->queue_batch_run( 'cron' );
	}

	public function cleanup_history() {
		$this->run_manager->install_schema();
		$cleanup_result = $this->run_manager->cleanup_history( Settings::all() );
		( new ListingOriginStore() )->purge_orphaned_rows();
		$archived_count = isset( $cleanup_result['archived'] ) ? (int) $cleanup_result['archived'] : 0;
		$deleted_count  = isset( $cleanup_result['deleted'] ) ? (int) $cleanup_result['deleted'] : 0;

		if ( $archived_count > 0 || $deleted_count > 0 ) {
			$this->logger->info(
				'Completed run history retention cleanup.',
				array(
					'archived_count' => $archived_count,
					'deleted_count'  => $deleted_count,
				)
			);
		}
	}

	public function trigger_manual_batch() {
		return $this->queue_batch_run( 'manual' );
	}

	private function queue_batch_run( $triggered_by = 'cron' ) {
		if ( ! $this->dependencies_available() ) {
			$this->logger->error( 'WP Job Manager is required before imports can run.' );

			return array(
				'status'  => 'dependency_missing',
				'run_id'  => 0,
				'message' => 'WP Job Manager is required before imports can run.',
			);
		}

		$this->run_manager->install_schema();

		$active_run = $this->run_manager->get_active_run();
		if ( ! empty( $active_run['id'] ) ) {
			$this->scheduler->schedule_process_event( (int) $active_run['id'] );

			return array(
				'status'  => 'active_run',
				'run_id'  => (int) $active_run['id'],
				'message' => 'An active import run already exists.',
			);
		}

		$sources = $this->source_registry->all();
		if ( empty( $sources ) ) {
			$this->logger->info( 'No runtime-enabled sources are available for batch import.' );

			return array(
				'status'  => 'no_sources',
				'run_id'  => 0,
				'message' => 'No runtime-enabled sources are available for batch import.',
			);
		}

		$run = $this->run_manager->start_run( $sources, $triggered_by );
		if ( empty( $run['id'] ) ) {
			$this->logger->error( 'Failed to create import batch run record.' );

			return array(
				'status'  => 'failed',
				'run_id'  => 0,
				'message' => 'Failed to create import batch run record.',
			);
		}

		$this->logger->info(
			'Started import batch run.',
			array(
				'run_id'       => (int) $run['id'],
				'source_count' => count( $sources ),
				'triggered_by' => (string) $triggered_by,
			)
		);
		$this->scheduler->schedule_process_event( (int) $run['id'] );

		return array(
			'status'  => 'started',
			'run_id'  => (int) $run['id'],
			'message' => 'Import run queued successfully.',
		);
	}

	public function process_batch( $run_id = 0 ) {
		if ( ! $this->dependencies_available() ) {
			return;
		}

		$target_run_id = (int) $run_id;
		if ( $target_run_id < 1 ) {
			$active_run = $this->run_manager->get_active_run();
			if ( empty( $active_run['id'] ) ) {
				return;
			}

			$target_run_id = (int) $active_run['id'];
		}

		$this->batch_processor->process( $target_run_id );
	}

	public function render_dependency_notice() {
		if ( $this->dependencies_available() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>Job Aggregator requires WP Job Manager and the <code>job_listing</code> post type.</p></div>';
	}

	public function should_delete_expired_job_listings( $delete_expired_jobs ) {
		unset( $delete_expired_jobs );
		$settings = Settings::all();

		return ! empty( $settings['delete_expired_job_listings'] );
	}

	private function dependencies_available() {
		return class_exists( 'WP_Job_Manager' ) || post_type_exists( 'job_listing' );
	}
}
