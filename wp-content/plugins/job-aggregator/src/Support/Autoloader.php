<?php

namespace JobAggregator\Support;

class Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( $_class ) {
		$prefix = 'JobAggregator\\';

		if ( 0 !== strpos( $_class, $prefix ) ) {
			return;
		}

		$relative = substr( $_class, strlen( $prefix ) );
		$path     =
			JOB_AGGREGATOR_PATH .
			'src/' .
			str_replace( '\\', '/', $relative ) .
			'.php';

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
}
