<?php

namespace JobAggregator\Batch;

class RunLock {
	const OPTION_PREFIX = 'job_aggregator_run_lock_';

	private $ttl_seconds;

	public function __construct( $ttl_seconds = 300 ) {
		$this->ttl_seconds = max( 30, (int) $ttl_seconds );
	}

	public function acquire( $run_id ) {
		$run_id = (int) $run_id;
		if ( $run_id < 1 ) {
			return '';
		}

		$option_name = $this->option_name( $run_id );
		$existing    = get_option( $option_name, array() );
		$now         = time();

		if ( is_array( $existing ) && ! empty( $existing['expires_at'] ) && (int) $existing['expires_at'] > $now ) {
			return '';
		}

		$token = wp_generate_uuid4();
		$value = array(
			'run_id'      => $run_id,
			'token'       => $token,
			'acquired_at' => $now,
			'expires_at'  => $now + $this->ttl_seconds,
		);

		if ( false === get_option( $option_name, false ) ) {
			add_option( $option_name, $value, '', 'no' );
		} else {
			update_option( $option_name, $value, false );
		}

		return $token;
	}

	public function release( $run_id, $token = '' ) {
		$run_id      = (int) $run_id;
		$option_name = $this->option_name( $run_id );
		$current     = get_option( $option_name, array() );

		if ( ! empty( $token ) && is_array( $current ) && ! empty( $current['token'] ) && $token !== $current['token'] ) {
			return;
		}

		delete_option( $option_name );
	}

	private function option_name( $run_id ) {
		return self::OPTION_PREFIX . (int) $run_id;
	}
}
