<?php

$wp_root_path = dirname( __DIR__, 4 );
$wp_load_file = $wp_root_path . '/wp-load.php';

if ( ! file_exists( $wp_load_file ) ) {
	echo "Could not find wp-load.php at {$wp_load_file}" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $wp_load_file;

$plugin_file = dirname( __DIR__ ) . '/job-aggregator.php';

if ( ! file_exists( $plugin_file ) ) {
	echo "Could not find plugin entry file at {$plugin_file}" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $plugin_file;
