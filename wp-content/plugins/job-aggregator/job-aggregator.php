<?php
/**
 * Plugin Name: Job Aggregator
 * Description: Imports jobs from configured RSS feeds and APIs into WP Job Manager listings.
 * Version: 0.3.0
 * Author: Allwell Agwu-Okoro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

define( 'JOB_AGGREGATOR_FILE', __FILE__ );
define( 'JOB_AGGREGATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'JOB_AGGREGATOR_URL', plugin_dir_url( __FILE__ ) );

require JOB_AGGREGATOR_PATH . 'src/Support/Autoloader.php';

JobAggregator\Support\Autoloader::register();

register_activation_hook(
	JOB_AGGREGATOR_FILE,
	array(
		'JobAggregator\\Plugin',
		'activate',
	)
);
register_deactivation_hook(
	JOB_AGGREGATOR_FILE,
	array(
		'JobAggregator\\Plugin',
		'deactivate',
	)
);

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new JobAggregator\Plugin();
		$plugin->boot();
	}
);
