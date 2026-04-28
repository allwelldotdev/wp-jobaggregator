<?php

namespace JobAggregator\Batch;

use wpdb;

/**
 * Manages batch run records, aggregate counters, and plugin table schema installation.
 */
class BatchRunManager {
	private $wpdb;
	private $runs_table;
	private $run_sources_table;
	private $normalization_signals_table;
	private $listing_origins_table;
	private $retention_selector;

	public function __construct( wpdb $database = null, RunRetentionSelector $retention_selector = null ) {
		global $wpdb;
		$this->wpdb                        = $database ?: $wpdb;
		$this->runs_table                  = $this->wpdb->prefix . 'job_aggregator_runs';
		$this->run_sources_table           = $this->wpdb->prefix . 'job_aggregator_run_sources';
		$this->normalization_signals_table = $this->wpdb->prefix . 'job_aggregator_normalization_signals';
		$this->listing_origins_table       = $this->wpdb->prefix . 'job_aggregator_listing_origins';
		$this->retention_selector          = $retention_selector ?: new RunRetentionSelector();
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
			archived_at datetime NULL DEFAULT NULL,
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
			KEY archived_at (archived_at),
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

		$normalization_signals_sql = "CREATE TABLE {$this->normalization_signals_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key varchar(191) NOT NULL,
            signal_type varchar(100) NOT NULL,
            raw_value varchar(191) NOT NULL,
            normalized_value varchar(191) NOT NULL DEFAULT '',
            seen_count int(11) unsigned NOT NULL DEFAULT 1,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            example_external_id varchar(191) NOT NULL DEFAULT '',
            example_title varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY signal_unique (source_key,signal_type,raw_value),
            KEY signal_type (signal_type),
            KEY last_seen_at (last_seen_at)
        ) {$charset_collate};";

		$listing_origins_sql = "CREATE TABLE {$this->listing_origins_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			source_key varchar(191) NOT NULL,
			group_key varchar(191) NOT NULL,
			title_norm varchar(191) NOT NULL,
			company_norm varchar(191) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_source_unique (post_id,source_key),
			KEY group_title_company (group_key,title_norm,company_norm)
		) {$charset_collate};";

		dbDelta( $runs_sql );
		dbDelta( $run_sources_sql );
		dbDelta( $normalization_signals_sql );
		dbDelta( $listing_origins_sql );
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
				'archived_at'       => null,
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
			"SELECT * FROM {$this->runs_table}
			WHERE id = %d
			  AND status <> %s
			LIMIT 1",
			(int) $run_id,
			'archived'
		);

		return $this->wpdb->get_row( $sql, ARRAY_A );
	}

	public function get_run_including_archived( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->runs_table} WHERE id = %d LIMIT 1",
			(int) $run_id
		);

		return $this->wpdb->get_row( $sql, ARRAY_A );
	}

	public function list_recent_runs( $limit = 20, $offset = 0 ) {
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$sql    = $this->wpdb->prepare(
			"SELECT * FROM {$this->runs_table}
			WHERE status <> %s
			ORDER BY id DESC
			LIMIT %d OFFSET %d",
			'archived',
			$limit,
			$offset
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	public function count_runs() {
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(1) FROM {$this->runs_table} WHERE status <> %s",
			'archived'
		);

		return (int) $this->wpdb->get_var( $sql );
	}

	public function list_follow_up_runs( $limit = 20 ) {
		$limit = max( 1, min( 200, (int) $limit ) );
		$sql   = $this->wpdb->prepare(
			"SELECT * FROM {$this->runs_table}
			WHERE status = %s
			  AND has_follow_up = 1
			ORDER BY last_activity_at DESC, id DESC
			LIMIT %d",
			'running',
			$limit
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
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
				'archived_at'      => null,
				'last_activity_at' => $now,
				'has_follow_up'    => 0,
				'updated_at'       => $now,
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function cleanup_history( array $settings, $archive_grace_days = 30 ) {
		$retention_days = isset( $settings['run_retention_days'] )
			? max( 1, (int) $settings['run_retention_days'] )
			: 62;
		$keep_min       = isset( $settings['run_keep_min'] )
			? max( 0, (int) $settings['run_keep_min'] )
			: 750;
		$grace_days     = max( 1, (int) $archive_grace_days );

		$terminal_rows = $this->list_terminal_runs_for_retention();
		$archive_ids   = $this->retention_selector->select_runs_to_archive(
			$terminal_rows,
			$retention_days,
			$keep_min,
			time()
		);

		$archived_count = $this->archive_runs( $archive_ids );

		$archived_rows = $this->list_archived_runs();
		$delete_ids    = $this->retention_selector->select_archived_runs_to_delete(
			$archived_rows,
			$grace_days,
			time()
		);

		$deleted_count = $this->delete_archived_runs( $delete_ids );

		return array(
			'archived' => $archived_count,
			'deleted'  => $deleted_count,
		);
	}

	private function list_terminal_runs_for_retention() {
		$sql = $this->wpdb->prepare(
			"SELECT id,
				COALESCE(completed_at, last_activity_at, started_at, created_at) AS terminal_at
			FROM {$this->runs_table}
			WHERE status IN (%s, %s, %s)
			ORDER BY id DESC",
			'completed',
			'partial',
			'failed'
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	private function list_archived_runs() {
		$sql = $this->wpdb->prepare(
			"SELECT id, archived_at
			FROM {$this->runs_table}
			WHERE status = %s
			  AND archived_at IS NOT NULL
			ORDER BY id DESC",
			'archived'
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	private function archive_runs( array $run_ids ) {
		$run_ids = array_values( array_filter( array_map( 'intval', $run_ids ) ) );
		if ( empty( $run_ids ) ) {
			return 0;
		}

		$now         = current_time( 'mysql' );
		$run_ids_sql = implode( ',', array_map( 'intval', $run_ids ) );

		$query = $this->wpdb->prepare(
			"UPDATE {$this->runs_table}
			SET status = %s,
				archived_at = %s,
				has_follow_up = 0,
				updated_at = %s
			WHERE id IN ({$run_ids_sql})
			  AND status IN ('completed','partial','failed')",
			'archived',
			$now,
			$now
		);

		$result = $this->wpdb->query( $query );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	private function delete_archived_runs( array $run_ids ) {
		$run_ids = array_values( array_filter( array_map( 'intval', $run_ids ) ) );
		if ( empty( $run_ids ) ) {
			return 0;
		}

		$run_ids_sql = implode( ',', array_map( 'intval', $run_ids ) );

		$source_delete_query = "
			DELETE FROM {$this->run_sources_table}
			WHERE run_id IN ({$run_ids_sql})";
		$this->wpdb->query( $source_delete_query );

		$run_delete_query = $this->wpdb->prepare(
			"DELETE FROM {$this->runs_table}
			WHERE id IN ({$run_ids_sql})
			  AND status = %s",
			'archived'
		);

		$result = $this->wpdb->query( $run_delete_query );

		return is_numeric( $result ) ? (int) $result : 0;
	}
}
