<?php

namespace JobAggregator\Admin;

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

	private $plugin;
	private $run_manager;
	private $checkpoint_store;
	private $source_registry;
	private $scheduler;

	public function __construct(
		Plugin $plugin,
		BatchRunManager $run_manager,
		CheckpointStore $checkpoint_store,
		SourceRegistry $source_registry,
		Scheduler $scheduler
	) {
		$this->plugin           = $plugin;
		$this->run_manager      = $run_manager;
		$this->checkpoint_store = $checkpoint_store;
		$this->source_registry  = $source_registry;
		$this->scheduler        = $scheduler;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'handle_manual_start' ) );
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this, 'handle_settings_updated' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Job Aggregator', 'job-aggregator' ),
			__( 'Job Aggregator', 'job-aggregator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-rss',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'job-aggregator' ),
			__( 'Dashboard', 'job-aggregator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Runs', 'job-aggregator' ),
			__( 'Runs', 'job-aggregator' ),
			'manage_options',
			self::RUNS_SLUG,
			array( $this, 'render_runs_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Monitoring', 'job-aggregator' ),
			__( 'Monitoring', 'job-aggregator' ),
			'manage_options',
			self::MONITORING_SLUG,
			array( $this, 'render_monitoring_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'job-aggregator' ),
			__( 'Settings', 'job-aggregator' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'job_aggregator_settings_group',
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section(
			'job_aggregator_schedule_section',
			__( 'Scheduling', 'job-aggregator' ),
			array( $this, 'render_schedule_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			'job_aggregator_enable_recurring',
			__( 'Enable Recurring Imports', 'job-aggregator' ),
			array( $this, 'render_field_enable_recurring' ),
			self::SETTINGS_SLUG,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_recurrence',
			__( 'Recurring Interval', 'job-aggregator' ),
			array( $this, 'render_field_recurrence' ),
			self::SETTINGS_SLUG,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_process_delay',
			__( 'Chunk Delay (seconds)', 'job-aggregator' ),
			array( $this, 'render_field_process_delay' ),
			self::SETTINGS_SLUG,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_runs_per_page',
			__( 'Runs Per Page', 'job-aggregator' ),
			array( $this, 'render_field_runs_per_page' ),
			self::SETTINGS_SLUG,
			'job_aggregator_schedule_section'
		);
	}

	public function handle_settings_updated( $old_value, $new_value ) {
		unset( $old_value, $new_value );

		$this->scheduler->schedule_recurring_start( true );
	}

	public function render_schedule_section() {
		echo '<p>' . esc_html__( 'Control recurring imports and queue cadence. Source definitions stay in config/sources.php.', 'job-aggregator' ) . '</p>';
	}

	public function render_field_enable_recurring() {
		$settings = Settings::all();
		?>
		<label for="job_aggregator_enable_recurring">
			<input
				type="checkbox"
				id="job_aggregator_enable_recurring"
				name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[enable_recurring]"
				value="1"
				<?php checked( ! empty( $settings['enable_recurring'] ) ); ?>
			/>
			<?php esc_html_e( 'Run automatic imports on a recurring schedule.', 'job-aggregator' ); ?>
		</label>
		<?php
	}

	public function render_field_recurrence() {
		$settings  = Settings::all();
		$schedules = wp_get_schedules();
		uasort(
			$schedules,
			static function ( $left, $right ) {
				return (int) $left['interval'] <=> (int) $right['interval'];
			}
		);
		?>
		<select id="job_aggregator_recurrence" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[recurrence]">
			<?php foreach ( $schedules as $key => $schedule ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['recurrence'], $key ); ?>>
					<?php echo esc_html( $schedule['display'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'How often the plugin starts a new import run.', 'job-aggregator' ); ?>
		</p>
		<?php
	}

	public function render_field_process_delay() {
		$settings = Settings::all();
		?>
		<input
			type="number"
			id="job_aggregator_process_delay"
			name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[process_delay]"
			value="<?php echo esc_attr( (string) $settings['process_delay'] ); ?>"
			min="5"
			max="300"
			step="1"
		/>
		<p class="description">
			<?php esc_html_e( 'Delay before queued follow-up chunk processing. Lower values run faster with higher request frequency.', 'job-aggregator' ); ?>
		</p>
		<?php
	}

	public function render_field_runs_per_page() {
		$settings = Settings::all();
		?>
		<input
			type="number"
			id="job_aggregator_runs_per_page"
			name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[runs_per_page]"
			value="<?php echo esc_attr( (string) $settings['runs_per_page'] ); ?>"
			min="5"
			max="100"
			step="1"
		/>
		<p class="description">
			<?php esc_html_e( 'How many runs to show on the Runs screen.', 'job-aggregator' ); ?>
		</p>
		<?php
	}

	public function handle_manual_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'job-aggregator' ) );
		}

		check_admin_referer( self::ACTION_START );

		$result = $this->plugin->trigger_manual_batch();
		$run_id = ! empty( $result['run_id'] ) ? (int) $result['run_id'] : 0;
		$notice = $this->notice_code_for_result( isset( $result['status'] ) ? (string) $result['status'] : '' );
		$args   = array(
			'page'                  => self::RUNS_SLUG,
			'job_aggregator_notice' => $notice,
		);

		if ( $run_id > 0 ) {
			$args['run_id'] = $run_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query arg for notice rendering.
		$notice_code = isset( $_GET['job_aggregator_notice'] ) ? sanitize_key( wp_unslash( $_GET['job_aggregator_notice'] ) ) : '';
		if ( '' === $notice_code ) {
			return;
		}

		$notices = array(
			'manual_started'   => array(
				'class' => 'notice notice-success is-dismissible',
				'text'  => __( 'Manual import run queued. Refresh the Runs screen for live progress.', 'job-aggregator' ),
			),
			'active_exists'    => array(
				'class' => 'notice notice-info is-dismissible',
				'text'  => __( 'A run is already active. The next processing event was refreshed.', 'job-aggregator' ),
			),
			'no_sources'       => array(
				'class' => 'notice notice-warning is-dismissible',
				'text'  => __( 'No enabled sources are configured. Update config/sources.php and try again.', 'job-aggregator' ),
			),
			'dependency'       => array(
				'class' => 'notice notice-error is-dismissible',
				'text'  => __( 'WP Job Manager is required before imports can run.', 'job-aggregator' ),
			),
			'run_start_failed' => array(
				'class' => 'notice notice-error is-dismissible',
				'text'  => __( 'Could not create a new run record. Check logs for details.', 'job-aggregator' ),
			),
		);

		if ( ! isset( $notices[ $notice_code ] ) ) {
			return;
		}

		$notice = $notices[ $notice_code ];

		echo '<div class="' . esc_attr( $notice['class'] ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
	}

	public function render_dashboard_page() {
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
			<?php $this->render_manual_start_form( empty( $enabled_sources ) ); ?>

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
							<td><?php echo esc_html( $this->status_label( $active_run['status'] ) ); ?></td>
							<td><?php echo esc_html( $this->run_progress_text( $active_run ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $active_run['started_at'] ) ? $active_run['started_at'] : '' ) ); ?></td>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $active_run['last_activity_at'] ) ? $active_run['last_activity_at'] : '' ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG, array( 'run_id' => (int) $active_run['id'] ) ) ); ?>"><?php esc_html_e( 'View Run', 'job-aggregator' ); ?></a></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent Runs', 'job-aggregator' ); ?></h2>
			<?php $this->render_runs_table( $recent_runs ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG ) ); ?>"><?php esc_html_e( 'Open Full Run History', 'job-aggregator' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function render_runs_page() {
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

			<?php $this->render_manual_start_form( false ); ?>

			<h2><?php esc_html_e( 'Run History', 'job-aggregator' ); ?></h2>
			<?php $this->render_runs_table( $runs ); ?>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg(
										array(
											'page'  => self::RUNS_SLUG,
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
							<td><?php echo esc_html( $this->status_label( isset( $selected_run['status'] ) ? $selected_run['status'] : '' ) ); ?></td>
							<th><?php esc_html_e( 'Triggered By', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( isset( $selected_run['triggered_by'] ) ? $selected_run['triggered_by'] : '' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Started', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $selected_run['started_at'] ) ? $selected_run['started_at'] : '' ) ); ?></td>
							<th><?php esc_html_e( 'Completed', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->format_mysql_time( isset( $selected_run['completed_at'] ) ? $selected_run['completed_at'] : '' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Source Progress', 'job-aggregator' ); ?></th>
							<td><?php echo esc_html( $this->run_progress_text( $selected_run ) ); ?></td>
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
				<?php $this->render_run_sources_table( $run_sources ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_monitoring_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$latest_source_statuses = $this->checkpoint_store->list_latest_source_statuses( 200 );
		$recent_failures        = $this->checkpoint_store->list_recent_failures( 40 );
		$follow_up_runs         = $this->run_manager->list_follow_up_runs( 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Monitoring', 'job-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Track source health, failure signals, and queued follow-up batch work.', 'job-aggregator' ); ?></p>

			<h2><?php esc_html_e( 'Source Status (Latest by Source)', 'job-aggregator' ); ?></h2>
			<?php $this->render_latest_source_statuses_table( $latest_source_statuses ); ?>

			<h2><?php esc_html_e( 'Recent Failures', 'job-aggregator' ); ?></h2>
			<?php $this->render_failures_table( $recent_failures ); ?>

			<h2><?php esc_html_e( 'Queued Follow-Up Batches', 'job-aggregator' ); ?></h2>
			<?php $this->render_follow_up_table( $follow_up_runs ); ?>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled_sources = $this->source_registry->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Job Aggregator Settings', 'job-aggregator' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'job_aggregator_settings_group' );
				do_settings_sections( self::SETTINGS_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Enabled Sources', 'job-aggregator' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source Label', 'job-aggregator' ); ?></th>
						<th><?php esc_html_e( 'Source Key', 'job-aggregator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $enabled_sources ) ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( 'No enabled sources found. Edit config/sources.php to add or enable sources.', 'job-aggregator' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $enabled_sources as $source ) : ?>
							<tr>
								<td><?php echo esc_html( $source->get_label() ); ?></td>
								<td><?php echo esc_html( $source->get_key() ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_manual_start_form( $disabled ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 16px 0;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_START ); ?>" />
			<?php wp_nonce_field( self::ACTION_START ); ?>
			<?php
			submit_button(
				__( 'Run Import Now', 'job-aggregator' ),
				'primary',
				'submit',
				false,
				array(
					'disabled' => $disabled ? 'disabled' : null,
				)
			);
			?>
		</form>
		<?php
	}

	private function render_runs_table( array $runs ) {
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
							<td><a class="button" href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG, array( 'run_id' => (int) $run['id'] ) ) ); ?>"><?php esc_html_e( 'View', 'job-aggregator' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_run_sources_table( array $run_sources ) {
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

	private function render_latest_source_statuses_table( array $rows ) {
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
								<a href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG, array( 'run_id' => (int) $row['run_id'] ) ) ); ?>">#<?php echo esc_html( (string) $row['run_id'] ); ?></a>
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

	private function render_failures_table( array $rows ) {
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
							<td><a href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG, array( 'run_id' => (int) $row['run_id'] ) ) ); ?>">#<?php echo esc_html( (string) $row['run_id'] ); ?></a></td>
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

	private function render_follow_up_table( array $runs ) {
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
						$snapshot       = $this->checkpoint_store->queue_snapshot_for_run( (int) $run['id'] );
						$next_scheduled = wp_next_scheduled( Scheduler::PROCESS_HOOK, array( (int) $run['id'] ) );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $this->page_url( self::RUNS_SLUG, array( 'run_id' => (int) $run['id'] ) ) ); ?>">#<?php echo esc_html( (string) $run['id'] ); ?></a></td>
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

	private function notice_code_for_result( $status ) {
		$map = array(
			'started'            => 'manual_started',
			'active_run'         => 'active_exists',
			'no_sources'         => 'no_sources',
			'dependency_missing' => 'dependency',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : 'run_start_failed';
	}

	private function run_progress_text( array $run ) {
		$total     = isset( $run['total_sources'] ) ? (int) $run['total_sources'] : 0;
		$processed = isset( $run['processed_sources'] ) ? (int) $run['processed_sources'] : 0;

		return sprintf( '%d / %d', $processed, $total );
	}

	private function run_count_text( array $run ) {
		$created = isset( $run['created_count'] ) ? (int) $run['created_count'] : 0;
		$updated = isset( $run['updated_count'] ) ? (int) $run['updated_count'] : 0;
		$skipped = isset( $run['skipped_count'] ) ? (int) $run['skipped_count'] : 0;
		$errors  = isset( $run['error_count'] ) ? (int) $run['error_count'] : 0;

		return sprintf( 'C:%d U:%d S:%d E:%d', $created, $updated, $skipped, $errors );
	}

	private function status_label( $status ) {
		$labels = array(
			'pending'       => __( 'Pending', 'job-aggregator' ),
			'running'       => __( 'Running', 'job-aggregator' ),
			'waiting_retry' => __( 'Waiting Retry', 'job-aggregator' ),
			'completed'     => __( 'Completed', 'job-aggregator' ),
			'partial'       => __( 'Partial', 'job-aggregator' ),
			'failed'        => __( 'Failed', 'job-aggregator' ),
			'queued'        => __( 'Queued', 'job-aggregator' ),
		);

		$status = (string) $status;

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	private function format_mysql_time( $value ) {
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

	private function page_url( $page_slug, array $args = array() ) {
		$params = array_merge(
			array(
				'page' => $page_slug,
			),
			$args
		);

		return add_query_arg( $params, admin_url( 'admin.php' ) );
	}
}
