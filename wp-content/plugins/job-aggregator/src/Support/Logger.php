<?php

namespace JobAggregator\Support;

class Logger {
	const OPTION_KEY = 'job_aggregator_run_log';

	public function info( $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	private function write( $level, $message, array $context ) {
		$entries   = get_option( self::OPTION_KEY, array() );
		$entries[] = array(
			'timestamp' => current_time( 'mysql' ),
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
		);

		if ( count( $entries ) > 100 ) {
			$entries = array_slice( $entries, -100 );
		}

		update_option( self::OPTION_KEY, $entries, false );
		error_log( '[job-aggregator][' . $level . '] ' . $message . ' ' . wp_json_encode( $context ) );
	}
}
