<?php

namespace JobAggregator\Batch;

use wpdb;

class BatchRunManager {
	private $wpdb;
	private $runs_table;
	private $run_sources_table;

	public function __construct( wpdb $database = null ) {
		global $wpdb;
		$this->wpdb              = $database ?: $wpdb;
		$this->runs_table        = $this->wpdb->prefix . 'job_aggregator_runs';
		$this->run_sources_table = $this->wpdb->prefix . 'job_aggregator_run_sources';
	}

	public function install_schema() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();

		$runs_sql = "CREATE TABLE {$this->runs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            status varchar(20) NOT NULL DEFAULT 'running',
            triggered_by varchar(20) NOT NULL DEFAULT 'cron',
            started_at datetime NOT NULL,
            last_activity_at datetime NOT NULL,
            completed_at datetime NULL DEFAULT NULL,
            total_sources int(11) unsigned NOT NULL DEFAULT 0,
            processed_sources int(11) unsigned NOT NULL DEFAULT 0,
            created_count int(11) unsigned NOT NULL DEFAULT 0,
            updated_count int(11) unsigned NOT NULL DEFAULT 0,
            skipped_count int(11) unsigned NOT NULL DEFAULT 0,
            error_count int(11) unsigned NOT NULL DEFAULT 0,
            retry_count int(11) unsigned NOT NULL DEFAULT 0,
            has_follow_up tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY last_activity_at (last_activity_at)
        ) {$charset_collate};";

		$run_sources_sql = "CREATE TABLE {$this->run_sources_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            source_key varchar(191) NOT NULL,
            source_label varchar(191) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            last_run_at datetime NULL DEFAULT NULL,
            last_success_at datetime NULL DEFAULT NULL,
            last_error_at datetime NULL DEFAULT NULL,
            last_error_message text NULL,
            attempt_count int(11) unsigned NOT NULL DEFAULT 0,
            retry_count int(11) unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL DEFAULT NULL,
            processed_items int(11) unsigned NOT NULL DEFAULT 0,
            remaining_hint int(11) unsigned NOT NULL DEFAULT 0,
            has_more tinyint(1) NOT NULL DEFAULT 1,
            checkpoint_payload longtext NULL,
            created_count int(11) unsigned NOT NULL DEFAULT 0,
            updated_count int(11) unsigned NOT NULL DEFAULT 0,
            skipped_count int(11) unsigned NOT NULL DEFAULT 0,
            error_count int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_source_unique (run_id,source_key),
            KEY run_id (run_id),
            KEY status_retry (status,next_retry_at)
        ) {$charset_collate};";

		dbDelta( $runs_sql );
		dbDelta( $run_sources_sql );
	}

	public function start_run( array $sources, $triggered_by = 'cron' ) {
		$now = current_time( 'mysql' );

		$this->wpdb->insert(
			$this->runs_table,
			array(
				'status'            => 'running',
				'triggered_by'      => (string) $triggered_by,
				'started_at'        => $now,
				'last_activity_at'  => $now,
				'completed_at'      => null,
				'total_sources'     => count( $sources ),
				'processed_sources' => 0,
				'created_count'     => 0,
				'updated_count'     => 0,
				'skipped_count'     => 0,
				'error_count'       => 0,
				'retry_count'       => 0,
				'has_follow_up'     => 0,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
			)
		);

		$run_id = (int) $this->wpdb->insert_id;
		if ( $run_id < 1 ) {
			return null;
		}

		foreach ( $sources as $source ) {
			$this->create_source_state( $run_id, $source->get_key(), $source->get_label(), $source->initial_checkpoint() );
		}

		return $this->get_run( $run_id );
	}

	public function create_source_state( $run_id, $source_key, $source_label, array $checkpoint ) {
		$now = current_time( 'mysql' );

		$this->wpdb->insert(
			$this->run_sources_table,
			array(
				'run_id'             => (int) $run_id,
				'source_key'         => (string) $source_key,
				'source_label'       => (string) $source_label,
				'status'             => 'pending',
				'last_run_at'        => null,
				'last_success_at'    => null,
				'last_error_at'      => null,
				'last_error_message' => '',
				'attempt_count'      => 0,
				'retry_count'        => 0,
				'next_retry_at'      => null,
				'processed_items'    => 0,
				'remaining_hint'     => 0,
				'has_more'           => 1,
				'checkpoint_payload' => wp_json_encode( $checkpoint ),
				'created_count'      => 0,
				'updated_count'      => 0,
				'skipped_count'      => 0,
				'error_count'        => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%d',
				'%d',
				'%d',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
			)
		);
	}

	public function get_active_run() {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->runs_table} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT 1",
			'queued',
			'running'
		);

		return $this->wpdb->get_row( $sql, ARRAY_A );
	}

	public function get_run( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->runs_table} WHERE id = %d LIMIT 1",
			(int) $run_id
		);

		return $this->wpdb->get_row( $sql, ARRAY_A );
	}

	public function update_activity( $run_id ) {
		$now = current_time( 'mysql' );

		$this->wpdb->update(
			$this->runs_table,
			array(
				'last_activity_at' => $now,
				'updated_at'       => $now,
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function update_processed_sources( $run_id, $processed_sources ) {
		$now = current_time( 'mysql' );

		$this->wpdb->update(
			$this->runs_table,
			array(
				'processed_sources' => max( 0, (int) $processed_sources ),
				'last_activity_at'  => $now,
				'updated_at'        => $now,
			),
			array( 'id' => (int) $run_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function increment_counters( $run_id, array $counters ) {
		$created = isset( $counters['created_count'] ) ? max( 0, (int) $counters['created_count'] ) : 0;
		$updated = isset( $counters['updated_count'] ) ? max( 0, (int) $counters['updated_count'] ) : 0;
		$skipped = isset( $counters['skipped_count'] ) ? max( 0, (int) $counters['skipped_count'] ) : 0;
		$errors  = isset( $counters['error_count'] ) ? max( 0, (int) $counters['error_count'] ) : 0;
		$retries = isset( $counters['retry_count'] ) ? max( 0, (int) $counters['retry_count'] ) : 0;
		$now     = current_time( 'mysql' );

		$query = $this->wpdb->prepare(
			"UPDATE {$this->runs_table}
                SET created_count = created_count + %d,
                    updated_count = updated_count + %d,
                    skipped_count = skipped_count + %d,
                    error_count = error_count + %d,
                    retry_count = retry_count + %d,
                    last_activity_at = %s,
                    updated_at = %s
              WHERE id = %d",
			$created,
			$updated,
			$skipped,
			$errors,
			$retries,
			$now,
			$now,
			(int) $run_id
		);

		$this->wpdb->query( $query );
	}

	public function set_has_follow_up( $run_id, $has_follow_up ) {
		$now = current_time( 'mysql' );

		$this->wpdb->update(
			$this->runs_table,
			array(
				'has_follow_up' => $has_follow_up ? 1 : 0,
				'updated_at'    => $now,
			),
			array( 'id' => (int) $run_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function mark_run_completed( $run_id, $status = 'completed' ) {
		$now = current_time( 'mysql' );

		$this->wpdb->update(
			$this->runs_table,
			array(
				'status'           => (string) $status,
				'completed_at'     => $now,
				'last_activity_at' => $now,
				'has_follow_up'    => 0,
				'updated_at'       => $now,
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}
}
