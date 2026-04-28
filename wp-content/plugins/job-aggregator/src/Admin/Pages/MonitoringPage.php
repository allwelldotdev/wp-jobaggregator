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
	const FAILURES_PER_PAGE = 20;

	private $run_manager;
	private $checkpoint_store;
	private $normalization_signals;
	private $view;
	private $monitoring_slug;

	public function __construct( BatchRunManager $run_manager, CheckpointStore $checkpoint_store, NormalizationSignalStore $normalization_signals, AdminView $view, $monitoring_slug ) {
		$this->run_manager           = $run_manager;
		$this->checkpoint_store      = $checkpoint_store;
		$this->normalization_signals = $normalization_signals;
		$this->view                  = $view;
		$this->monitoring_slug       = (string) $monitoring_slug;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$latest_source_statuses = $this->checkpoint_store->list_latest_source_statuses( 200 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query arg for pagination.
		$failures_page       = isset( $_GET['failures_paged'] ) ? max( 1, (int) $_GET['failures_paged'] ) : 1;
		$failures_offset     = ( $failures_page - 1 ) * self::FAILURES_PER_PAGE;
		$total_failures      = $this->checkpoint_store->count_recent_failures();
		$recent_failures     = $this->checkpoint_store->list_recent_failures( self::FAILURES_PER_PAGE, $failures_offset );
		$total_failure_pages = (int) ceil( $total_failures / max( 1, self::FAILURES_PER_PAGE ) );
		$follow_up_runs      = $this->run_manager->list_follow_up_runs( 50 );
		$signal_rows         = $this->normalization_signals->list_recent( 80 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Monitoring', 'job-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Track source health, failure signals, and queued follow-up batch work.', 'job-aggregator' ); ?></p>

			<h2><?php esc_html_e( 'Source Status (Latest by Source)', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_latest_source_statuses_table( $latest_source_statuses ); ?>

			<h2><?php esc_html_e( 'Recent Failures', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_failures_table( $recent_failures ); ?>
			<?php if ( $total_failure_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg(
										array(
											'page' => $this->monitoring_slug,
											'failures_paged' => '%#%',
										),
										admin_url( 'admin.php' )
									),
									'format'  => '',
									'current' => $failures_page,
									'total'   => $total_failure_pages,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Queued Follow-Up Batches', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_follow_up_table( $follow_up_runs, $this->checkpoint_store ); ?>

			<h2><?php esc_html_e( 'Normalization Signals', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_normalization_signals_table( $signal_rows ); ?>
		</div>
		<?php
	}
}
