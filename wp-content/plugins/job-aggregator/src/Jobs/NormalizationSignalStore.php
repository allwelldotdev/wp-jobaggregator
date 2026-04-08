<?php

namespace JobAggregator\Jobs;

use wpdb;

/**
 * Stores and aggregates normalization mismatch signals for source mapping observability.
 */
class NormalizationSignalStore {
	private $wpdb;
	private $table;

	public function __construct( wpdb $database = null ) {
		global $wpdb;
		$this->wpdb  = $database ?: $wpdb;
		$this->table = $this->wpdb->prefix . 'job_aggregator_normalization_signals';
	}

	public function record( $source_key, $signal_type, $raw_value, $normalized_value = '', $example_external_id = '', $example_title = '' ) {
		$source_key          = $this->truncate( trim( (string) $source_key ), 191 );
		$signal_type         = $this->truncate( trim( (string) $signal_type ), 100 );
		$raw_value           = $this->truncate( trim( (string) $raw_value ), 191 );
		$normalized_value    = $this->truncate( trim( (string) $normalized_value ), 191 );
		$example_external_id = $this->truncate( trim( (string) $example_external_id ), 191 );
		$example_title       = $this->truncate( trim( (string) $example_title ), 191 );

		if ( '' === $source_key || '' === $signal_type || '' === $raw_value ) {
			return;
		}

		$now      = current_time( 'mysql' );
		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, seen_count FROM {$this->table}
				WHERE source_key = %s
				  AND signal_type = %s
				  AND raw_value = %s
				LIMIT 1",
				$source_key,
				$signal_type,
				$raw_value
			),
			ARRAY_A
		);

		if ( ! empty( $existing['id'] ) ) {
			$this->wpdb->update(
				$this->table,
				array(
					'normalized_value'    => $normalized_value,
					'seen_count'          => (int) $existing['seen_count'] + 1,
					'last_seen_at'        => $now,
					'example_external_id' => $example_external_id,
					'example_title'       => $example_title,
					'updated_at'          => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$this->wpdb->insert(
			$this->table,
			array(
				'source_key'          => $source_key,
				'signal_type'         => $signal_type,
				'raw_value'           => $raw_value,
				'normalized_value'    => $normalized_value,
				'seen_count'          => 1,
				'first_seen_at'       => $now,
				'last_seen_at'        => $now,
				'example_external_id' => $example_external_id,
				'example_title'       => $example_title,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function list_recent( $limit = 100 ) {
		$limit = max( 1, min( 500, (int) $limit ) );
		$sql   = $this->wpdb->prepare(
			"SELECT * FROM {$this->table}
			ORDER BY last_seen_at DESC, id DESC
			LIMIT %d",
			$limit
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	private function truncate( $value, $max_length ) {
		if ( strlen( $value ) <= $max_length ) {
			return $value;
		}

		return substr( $value, 0, $max_length );
	}
}
