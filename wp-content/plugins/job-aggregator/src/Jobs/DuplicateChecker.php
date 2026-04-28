<?php

namespace JobAggregator\Jobs;

/**
 * Detects existing listings by a stable identity key so imports update instead of duplicating.
 */
class DuplicateChecker {
	const META_SOURCE_KEY          = '_job_aggregator_source_key';
	const META_EXTERNAL_ID         = '_job_aggregator_external_id';
	const META_SOURCE_URL          = '_job_aggregator_source_url';
	const META_IDENTITY_KEY        = '_job_aggregator_identity_key';
	const META_CONTENT_FINGERPRINT = '_job_aggregator_content_fingerprint';
	private $listing_origins;
	private $cross_source_groups;

	public function __construct( ListingOriginStore $listing_origins = null, array $cross_source_groups = array() ) {
		$this->listing_origins     = $listing_origins;
		$this->cross_source_groups = $cross_source_groups;
	}

	public function find_existing_id( JobData $job ) {
		$identity_key = $this->build_identity_key( $job );
		$meta_query   = array();

		if ( '' !== $identity_key ) {
			$meta_query[] = array(
				'key'   => self::META_IDENTITY_KEY,
				'value' => $identity_key,
			);
		}

		$legacy_meta_query = $this->legacy_meta_query( $job );
		if ( ! empty( $legacy_meta_query ) ) {
			$meta_query[] = $legacy_meta_query;
		}

		if ( empty( $meta_query ) ) {
			return 0;
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query = array_merge(
				array( 'relation' => 'OR' ),
				$meta_query
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'job_listing',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Stable identity matching requires post meta lookup.
				'meta_query'     => $meta_query,
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}

	public function build_identity_key( JobData $job ) {
		$source_key = sanitize_key( (string) $job->source_key );
		if ( '' === $source_key ) {
			return '';
		}

		$external_id = trim( (string) $job->external_id );
		if ( '' !== $external_id ) {
			return $source_key . '|external_id|' . $external_id;
		}

		$normalized_source_url = $this->normalize_source_url( $job->source_url );
		if ( '' === $normalized_source_url ) {
			return '';
		}

		return $source_key . '|source_url|' . $normalized_source_url;
	}

	public function find_cross_source_duplicate_id( JobData $job ) {
		$group_context = $this->resolve_group_context( $job );
		if ( empty( $group_context ) ) {
			return 0;
		}

		$title_norm   = $this->normalize_match_text( $job->title );
		$company_norm = $this->normalize_match_text( $job->company_name );
		if ( '' === $title_norm || '' === $company_norm ) {
			return 0;
		}

		return $this->origin_store()->find_matching_post_id(
			$group_context['group_key'],
			$title_norm,
			$company_norm,
			$group_context['source_keys'],
			(string) $job->source_key
		);
	}

	public function sync_cross_source_origin( JobData $job, $post_id ) {
		$post_id       = (int) $post_id;
		$group_context = $this->resolve_group_context( $job );
		if ( $post_id < 1 || empty( $group_context ) ) {
			return;
		}

		$title_norm   = $this->normalize_match_text( $job->title );
		$company_norm = $this->normalize_match_text( $job->company_name );
		if ( '' === $title_norm || '' === $company_norm ) {
			return;
		}

		$this->origin_store()->upsert_origin(
			$post_id,
			(string) $job->source_key,
			$group_context['group_key'],
			$title_norm,
			$company_norm
		);
	}

	public function normalize_match_text( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtolower( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return is_string( $value ) ? trim( $value ) : '';
	}

	public function normalize_source_url( $source_url ) {
		$normalized_url = esc_url_raw( trim( (string) $source_url ) );
		if ( '' === $normalized_url ) {
			return '';
		}

		return strtolower( untrailingslashit( $normalized_url ) );
	}

	private function legacy_meta_query( JobData $job ) {
		$source_key = sanitize_key( (string) $job->source_key );
		if ( '' === $source_key ) {
			return array();
		}

		$external_id = trim( (string) $job->external_id );
		if ( '' !== $external_id ) {
			return array(
				'relation' => 'AND',
				array(
					'key'   => self::META_SOURCE_KEY,
					'value' => $source_key,
				),
				array(
					'key'   => self::META_EXTERNAL_ID,
					'value' => $external_id,
				),
			);
		}

		$normalized_source_url = $this->normalize_source_url( $job->source_url );
		$raw_source_url        = trim( (string) $job->source_url );

		if ( '' === $normalized_source_url && '' === $raw_source_url ) {
			return array();
		}

		$url_meta_options = array(
			'relation' => 'OR',
		);

		if ( '' !== $raw_source_url ) {
			$url_meta_options[] = array(
				'key'   => self::META_SOURCE_URL,
				'value' => $raw_source_url,
			);
		}

		if ( '' !== $normalized_source_url && $normalized_source_url !== $raw_source_url ) {
			$url_meta_options[] = array(
				'key'   => self::META_SOURCE_URL,
				'value' => $normalized_source_url,
			);
		}

		if ( count( $url_meta_options ) < 2 ) {
			return array();
		}

		return array(
			'relation' => 'AND',
			array(
				'key'   => self::META_SOURCE_KEY,
				'value' => $source_key,
			),
			$url_meta_options,
		);
	}

	private function resolve_group_context( JobData $job ) {
		$source_key = sanitize_key( (string) $job->source_key );
		if ( '' === $source_key || empty( $this->cross_source_groups ) ) {
			return array();
		}

		foreach ( $this->cross_source_groups as $group_key => $group ) {
			$candidate_group = sanitize_key( (string) $group_key );
			if ( '' === $candidate_group ) {
				continue;
			}

			$group_sources = isset( $group['source_keys'] ) && is_array( $group['source_keys'] )
				? array_values(
					array_filter(
						array_map(
							static function ( $group_source_key ) {
								return sanitize_key( (string) $group_source_key );
							},
							$group['source_keys']
						)
					)
				)
				: array();
			if ( empty( $group_sources ) || ! in_array( $source_key, $group_sources, true ) ) {
				continue;
			}

			return array(
				'group_key'   => $candidate_group,
				'source_keys' => array_values( array_unique( $group_sources ) ),
			);
		}

		return array();
	}

	private function origin_store() {
		if ( ! $this->listing_origins instanceof ListingOriginStore ) {
			$this->listing_origins = new ListingOriginStore();
		}

		return $this->listing_origins;
	}
}
