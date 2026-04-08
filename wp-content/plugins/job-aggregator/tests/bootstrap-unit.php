<?php

if ( ! defined( 'JOB_AGGREGATOR_PATH' ) ) {
	define( 'JOB_AGGREGATOR_PATH', dirname( __DIR__ ) . '/' );
}

require JOB_AGGREGATOR_PATH . 'src/Support/Autoloader.php';

JobAggregator\Support\Autoloader::register();

foreach ( glob( __DIR__ . '/Support/*.php' ) as $support_file ) {
	require_once $support_file;
}
