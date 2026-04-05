<?php

namespace JobAggregator\Admin\Pages;

use JobAggregator\Admin\Support\AdminView;
use JobAggregator\Batch\BatchRunManager;
use JobAggregator\SourceRegistry;

class DashboardPage {
	private $run_manager;
	private $source_registry;
	private $view;
	private $runs_slug;

	public function __construct( BatchRunManager $run_manager, SourceRegistry $source_registry, AdminView $view, $runs_slug ) {
		$this->run_manager     = $run_manager;
		$this->source_registry = $source_registry;
		$this->view            = $view;
		$this->runs_slug       = (string) $runs_slug;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_run      = $this->run_manager->get_active_run();
		$recent_runs     = $this->run_manager->list_recent_runs( 5, 0 );
		$enabled_sources = $this->source_registry->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Job Aggregator Dashboard', 'job-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Start imports manually, monitor active work, and review recent run outcomes.', 'job-aggregator' ); ?></p>

			<h2><?php esc_html_e( 'Manual Import', 'job-aggregator' ); ?></h2>
			<?php if ( empty( $enabled_sources ) ) : ?>
				<p>
					<?php esc_html_e( 'No enabled sources found in config/sources.php.', 'job-aggregator' ); ?>
				</p>
			<?php endif; ?>
			<?php $this->view->render_manual_start_form( empty( $enabled_sources ) ); ?>

			<?php if ( ! empty( $active_run['id'] ) ) : ?>
				<h2><?php esc_html_e( 'Active Run', 'job-aggregator' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Run ID', 'job-aggregator' ); ?></th>
							<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'job-aggregator' ); ?></th>
							<th><?php esc_html_e( 'Started', 'job-aggregator' ); ?></th>
							<th><?php esc_html_e( 'Last Activity', 'job-aggregator' ); ?></th>
							<th><?php esc_html_e( 'Action', 'job-aggregator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php echo esc_html( (string) $active_run['id'] ); ?></td>
							<td><?php echo esc_html( $this->view->status_label( $active_run['status'] ) ); ?></td>
							<td><?php echo esc_html( $this->view->run_progress_text( $active_run ) ); ?></td>
							<td><?php echo esc_html( $this->view->format_mysql_time( isset( $active_run['started_at'] ) ? $active_run['started_at'] : '' ) ); ?></td>
							<td><?php echo esc_html( $this->view->format_mysql_time( isset( $active_run['last_activity_at'] ) ? $active_run['last_activity_at'] : '' ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( $this->view->page_url( $this->runs_slug, array( 'run_id' => (int) $active_run['id'] ) ) ); ?>"><?php esc_html_e( 'View Run', 'job-aggregator' ); ?></a></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent Runs', 'job-aggregator' ); ?></h2>
			<?php $this->view->render_runs_table( $recent_runs ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( $this->view->page_url( $this->runs_slug ) ); ?>"><?php esc_html_e( 'Open Full Run History', 'job-aggregator' ); ?></a>
			</p>
		</div>
		<?php
	}
}
