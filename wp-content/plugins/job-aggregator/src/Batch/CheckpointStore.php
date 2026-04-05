<?php

namespace JobAggregator\Batch;

use wpdb;

class CheckpointStore {
	private $wpdb;
	private $run_sources_table;

	public function __construct( wpdb $database = null ) {
		global $wpdb;
		$this->wpdb              = $database ?: $wpdb;
		$this->run_sources_table = $this->wpdb->prefix . 'job_aggregator_run_sources';
	}

	public function next_due_source( $run_id ) {
		$now = current_time( 'mysql' );

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND has_more = 1
                AND status IN ('pending', 'running', 'waiting_retry')
                AND (next_retry_at IS NULL OR next_retry_at <= %s)
              ORDER BY FIELD(status, 'running', 'pending', 'waiting_retry'), id ASC
              LIMIT 1",
			(int) $run_id,
			$now
		);

		return $this->wpdb->get_row( $sql, ARRAY_A );
	}

	public function has_open_work( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(1) FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND has_more = 1
                AND status IN ('pending', 'running', 'waiting_retry')",
			(int) $run_id
		);

		return (int) $this->wpdb->get_var( $sql ) > 0;
	}

	public function has_due_work( $run_id ) {
		$now = current_time( 'mysql' );
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(1) FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND has_more = 1
                AND status IN ('pending', 'running', 'waiting_retry')
                AND (next_retry_at IS NULL OR next_retry_at <= %s)",
			(int) $run_id,
			$now
		);

		return (int) $this->wpdb->get_var( $sql ) > 0;
	}

	public function earliest_retry_timestamp( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT MIN(next_retry_at) FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND has_more = 1
                AND status = 'waiting_retry'
                AND next_retry_at IS NOT NULL",
			(int) $run_id
		);

		$next_retry_at = $this->wpdb->get_var( $sql );
		if ( empty( $next_retry_at ) ) {
			return 0;
		}

		$timestamp = strtotime( $next_retry_at );

		return false === $timestamp ? 0 : $timestamp;
	}

	public function count_processed_sources( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(1) FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND status IN ('completed', 'failed')",
			(int) $run_id
		);

		return (int) $this->wpdb->get_var( $sql );
	}

	public function has_failed_sources( $run_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(1) FROM {$this->run_sources_table}
              WHERE run_id = %d
                AND status = 'failed'",
			(int) $run_id
		);

		return (int) $this->wpdb->get_var( $sql ) > 0;
	}

	public function mark_source_running( array $source_state ) {
		$now = current_time( 'mysql' );

		$this->wpdb->update(
			$this->run_sources_table,
			array(
				'status'             => 'running',
				'last_run_at'        => $now,
				'attempt_count'      => (int) $source_state['attempt_count'] + 1,
				'last_error_message' => '',
				'updated_at'         => $now,
			),
			array( 'id' => (int) $source_state['id'] ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_source_success( array $source_state, SourceBatchResult $result, array $metrics ) {
		$now             = current_time( 'mysql' );
		$processed_items = (int) $source_state['processed_items'] + max( 0, (int) $result->fetched_count() );
		$status          = $result->has_more() ? 'running' : 'completed';
		$remaining_hint  = $result->has_more() ? 1 : 0;
		$created_count   = (int) $source_state['created_count'] + max( 0, (int) $metrics['created_count'] );
		$updated_count   = (int) $source_state['updated_count'] + max( 0, (int) $metrics['updated_count'] );
		$skipped_count   = (int) $source_state['skipped_count'] + max( 0, (int) $metrics['skipped_count'] );
		$error_count     = (int) $source_state['error_count'] + max( 0, (int) $metrics['error_count'] );

		$this->wpdb->update(
			$this->run_sources_table,
			array(
				'status'             => $status,
				'last_success_at'    => $now,
				'next_retry_at'      => null,
				'processed_items'    => $processed_items,
				'remaining_hint'     => $remaining_hint,
				'has_more'           => $result->has_more() ? 1 : 0,
				'checkpoint_payload' => wp_json_encode( $result->next_checkpoint() ),
				'created_count'      => $created_count,
				'updated_count'      => $updated_count,
				'skipped_count'      => $skipped_count,
				'error_count'        => $error_count,
				'updated_at'         => $now,
			),
			array( 'id' => (int) $source_state['id'] ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function mark_source_retry_or_failure( array $source_state, $message, $retry_after_seconds, $max_retries ) {
		$now         = current_time( 'mysql' );
		$retry_count = (int) $source_state['retry_count'] + 1;
		$error_count = (int) $source_state['error_count'] + 1;
		$max_retries = max( 0, (int) $max_retries );
		$retry_after = max( 1, (int) $retry_after_seconds );
		$next_retry  = gmdate( 'Y-m-d H:i:s', time() + $retry_after + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$is_terminal = $retry_count > $max_retries;
		$status      = $is_terminal ? 'failed' : 'waiting_retry';

		$this->wpdb->update(
			$this->run_sources_table,
			array(
				'status'             => $status,
				'last_error_at'      => $now,
				'last_error_message' => (string) $message,
				'retry_count'        => $retry_count,
				'next_retry_at'      => $is_terminal ? null : $next_retry,
				'error_count'        => $error_count,
				'has_more'           => $is_terminal ? 0 : 1,
				'updated_at'         => $now,
			),
			array( 'id' => (int) $source_state['id'] ),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return array(
			'status'      => $status,
			'retry_count' => $retry_count,
			'terminal'    => $is_terminal,
		);
	}

	public function decode_checkpoint( array $source_state ) {
		if ( empty( $source_state['checkpoint_payload'] ) ) {
			return array();
		}

		$checkpoint = json_decode( $source_state['checkpoint_payload'], true );

		return is_array( $checkpoint ) ? $checkpoint : array();
	}
}
