<?php

namespace JobAggregator;

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
	private $batch_processor;
	private $source_registry;

	public function __construct() {
		$this->logger          = new Logger();
		$http                  = new HttpClient();
		$this->scheduler       = new Scheduler();
		$this->run_manager     = new BatchRunManager();
		$checkpoint_store      = new CheckpointStore();
		$this->source_registry = new SourceRegistry( JOB_AGGREGATOR_PATH . 'config/sources.php', $this->logger, $http );

		$this->batch_processor = new BatchProcessor(
			$this->run_manager,
			$checkpoint_store,
			$this->source_registry,
			new PostWriter( new DuplicateChecker(), $this->logger ),
			$this->scheduler,
			$this->logger,
			new RunLock()
		);
	}

	public function boot() {
		$this->scheduler->register_callbacks( $this );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
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
		if ( ! $this->dependencies_available() ) {
			$this->logger->error( 'WP Job Manager is required before imports can run.' );
			return;
		}

		$this->run_manager->install_schema();

		$active_run = $this->run_manager->get_active_run();
		if ( ! empty( $active_run['id'] ) ) {
			$this->scheduler->schedule_process_event( (int) $active_run['id'], time() + 5 );
			return;
		}

		$sources = $this->source_registry->all();
		if ( empty( $sources ) ) {
			$this->logger->info( 'No enabled sources are available for batch import.' );
			return;
		}

		$run = $this->run_manager->start_run( $sources, 'cron' );
		if ( empty( $run['id'] ) ) {
			$this->logger->error( 'Failed to create import batch run record.' );
			return;
		}

		$this->logger->info(
			'Started import batch run.',
			array(
				'run_id'       => (int) $run['id'],
				'source_count' => count( $sources ),
				'triggered_by' => 'cron',
			)
		);
		$this->scheduler->schedule_process_event( (int) $run['id'], time() + 5 );
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
