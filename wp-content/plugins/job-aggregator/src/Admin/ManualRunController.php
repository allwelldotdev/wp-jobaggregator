<?php

namespace JobAggregator\Admin;

use JobAggregator\Plugin;

class ManualRunController {
	private $plugin;
	private $runs_slug;
	private $action_start;

	public function __construct( Plugin $plugin, $runs_slug, $action_start ) {
		$this->plugin       = $plugin;
		$this->runs_slug    = (string) $runs_slug;
		$this->action_start = (string) $action_start;
	}

	public function handle_manual_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'job-aggregator' ) );
		}

		check_admin_referer( $this->action_start );

		$result = $this->plugin->trigger_manual_batch();
		$run_id = ! empty( $result['run_id'] ) ? (int) $result['run_id'] : 0;
		$notice = $this->notice_code_for_result( isset( $result['status'] ) ? (string) $result['status'] : '' );
		$args   = array(
			'page'                  => $this->runs_slug,
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

	private function notice_code_for_result( $status ) {
		$map = array(
			'started'            => 'manual_started',
			'active_run'         => 'active_exists',
			'no_sources'         => 'no_sources',
			'dependency_missing' => 'dependency',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : 'run_start_failed';
	}
}
