<?php

namespace JobAggregator\Support;

class Autoloader {
    public static function register() {
        spl_autoload_register( [ __CLASS__, 'autoload' ] );
    }

    public static function autoload( $class ) {
        $prefix = 'JobAggregator\\';

        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $path     = JOB_AGGREGATOR_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

        if ( file_exists( $path ) ) {
            require $path;
        }
    }
}
