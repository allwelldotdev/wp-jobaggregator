<?php

namespace JobAggregator\Admin;

use JobAggregator\Cron\Scheduler;
use JobAggregator\SourceRegistry;
use JobAggregator\Support\Settings;

/**
 * Registers plugin settings and renders the Settings admin screen.
 */
class SettingsRegistrar {
	private $settings_slug;
	private $scheduler;
	private $source_registry;

	public function __construct( $settings_slug, Scheduler $scheduler, SourceRegistry $source_registry ) {
		$this->settings_slug   = (string) $settings_slug;
		$this->scheduler       = $scheduler;
		$this->source_registry = $source_registry;
	}

	public function register() {
		register_setting(
			'job_aggregator_settings_group',
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section(
			'job_aggregator_schedule_section',
			__( 'Scheduling', 'job-aggregator' ),
			array( $this, 'render_schedule_section' ),
			$this->settings_slug
		);

		add_settings_field(
			'job_aggregator_enable_recurring',
			__( 'Enable Recurring Imports', 'job-aggregator' ),
			array( $this, 'render_field_enable_recurring' ),
			$this->settings_slug,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_recurrence',
			__( 'Recurring Interval', 'job-aggregator' ),
			array( $this, 'render_field_recurrence' ),
			$this->settings_slug,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_process_delay',
			__( 'Chunk Delay (seconds)', 'job-aggregator' ),
			array( $this, 'render_field_process_delay' ),
			$this->settings_slug,
			'job_aggregator_schedule_section'
		);

		add_settings_field(
			'job_aggregator_runs_per_page',
			__( 'Runs Per Page', 'job-aggregator' ),
			array( $this, 'render_field_runs_per_page' ),
			$this->settings_slug,
			'job_aggregator_schedule_section'
		);

		add_settings_section(
			'job_aggregator_sources_section',
			__( 'Source Controls', 'job-aggregator' ),
			array( $this, 'render_sources_section' ),
			$this->settings_slug
		);

		add_settings_field(
			'job_aggregator_source_states',
			__( 'Runtime Source States', 'job-aggregator' ),
			array( $this, 'render_field_source_states' ),
			$this->settings_slug,
			'job_aggregator_sources_section',
			array(
				'class' => 'job-aggregator-source-states-row',
			)
		);
	}

	public function handle_settings_updated( $old_value, $new_value ) {
		unset( $old_value, $new_value );

		$this->scheduler->schedule_recurring_start( true );
	}

	public function sanitize_settings( $input ) {
		return Settings::enforce_configured_source_states(
			Settings::sanitize( $input ),
			$this->source_registry->configured_source_states()
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Job Aggregator Settings', 'job-aggregator' ); ?></h1>
			<style>
				tr.job-aggregator-source-states-row > th,
				tr.job-aggregator-source-states-row > td {
					display: block;
					width: 100%;
				}

				tr.job-aggregator-source-states-row > th {
					padding-bottom: 8px;
				}

				tr.job-aggregator-source-states-row > td {
					padding-top: 0;
					padding-left: 0;
				}

				.job-aggregator-source-states-table thead th {
					padding: 20px 10px;
				}
			</style>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'job_aggregator_settings_group' );
				do_settings_sections( $this->settings_slug );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_schedule_section() {
		echo '<p>' . esc_html__( 'Control recurring imports and queue cadence. Source definitions live in config/sources.php; runtime enablement is controlled below.', 'job-aggregator' ) . '</p>';
	}

	public function render_sources_section() {
		echo '<p>' . esc_html__( 'Use these toggles to include sources in manual and scheduled runs. Sources remain cataloged in config/sources.php.', 'job-aggregator' ) . '</p>';
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

	public function render_field_source_states() {
		$settings           = Settings::all();
		$configured_sources = $this->source_registry->configured();
		$source_states      = isset( $settings['source_states'] ) && is_array( $settings['source_states'] )
			? $settings['source_states']
			: array();
		?>
		<table class="widefat striped job-aggregator-source-states-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Type', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Catalog Default', 'job-aggregator' ); ?></th>
					<th><?php esc_html_e( 'Enabled For Runs', 'job-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $configured_sources ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No sources were found in config/sources.php.', 'job-aggregator' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $configured_sources as $source_row ) : ?>
						<?php
						$key            = (string) $source_row['key'];
						$config_enabled = ! empty( $source_row['config_enabled'] );
						$is_active      = $config_enabled && ! empty( $source_states[ $key ] );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) $source_row['label'] ); ?></strong><br />
								<code><?php echo esc_html( $key ); ?></code>
							</td>
							<td><?php echo esc_html( strtoupper( (string) $source_row['type'] ) ); ?></td>
							<td>
								<?php echo esc_html( ! empty( $source_row['config_enabled'] ) ? __( 'Enabled', 'job-aggregator' ) : __( 'Disabled', 'job-aggregator' ) ); ?>
							</td>
							<td>
								<input
									type="hidden"
									name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[source_states][<?php echo esc_attr( $key ); ?>]"
									value="0"
								/>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[source_states][<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( $is_active ); ?>
										<?php disabled( ! $config_enabled ); ?>
									/>
									<?php echo esc_html( $is_active ? __( 'Enabled', 'job-aggregator' ) : __( 'Disabled', 'job-aggregator' ) ); ?>
								</label>
								<?php if ( ! $config_enabled ) : ?>
									<p class="description">
										<?php esc_html_e( 'Locked by config/sources.php. Enable the catalog source in code before allowing runtime imports.', 'job-aggregator' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Only enabled sources are processed during manual or recurring imports.', 'job-aggregator' ); ?>
		</p>
		<?php
	}
}
