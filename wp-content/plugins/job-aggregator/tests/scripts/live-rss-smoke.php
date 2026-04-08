<?php

use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Cron\Scheduler;
use JobAggregator\Plugin;

$plugin_file = WP_CONTENT_DIR . '/plugins/job-aggregator/job-aggregator.php';
if ( file_exists( $plugin_file ) ) {
	require_once $plugin_file;
}

if ( ! class_exists( Plugin::class ) ) {
	echo "Job Aggregator plugin classes are unavailable.\n";
	return;
}

if ( ! post_type_exists( 'job_listing' ) ) {
	register_post_type(
		'job_listing',
		array(
			'public'   => true,
			'label'    => 'Job Listing',
			'supports' => array( 'title', 'editor', 'thumbnail' ),
		)
	);
}

if ( ! taxonomy_exists( 'job_listing_type' ) ) {
	register_taxonomy( 'job_listing_type', 'job_listing', array( 'hierarchical' => false ) );
}

if ( ! taxonomy_exists( 'job_listing_category' ) ) {
	register_taxonomy( 'job_listing_category', 'job_listing', array( 'hierarchical' => false ) );
}

$persist = false;
if ( isset( $argv ) && is_array( $argv ) ) {
	foreach ( $argv as $arg ) {
		if ( '--persist=1' === $arg || '--persist' === $arg ) {
			$persist = true;
			break;
		}
	}
}

$base_config_path = WP_CONTENT_DIR . '/plugins/job-aggregator/config/sources.php';
$base_config      = file_exists( $base_config_path ) ? require $base_config_path : array();
$rss_sources      = isset( $base_config['rss'] ) ? (array) $base_config['rss'] : array();

$source_prefix = 'e2e_live_' . gmdate( 'YmdHis' ) . '_';
$source_keys   = array();

foreach ( $rss_sources as $index => $source ) {
	$source['enabled'] = true;

	$existing_key   = isset( $source['key'] ) ? sanitize_key( (string) $source['key'] ) : '';
	$prefixed_key   = $source_prefix . ( '' !== $existing_key ? $existing_key : (string) $index );
	$source['key']  = sanitize_key( $prefixed_key );
	$source_keys[]  = $source['key'];
	$rss_sources[ $index ] = $source;
}

$live_config = array(
	'rss'  => $rss_sources,
	'apis' => array(),
);

$temp_config_path = trailingslashit( sys_get_temp_dir() ) . 'job-aggregator-live-rss-' . uniqid( '', true ) . '.php';
file_put_contents(
	$temp_config_path,
	"<?php\n\nreturn " . var_export( $live_config, true ) . ";\n"
);

$config_filter = static function () use ( $temp_config_path ) {
	return $temp_config_path;
};
add_filter( 'job_aggregator_sources_config_path', $config_filter );

Plugin::activate();

$plugin = new Plugin();
$result = $plugin->trigger_manual_batch();
$run_id = ! empty( $result['run_id'] ) ? (int) $result['run_id'] : 0;

if ( 'started' !== (string) $result['status'] ) {
	remove_filter( 'job_aggregator_sources_config_path', $config_filter );
	@unlink( $temp_config_path );
	echo 'Live RSS smoke did not start: ' . wp_json_encode( $result ) . "\n";
	return;
}

$run_manager = new BatchRunManager();
$run         = array();

for ( $iteration = 0; $iteration < 120; $iteration++ ) {
	$run = $run_manager->get_run( $run_id );
	if ( ! is_array( $run ) || empty( $run ) ) {
		break;
	}

	if ( in_array( (string) $run['status'], array( 'completed', 'partial', 'failed' ), true ) ) {
		break;
	}

	$plugin->process_batch( $run_id );
}

$run = $run_manager->get_run( $run_id );

$summary = array(
	'mode'            => $persist ? 'persist' : 'isolated',
	'run_id'          => $run_id,
	'run_status'      => isset( $run['status'] ) ? (string) $run['status'] : 'unknown',
	'created_count'   => isset( $run['created_count'] ) ? (int) $run['created_count'] : 0,
	'updated_count'   => isset( $run['updated_count'] ) ? (int) $run['updated_count'] : 0,
	'skipped_count'   => isset( $run['skipped_count'] ) ? (int) $run['skipped_count'] : 0,
	'error_count'     => isset( $run['error_count'] ) ? (int) $run['error_count'] : 0,
	'source_key_list' => $source_keys,
);

if ( ! $persist ) {
	global $wpdb;

	$post_ids = array();
	foreach ( $source_keys as $source_key ) {
		$matching_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				  AND meta_value = %s",
				'_job_aggregator_source_key',
				$source_key
			)
		);

		foreach ( (array) $matching_ids as $post_id ) {
			$post_ids[] = (int) $post_id;
		}
	}

	$post_ids = array_values( array_unique( $post_ids ) );

	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	if ( $run_id > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'job_aggregator_run_sources', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'job_aggregator_runs', array( 'id' => $run_id ), array( '%d' ) );
	}

	foreach ( $source_keys as $source_key ) {
		$wpdb->delete(
			$wpdb->prefix . 'job_aggregator_normalization_signals',
			array( 'source_key' => $source_key ),
			array( '%s' )
		);
	}

	$cron = _get_cron_array();
	if ( is_array( $cron ) ) {
		foreach ( $cron as $timestamp => $hooks ) {
			if ( empty( $hooks[ Scheduler::PROCESS_HOOK ] ) || ! is_array( $hooks[ Scheduler::PROCESS_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ Scheduler::PROCESS_HOOK ] as $event ) {
				$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				wp_unschedule_event( (int) $timestamp, Scheduler::PROCESS_HOOK, $args );
			}
		}
	}

	$summary['cleanup_post_count'] = count( $post_ids );
	$summary['cleanup_done']       = true;
}

remove_filter( 'job_aggregator_sources_config_path', $config_filter );
@unlink( $temp_config_path );

echo wp_json_encode( $summary, JSON_PRETTY_PRINT ) . "\n";
