<?php

namespace JobAggregator;

use JobAggregator\Admin\AdminPages;
use JobAggregator\Batch\BatchProcessor;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Batch\RunLock;
use JobAggregator\Cron\Scheduler;
use JobAggregator\Jobs\DuplicateChecker;
use JobAggregator\Jobs\PostWriter;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;

class Plugin {
	private $logger;
	private $scheduler;
	private $run_manager;
	private $checkpoint_store;
	private $batch_processor;
	private $source_registry;
	private $admin_pages;

	public function __construct() {
		$this->logger           = new Logger();
		$http                   = new HttpClient();
		$this->scheduler        = new Scheduler();
		$this->run_manager      = new BatchRunManager();
		$this->checkpoint_store = new CheckpointStore();
		$this->source_registry  = new SourceRegistry( JOB_AGGREGATOR_PATH . 'config/sources.php', $this->logger, $http );

		$this->batch_processor = new BatchProcessor(
			$this->run_manager,
			$this->checkpoint_store,
			$this->source_registry,
			new PostWriter( new DuplicateChecker(), $this->logger ),
			$this->scheduler,
			$this->logger,
			new RunLock()
		);
		$this->admin_pages     = new AdminPages(
			$this,
			$this->run_manager,
			$this->checkpoint_store,
			$this->source_registry,
			$this->scheduler
		);
	}

	public function boot() {
		$this->scheduler->register_callbacks( $this );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );

		if ( is_admin() ) {
			$this->admin_pages->register();
		}
	}

	public static function activate() {
		$run_manager = new BatchRunManager();
		$run_manager->install_schema();

		$scheduler = new Scheduler();
		$scheduler->schedule_recurring_start();
	}

	public static function deactivate() {
		$scheduler = new Scheduler();
		$scheduler->clear_all_events();
	}

	public function start_batch() {
		$this->queue_batch_run( 'cron' );
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
			$this->logger->info( 'No enabled sources are available for batch import.' );

			return array(
				'status'  => 'no_sources',
				'run_id'  => 0,
				'message' => 'No enabled sources are available for batch import.',
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

	private function dependencies_available() {
		return class_exists( 'WP_Job_Manager' ) || post_type_exists( 'job_listing' );
	}
}
