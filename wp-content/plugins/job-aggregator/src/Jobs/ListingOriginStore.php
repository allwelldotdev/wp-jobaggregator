<?php

namespace JobAggregator\Jobs;

use wpdb;

/**
 * Persists source-to-listing origins used for cross-source deduplication lookups.
 */
class ListingOriginStore {
	private $wpdb;
	private $origins_table;

	public function __construct( wpdb $database = null ) {
		global $wpdb;
		$this->wpdb          = $database ?: $wpdb;
		$this->origins_table = $this->wpdb->prefix . 'job_aggregator_listing_origins';
	}

	public function upsert_origin( $post_id, $source_key, $group_key, $title_norm, $company_norm ) {
		$post_id      = (int) $post_id;
		$source_key   = sanitize_key( (string) $source_key );
		$group_key    = sanitize_key( (string) $group_key );
		$title_norm   = trim( (string) $title_norm );
		$company_norm = trim( (string) $company_norm );

		if ( $post_id < 1 || '' === $source_key || '' === $group_key || '' === $title_norm || '' === $company_norm ) {
			return;
		}

		$now         = current_time( 'mysql' );
		$existing_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->origins_table}
				WHERE post_id = %d
				  AND source_key = %s
				LIMIT 1",
				$post_id,
				$source_key
			)
		);

		if ( $existing_id > 0 ) {
			$this->wpdb->update(
				$this->origins_table,
				array(
					'group_key'    => $group_key,
					'title_norm'   => $title_norm,
					'company_norm' => $company_norm,
					'updated_at'   => $now,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$this->wpdb->insert(
			$this->origins_table,
			array(
				'post_id'      => $post_id,
				'source_key'   => $source_key,
				'group_key'    => $group_key,
				'title_norm'   => $title_norm,
				'company_norm' => $company_norm,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function find_matching_post_id( $group_key, $title_norm, $company_norm, array $source_keys, $exclude_source_key = '' ) {
		$group_key          = sanitize_key( (string) $group_key );
		$title_norm         = trim( (string) $title_norm );
		$company_norm       = trim( (string) $company_norm );
		$exclude_source_key = sanitize_key( (string) $exclude_source_key );
		$allowed_sources    = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $source_key ) {
							return sanitize_key( (string) $source_key );
						},
						$source_keys
					)
				)
			)
		);

		if ( '' === $group_key || '' === $title_norm || '' === $company_norm || empty( $allowed_sources ) ) {
			return 0;
		}

		$in_placeholders = implode( ',', array_fill( 0, count( $allowed_sources ), '%s' ) );
		$sql             = "SELECT lo.post_id
			FROM {$this->origins_table} lo
			INNER JOIN {$this->wpdb->posts} p ON p.ID = lo.post_id
			WHERE lo.group_key = %s
			  AND lo.title_norm = %s
			  AND lo.company_norm = %s
			  AND lo.source_key IN ({$in_placeholders})
			  AND p.post_type = 'job_listing'
			  AND p.post_status <> 'trash'";
		$params          = array_merge( array( $group_key, $title_norm, $company_norm ), $allowed_sources );

		if ( '' !== $exclude_source_key ) {
			$sql     .= ' AND lo.source_key <> %s';
			$params[] = $exclude_source_key;
		}

		$sql    .= ' ORDER BY lo.updated_at DESC, lo.id DESC LIMIT 1';
		$query   = $this->wpdb->prepare( $sql, $params );
		$post_id = (int) $this->wpdb->get_var( $query );

		return $post_id > 0 ? $post_id : 0;
	}

	public function purge_orphaned_rows() {
		$query = "DELETE lo
			FROM {$this->origins_table} lo
			LEFT JOIN {$this->wpdb->posts} p ON p.ID = lo.post_id
			WHERE p.ID IS NULL";

		$this->wpdb->query( $query );
	}
}
