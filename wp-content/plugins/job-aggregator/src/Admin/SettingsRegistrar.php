<?php

namespace JobAggregator\Admin;

use JobAggregator\Cron\Scheduler;
use JobAggregator\SourceRegistry;
use JobAggregator\Support\Settings;

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
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
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
	}

	public function handle_settings_updated( $old_value, $new_value ) {
		unset( $old_value, $new_value );

		$this->scheduler->schedule_recurring_start( true );
	}

	public function render_page() {
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
				do_settings_sections( $this->settings_slug );
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
}
