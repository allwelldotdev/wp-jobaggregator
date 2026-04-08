<?php

namespace JobAggregator\Admin\Pages;

use JobAggregator\Admin\Support\AdminView;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Jobs\NormalizationSignalStore;

/**
 * Renders operational monitoring data including source status, failures, and signals.
 */
class MonitoringPage {
	private $run_manager;
	private $checkpoint_store;
	private $normalization_signals;
	private $view;

	public function __construct( BatchRunManager $run_manager, CheckpointStore $checkpoint_store, NormalizationSignalStore $normalization_signals, AdminView $view ) {
		$this->run_manager           = $run_manager;
		$this->checkpoint_store      = $checkpoint_store;
		$this->normalization_signals = $normalization_signals;
		$this->view                  = $view;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$latest_source_statuses = $this->checkpoint_store->list_latest_source_statuses( 200 );
		$recent_failures        = $this->checkpoint_store->list_recent_failures( 40 );
		$follow_up_runs         = $this->run_manager->list_follow_up_runs( 50 );
		$signal_rows            = $this->normalization_signals->list_recent( 80 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Monitoring', 'job-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Track source health, failure signals, and queued follow-up batch work.', 'job-aggregator' ); ?></p>

			<h2><?php esc_html_e( 'Source Status (Latest by Source)', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_latest_source_statuses_table( $latest_source_statuses ); ?>

			<h2><?php esc_html_e( 'Recent Failures', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_failures_table( $recent_failures ); ?>

			<h2><?php esc_html_e( 'Queued Follow-Up Batches', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_follow_up_table( $follow_up_runs, $this->checkpoint_store ); ?>

			<h2><?php esc_html_e( 'Normalization Signals', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_normalization_signals_table( $signal_rows ); ?>
		</div>
		<?php
	}
}
