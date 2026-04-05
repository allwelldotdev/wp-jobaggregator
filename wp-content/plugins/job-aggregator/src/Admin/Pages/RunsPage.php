<?php

namespace JobAggregator\Admin\Pages;

use JobAggregator\Admin\Support\AdminView;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Support\Settings;

class RunsPage {
	private $run_manager;
	private $checkpoint_store;
	private $view;
	private $runs_slug;

	public function __construct( BatchRunManager $run_manager, CheckpointStore $checkpoint_store, AdminView $view, $runs_slug ) {
		$this->run_manager      = $run_manager;
		$this->checkpoint_store = $checkpoint_store;
		$this->view             = $view;
		$this->runs_slug        = (string) $runs_slug;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$per_page = max( 1, (int) $settings['runs_per_page'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query arg for pagination.
		$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;
		$total_runs   = $this->run_manager->count_runs();
		$runs         = $this->run_manager->list_recent_runs( $per_page, $offset );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query arg for selecting a run detail view.
		$run_id       = isset( $_GET['run_id'] ) ? (int) $_GET['run_id'] : 0;
		$selected_run = $run_id > 0 ? $this->run_manager->get_run( $run_id ) : array();

		if ( empty( $selected_run ) && ! empty( $runs ) ) {
			$selected_run = $runs[0];
		}

		$selected_run_id = ! empty( $selected_run['id'] ) ? (int) $selected_run['id'] : 0;
		$run_sources     = $selected_run_id > 0 ? $this->checkpoint_store->list_run_sources( $selected_run_id ) : array();
		$total_pages     = (int) ceil( $total_runs / max( 1, $per_page ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Runs', 'job-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Review run summaries and per-source outcomes from persisted run tables.', 'job-aggregator' ); ?></p>

			<?php $this->view->render_manual_start_form( false ); ?>

			<h2><?php esc_html_e( 'Run History', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_runs_table( $runs ); ?>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg(
										array(
											'page'  => $this->runs_slug,
											'paged' => '%#%',
										),
										admin_url( 'admin.php' )
									),
									'format'  => '',
									'current' => $current_page,
									'total'   => $total_pages,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $selected_run_id > 0 ) : ?>
				<?php /* translators: %d: import run ID. */ ?>
				<h2><?php echo esc_html( sprintf( __( 'Run #%d Summary', 'job-aggregator' ), $selected_run_id ) ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->view->status_label( isset( $selected_run['status'] ) ? $selected_run['status'] : '' ) ); ?></td>
							<th><?php esc_html_e( 'Triggered By', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( isset( $selected_run['triggered_by'] ) ? $selected_run['triggered_by'] : '' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Started', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->view->format_mysql_time( isset( $selected_run['started_at'] ) ? $selected_run['started_at'] : '' ) ); ?></td>
							<th><?php esc_html_e( 'Completed', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->view->format_mysql_time( isset( $selected_run['completed_at'] ) ? $selected_run['completed_at'] : '' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Source Progress', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->view->run_progress_text( $selected_run ) ); ?></td>
							<th><?php esc_html_e( 'Follow-up Queued', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( ! empty( $selected_run['has_follow_up'] ) ? __( 'Yes', 'job-aggregator' ) : __( 'No', 'job-aggregator' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Created / Updated', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( sprintf( '%d / %d', (int) $selected_run['created_count'], (int) $selected_run['updated_count'] ) ); ?></td>
							<th><?php esc_html_e( 'Skipped / Errors / Retries', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( sprintf( '%d / %d / %d', (int) $selected_run['skipped_count'], (int) $selected_run['error_count'], (int) $selected_run['retry_count'] ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Per-Source Breakdown', 'job-aggregator' ); ?></h2>
				<?php $this->view->render_run_sources_table( $run_sources ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
