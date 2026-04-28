<?php

namespace JobAggregator\Admin\Support;

use JobAggregator\Batch\CheckpointStore;
use JobAggregator\Cron\Scheduler;

/**
 * Provides reusable admin UI rendering helpers for tables, links, and formatting.
 */
class AdminView {
	private $runs_slug;
	private $manual_action;

	public function __construct( $runs_slug, $manual_action ) {
		$this->runs_slug     = (string) $runs_slug;
		$this->manual_action = (string) $manual_action;
	}

	public function page_url( $page_slug, array $args = array() ) {
		$params = array_merge(
			array(
				'page' => $page_slug,
			),
			$args
		);

		return add_query_arg( $params, admin_url( 'admin.php' ) );
	}

	public function format_mysql_time( $value ) {
		$value = (string) $value;
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '—';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public function status_label( $status ) {
		$labels = array(
			'pending'       => __( 'Pending', 'job-aggregator' ),
			'running'       => __( 'Running', 'job-aggregator' ),
			'waiting_retry' => __( 'Waiting Retry', 'job-aggregator' ),
			'completed'     => __( 'Completed', 'job-aggregator' ),
			'partial'       => __( 'Partial', 'job-aggregator' ),
			'failed'        => __( 'Failed', 'job-aggregator' ),
			'archived'      => __( 'Archived', 'job-aggregator' ),
			'queued'        => __( 'Queued', 'job-aggregator' ),
		);

		$status = (string) $status;

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	public function run_progress_text( array $run ) {
		$total     = isset( $run['total_sources'] ) ? (int) $run['total_sources'] : 0;
		$processed = isset( $run['processed_sources'] ) ? (int) $run['processed_sources'] : 0;

		return sprintf( '%d / %d', $processed, $total );
	}

	public function run_count_text( array $run ) {
		$created = isset( $run['created_count'] ) ? (int) $run['created_count'] : 0;
		$updated = isset( $run['updated_count'] ) ? (int) $run['updated_count'] : 0;
		$skipped = isset( $run['skipped_count'] ) ? (int) $run['skipped_count'] : 0;
		$errors  = isset( $run['error_count'] ) ? (int) $run['error_count'] : 0;

		return sprintf( 'C:%d U:%d S:%d E:%d', $created, $updated, $skipped, $errors );
	}

	public function render_manual_start_form( $disabled ) {
		$button_attributes = array();
		if ( $disabled ) {
			$button_attributes['disabled'] = 'disabled';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 16px 0;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $this->manual_action ); ?>" />
			<?php wp_nonce_field( $this->manual_action ); ?>
			<?php
			submit_button(
				__( 'Run Import Now', 'job-aggregator' ),
				'primary',
				'submit',
				false,
				$button_attributes
			);
			?>
		</form>
		<?php
	}

	public function render_runs_table( array $runs ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Run ID', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Triggered By', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Counts', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Started', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Completed', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Action', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $runs ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No runs found yet.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $runs as $run ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $run['id'] ); ?></td>
							<td><?php echo esc_html( $this->status_label( isset( $run['status'] ) ? $run['status'] : '' ) ); ?></td>
							<td><?php echo esc_html( isset( $run['triggered_by'] ) ? $run['triggered_by'] : '' ); ?></td>
							<td><?php echo esc_html( $this->run_progress_text( $run ) ); ?></td>
							<td><?php echo esc_html( $this->run_count_text( $run ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $run['started_at'] ) ? $run['started_at'] : '' ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $run['completed_at'] ) ? $run['completed_at'] : '' ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( $this->page_url( $this->runs_slug, array( 'run_id' => (int) $run['id'] ) ) ); ?>"><?php esc_html_e( 'View', 'job-aggregator' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_run_sources_table( array $run_sources ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Created / Updated', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Skipped / Errors', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Retries', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Last Success', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Last Error', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $run_sources ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No source rows found for this run.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $run_sources as $source ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( ! empty( $source['source_label'] ) ? $source['source_label'] : $source['source_key'] ); ?></strong><br />
								<code><?php echo esc_html( $source['source_key'] ); ?></code>
							</td>
							<td><?php echo esc_html( $this->status_label( isset( $source['status'] ) ? $source['status'] : '' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $source['processed_items'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%d / %d', (int) $source['created_count'], (int) $source['updated_count'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%d / %d', (int) $source['skipped_count'], (int) $source['error_count'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%d attempts, %d retries', (int) $source['attempt_count'], (int) $source['retry_count'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $source['last_success_at'] ) ? $source['last_success_at'] : '' ) ); ?></td>
							<td>
								<?php echo esc_html( $this->format_mysql_time( isset( $source['last_error_at'] ) ? $source['last_error_at'] : '' ) ); ?>
								<?php if ( ! empty( $source['last_error_message'] ) ) : ?>
									<br /><?php echo esc_html( $source['last_error_message'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_latest_source_statuses_table( array $rows ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Latest Run', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Retries', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Next Retry', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Last Success', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No source status rows available yet.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( ! empty( $row['source_label'] ) ? $row['source_label'] : $row['source_key'] ); ?></strong><br />
								<code><?php echo esc_html( $row['source_key'] ); ?></code>
							</td>
							<td>
								<a href="<?php echo esc_url( $this->page_url( $this->runs_slug, array( 'run_id' => (int) $row['run_id'] ) ) ); ?>">#<?php echo esc_html( (string) $row['run_id'] ); ?></a>
								(<?php echo esc_html( isset( $row['triggered_by'] ) ? $row['triggered_by'] : '' ); ?>)
							</td>
							<td><?php echo esc_html( $this->status_label( isset( $row['status'] ) ? $row['status'] : '' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['processed_items'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%d retries / %d errors', (int) $row['retry_count'], (int) $row['error_count'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $row['next_retry_at'] ) ? $row['next_retry_at'] : '' ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $row['last_success_at'] ) ? $row['last_success_at'] : '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_failures_table( array $rows ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Run', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Source', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Message', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No failures recorded yet.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $row['last_error_at'] ) ? $row['last_error_at'] : '' ) ); ?></td>
							<td><a href="<?php echo esc_url( $this->page_url( $this->runs_slug, array( 'run_id' => (int) $row['run_id'] ) ) ); ?>">#<?php echo esc_html( (string) $row['run_id'] ); ?></a></td>
							<td><?php echo esc_html( ! empty( $row['source_label'] ) ? $row['source_label'] : $row['source_key'] ); ?></td>
							<td><?php echo esc_html( $this->status_label( isset( $row['status'] ) ? $row['status'] : '' ) ); ?></td>
							<td><?php echo esc_html( isset( $row['last_error_message'] ) ? $row['last_error_message'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_follow_up_table( array $runs, CheckpointStore $checkpoint_store ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Run', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Open Work', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Due Now', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Waiting Retries', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Next Retry Time', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Next Scheduled Batch', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $runs ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No queued follow-up batches right now.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $runs as $run ) : ?>
						<?php
						$snapshot       = $checkpoint_store->queue_snapshot_for_run( (int) $run['id'] );
						$next_scheduled = wp_next_scheduled( Scheduler::PROCESS_HOOK, array( (int) $run['id'] ) );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $this->page_url( $this->runs_slug, array( 'run_id' => (int) $run['id'] ) ) ); ?>">#<?php echo esc_html( (string) $run['id'] ); ?></a></td>
							<td><?php echo esc_html( $this->status_label( isset( $run['status'] ) ? $run['status'] : '' ) ); ?></td>
							<td><?php echo esc_html( (string) $snapshot['open_count'] ); ?></td>
							<td><?php echo esc_html( (string) $snapshot['due_count'] ); ?></td>
							<td><?php echo esc_html( (string) $snapshot['waiting_count'] ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( $snapshot['next_retry_at'] ) ); ?></td>
							<td><?php echo esc_html( $next_scheduled ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_scheduled ) : __( 'Not scheduled', 'job-aggregator' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_normalization_signals_table( array $rows ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Last Seen', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Source', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Signal Type', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Raw Value', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Normalized', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Seen Count', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Example Job', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No normalization signals recorded yet.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $row['last_seen_at'] ) ? $row['last_seen_at'] : '' ) ); ?></td>
							<td>
								<code><?php echo esc_html( isset( $row['source_key'] ) ? (string) $row['source_key'] : '' ); ?></code>
							</td>
							<td><code><?php echo esc_html( isset( $row['signal_type'] ) ? (string) $row['signal_type'] : '' ); ?></code></td>
							<td><?php echo esc_html( isset( $row['raw_value'] ) ? (string) $row['raw_value'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $row['normalized_value'] ) ? (string) $row['normalized_value'] : '' ); ?></td>
							<td><?php echo esc_html( (string) (int) ( isset( $row['seen_count'] ) ? $row['seen_count'] : 0 ) ); ?></td>
							<td>
								<?php if ( ! empty( $row['example_title'] ) ) : ?>
									<?php echo esc_html( (string) $row['example_title'] ); ?><br />
								<?php endif; ?>
								<?php if ( ! empty( $row['example_external_id'] ) ) : ?>
									<code><?php echo esc_html( (string) $row['example_external_id'] ); ?></code>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
