<?php

namespace JobAggregator\Tests\Integration\E2E;

use JobAggregator\Batch\BatchRunManager;
use JobAggregator\Cron\Scheduler;
use JobAggregator\Plugin;
use JobAggregator\Support\Settings;
use PHPUnit\Framework\TestCase;

class RssIngestionE2ETest extends TestCase {
	const SOURCE_META_KEY           = '_job_aggregator_source_key';
	const TEST_SOURCE_KEY_PREFIX    = 'e2e_';
	const TEST_CONFIG_RELATIVE_PATH = 'tests/config/sources.integration.php';
	const TEST_CONFIG_UPDATED_RELATIVE_PATH = 'tests/config/sources.integration.updated.php';

	private $fixture_body_by_url = array();
	private $config_filter;
	private $http_filter;
	private $feed_cache_filter;
	private $run_ids = array();
	private $original_settings;
	private $test_config_relative_path = self::TEST_CONFIG_RELATIVE_PATH;

	protected function setUp(): void {
		$this->register_job_listing_schema();
		Plugin::activate();
		$this->delete_test_run_rows();
		$this->delete_test_posts();
		$this->clear_feed_transients();
		$this->original_settings = get_option( Settings::OPTION_KEY, null );
		$this->test_config_relative_path = self::TEST_CONFIG_RELATIVE_PATH;
		$this->configure_source_states( array( 'e2e_myjobmag', 'e2e_remoteok', 'e2e_weworkremotely' ) );
		$this->fixture_body_by_url = $this->load_fixture_body_by_url();
		$this->register_test_filters();
	}

	protected function tearDown(): void {
		$this->remove_test_filters();
		$this->clear_hook_events( Scheduler::START_HOOK );
		$this->clear_hook_events( Scheduler::PROCESS_HOOK );
		$this->clear_feed_transients();
		$this->delete_test_posts();
		$this->delete_test_run_rows();
		if ( null === $this->original_settings ) {
			delete_option( Settings::OPTION_KEY );
		} else {
			update_option( Settings::OPTION_KEY, $this->original_settings );
		}
	}

	public function test_ingests_enabled_rss_sources_into_job_listing_posts() {
		$run = $this->run_import_to_completion();

		$this->assertSame( 'completed', (string) $run['status'] );
		$this->assertSame( 3, (int) $run['created_count'] );
		$this->assertSame( 0, (int) $run['updated_count'] );
		$this->assertSame( 0, (int) $run['error_count'] );

		$post_map = $this->get_source_post_map();
		$this->assertCount( 3, $post_map );
		$this->assertArrayHasKey( 'e2e_myjobmag', $post_map );
		$this->assertArrayHasKey( 'e2e_remoteok', $post_map );
		$this->assertArrayHasKey( 'e2e_weworkremotely', $post_map );

		$myjobmag_post_id = $post_map['e2e_myjobmag'];
		$this->assertSame( 'Abia', get_post_meta( $myjobmag_post_id, '_job_location', true ) );
		$this->assertSame( '500000', get_post_meta( $myjobmag_post_id, '_job_salary', true ) );
		$this->assertSame( 'NGN', get_post_meta( $myjobmag_post_id, '_job_salary_currency', true ) );

		$remoteok_post_id = $post_map['e2e_remoteok'];
		$this->assertSame( '1', get_post_meta( $remoteok_post_id, '_remote_position', true ) );
		$this->assertSame( 'Worldwide', get_post_meta( $remoteok_post_id, '_job_location', true ) );
		$this->assertSame( 'RemoteOK Fixture Co', get_post_meta( $remoteok_post_id, '_company_name', true ) );
		$this->assertSame( 'https://fixtures.job-aggregator.test/jobs/remoteok-001', get_post_meta( $remoteok_post_id, '_job_aggregator_source_url', true ) );

		$wwr_post_id = $post_map['e2e_weworkremotely'];
		$this->assertSame( 'Acme Corp', get_post_meta( $wwr_post_id, '_company_name', true ) );
		$this->assertSame( 'Anywhere in the World, California', get_post_meta( $wwr_post_id, '_job_location', true ) );

		$category_terms = wp_get_post_terms( $wwr_post_id, 'job_listing_category', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'other-automated', $category_terms );

		$this->assert_run_sources_completed( (int) $run['id'] );
	}

	public function test_reingestion_updates_existing_posts_without_duplicates() {
		$first_run         = $this->run_import_to_completion();
		$first_source_post = $this->get_source_post_map();

		$this->assertSame( 'completed', (string) $first_run['status'] );
		$this->assertSame( 3, (int) $first_run['created_count'] );

		$second_run          = $this->run_import_to_completion();
		$second_source_posts = $this->get_source_post_map();

		$this->assertSame( 'completed', (string) $second_run['status'] );
		$this->assertSame( 0, (int) $second_run['created_count'] );
		$this->assertSame( 0, (int) $second_run['updated_count'] );
		$this->assertSame( 3, (int) $second_run['skipped_count'] );
		$this->assertSame( 3, count( $second_source_posts ) );
		$this->assertSame( $first_source_post, $second_source_posts );
	}

	public function test_reingestion_updates_only_changed_listing_when_fixture_changes() {
		$first_run = $this->run_import_to_completion();

		$this->assertSame( 'completed', (string) $first_run['status'] );
		$this->assertSame( 3, (int) $first_run['created_count'] );

		$source_posts_before = $this->get_source_post_map();

		$this->test_config_relative_path = self::TEST_CONFIG_UPDATED_RELATIVE_PATH;
		$this->clear_feed_transients();

		$second_run         = $this->run_import_to_completion();
		$source_posts_after = $this->get_source_post_map();

		$this->assertSame( 'completed', (string) $second_run['status'] );
		$this->assertSame( 0, (int) $second_run['created_count'] );
		$this->assertSame( 1, (int) $second_run['updated_count'] );
		$this->assertSame( 2, (int) $second_run['skipped_count'] );
		$this->assertSame( $source_posts_before, $source_posts_after );

		$remote_post_id = $source_posts_after['e2e_remoteok'];
		$this->assertSame(
			'RemoteOK Fixture Co Updated',
			(string) get_post_meta( $remote_post_id, '_company_name', true )
		);
	}

	public function test_source_state_overrides_limit_manual_runs_to_enabled_sources() {
		$this->configure_source_states( array( 'e2e_remoteok' ) );

		$run = $this->run_import_to_completion();

		$this->assertSame( 'completed', (string) $run['status'] );
		$this->assertSame( 1, (int) $run['total_sources'] );
		$this->assertSame( 1, (int) $run['created_count'] );
		$this->assertSame( 0, (int) $run['updated_count'] );
		$this->assertSame( 0, (int) $run['error_count'] );

		$post_map = $this->get_source_post_map( array( 'e2e_remoteok' ) );
		$this->assertCount( 1, $post_map );
		$this->assertArrayHasKey( 'e2e_remoteok', $post_map );
	}

	private function run_import_to_completion() {
		$plugin = new Plugin();
		$result = $plugin->trigger_manual_batch();

		$this->assertContains( (string) $result['status'], array( 'started', 'active_run' ) );
		$this->assertGreaterThan( 0, (int) $result['run_id'] );

		$run_id      = (int) $result['run_id'];
		$this->run_ids[] = $run_id;
		$run_manager = new BatchRunManager();
		$run         = array();

		for ( $iteration = 0; $iteration < 30; $iteration++ ) {
			$run = $run_manager->get_run( $run_id );

			$this->assertIsArray( $run );
			$this->assertNotEmpty( $run );

			if ( in_array( (string) $run['status'], array( 'completed', 'partial', 'failed' ), true ) ) {
				break;
			}

			$plugin->process_batch( $run_id );
		}

		$run = $run_manager->get_run( $run_id );
		$this->assertIsArray( $run );
		$this->assertNotEmpty( $run );
		$this->assertContains( (string) $run['status'], array( 'completed', 'partial', 'failed' ) );

		return $run;
	}

	private function register_job_listing_schema() {
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
			register_taxonomy(
				'job_listing_type',
				'job_listing',
				array(
					'hierarchical' => false,
					'label'        => 'Job Type',
				)
			);
		}

		if ( ! taxonomy_exists( 'job_listing_category' ) ) {
			register_taxonomy(
				'job_listing_category',
				'job_listing',
				array(
					'hierarchical' => false,
					'label'        => 'Job Category',
				)
			);
		}
	}

	private function register_test_filters() {
		$this->config_filter = function () {
			return JOB_AGGREGATOR_PATH . $this->test_config_relative_path;
		};
		add_filter( 'job_aggregator_sources_config_path', $this->config_filter );

		$this->http_filter = function ( $preempt, $request_args, $url ) {
			unset( $request_args );
			if ( ! isset( $this->fixture_body_by_url[ $url ] ) ) {
				return $preempt;
			}

			return array(
				'headers'  => array( 'content-type' => 'application/rss+xml; charset=utf-8' ),
				'body'     => $this->fixture_body_by_url[ $url ],
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$this->feed_cache_filter = static function () {
			return 0;
		};
		add_filter( 'wp_feed_cache_transient_lifetime', $this->feed_cache_filter );
	}

	private function remove_test_filters() {
		if ( null !== $this->config_filter ) {
			remove_filter( 'job_aggregator_sources_config_path', $this->config_filter );
			$this->config_filter = null;
		}

		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}

		if ( null !== $this->feed_cache_filter ) {
			remove_filter( 'wp_feed_cache_transient_lifetime', $this->feed_cache_filter );
			$this->feed_cache_filter = null;
		}
	}

	private function load_fixture_body_by_url() {
		$fixture_base = JOB_AGGREGATOR_PATH . 'tests/fixtures/rss/';

		return array(
			'https://fixtures.job-aggregator.test/myjobmag.xml'      => (string) file_get_contents( $fixture_base . 'myjobmag.xml' ),
			'https://fixtures.job-aggregator.test/remoteok.xml'      => (string) file_get_contents( $fixture_base . 'remoteok.xml' ),
			'https://fixtures.job-aggregator.test/remoteok-updated.xml' => (string) file_get_contents( $fixture_base . 'remoteok-updated.xml' ),
			'https://fixtures.job-aggregator.test/weworkremotely.xml' => (string) file_get_contents( $fixture_base . 'weworkremotely.xml' ),
		);
	}

	private function get_source_post_map( array $source_keys = array( 'e2e_myjobmag', 'e2e_remoteok', 'e2e_weworkremotely' ) ) {
		$query = new \WP_Query(
			array(
				'post_type'      => 'job_listing',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::SOURCE_META_KEY,
						'value'   => array_values( $source_keys ),
						'compare' => 'IN',
					),
				),
			)
		);

		$map = array();
		foreach ( $query->posts as $post_id ) {
			$source_key          = (string) get_post_meta( (int) $post_id, self::SOURCE_META_KEY, true );
			$map[ $source_key ] = (int) $post_id;
		}

		ksort( $map );

		return $map;
	}

	private function configure_source_states( array $enabled_source_keys ) {
		$enabled_map = array();
		foreach ( $enabled_source_keys as $source_key ) {
			$enabled_map[ (string) $source_key ] = 1;
		}

		update_option(
			Settings::OPTION_KEY,
			array(
				'enable_recurring' => 0,
				'recurrence'       => Scheduler::EVERY_TWO_HOURS,
				'process_delay'    => 5,
				'runs_per_page'    => 20,
				'source_states'    => array(
					'e2e_myjobmag'       => ! empty( $enabled_map['e2e_myjobmag'] ) ? 1 : 0,
					'e2e_remoteok'       => ! empty( $enabled_map['e2e_remoteok'] ) ? 1 : 0,
					'e2e_weworkremotely' => ! empty( $enabled_map['e2e_weworkremotely'] ) ? 1 : 0,
				),
			)
		);
	}

	private function delete_test_run_rows() {
		global $wpdb;

		$run_sources_table = $wpdb->prefix . 'job_aggregator_run_sources';
		$runs_table        = $wpdb->prefix . 'job_aggregator_runs';
		$signals_table     = $wpdb->prefix . 'job_aggregator_normalization_signals';

		$run_ids = $this->run_ids;
		$source_run_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT run_id
				FROM {$run_sources_table}
				WHERE source_key LIKE %s",
				self::TEST_SOURCE_KEY_PREFIX . '%'
			)
		);
		foreach ( (array) $source_run_ids as $run_id ) {
			$run_ids[] = (int) $run_id;
		}

		$run_ids = array_values( array_unique( array_map( 'intval', $run_ids ) ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$signals_table}
				WHERE source_key LIKE %s",
				self::TEST_SOURCE_KEY_PREFIX . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$run_sources_table}
				WHERE source_key LIKE %s",
				self::TEST_SOURCE_KEY_PREFIX . '%'
			)
		);

		foreach ( $run_ids as $run_id ) {
			$wpdb->delete( $run_sources_table, array( 'run_id' => $run_id ), array( '%d' ) );
			$wpdb->delete( $runs_table, array( 'id' => $run_id ), array( '%d' ) );
		}
	}

	private function clear_feed_transients() {
		global $wpdb;

		$options_exists = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->options
			)
		);

		if ( $wpdb->options !== $options_exists ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_feed_%',
				'_transient_timeout_feed_%'
			)
		);
		wp_cache_flush();
	}

	private function delete_test_posts() {
		global $wpdb;

		$postmeta_exists = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->postmeta
			)
		);

		if ( $wpdb->postmeta !== $postmeta_exists ) {
			return;
		}

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				  AND meta_value LIKE %s",
				self::SOURCE_META_KEY,
				self::TEST_SOURCE_KEY_PREFIX . '%'
			)
		);

		$post_ids = array_map( 'intval', array_unique( (array) $post_ids ) );
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	private function clear_hook_events( $hook_name ) {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ $hook_name ] ) || ! is_array( $hooks[ $hook_name ] ) ) {
				continue;
			}

			foreach ( $hooks[ $hook_name ] as $event ) {
				if ( ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
					continue;
				}

				wp_unschedule_event( (int) $timestamp, $hook_name, $event['args'] );
			}
		}
	}

	private function assert_run_sources_completed( $run_id ) {
		global $wpdb;

		$table_exists = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->prefix . 'job_aggregator_run_sources'
			)
		);

		$table = $wpdb->prefix . 'job_aggregator_run_sources';
		$this->assertSame( $table, $table_exists );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_key, status, created_count, updated_count, error_count
				FROM {$table}
				WHERE run_id = %d
				ORDER BY source_key ASC",
				$run_id
			),
			ARRAY_A
		);

		$this->assertCount( 3, $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( 'completed', (string) $row['status'] );
			$this->assertSame( 1, (int) $row['created_count'] );
			$this->assertSame( 0, (int) $row['updated_count'] );
			$this->assertSame( 0, (int) $row['error_count'] );
		}
	}
}
