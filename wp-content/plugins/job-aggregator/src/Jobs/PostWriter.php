<?php

namespace JobAggregator\Jobs;

use JobAggregator\Support\Logger;
use RuntimeException;

/**
 * Creates or updates WP Job Manager listings and writes related taxonomy/meta fields.
 */
class PostWriter {
	private $duplicate_checker;
	private $logger;

	public function __construct( DuplicateChecker $duplicate_checker, Logger $logger ) {
		$this->duplicate_checker = $duplicate_checker;
		$this->logger            = $logger;
	}

	public function upsert( JobData $job ) {
		$result = $this->upsert_with_result( $job );

		return (int) $result['post_id'];
	}

	public function upsert_with_result( JobData $job ) {
		$existing_id         = $this->duplicate_checker->find_existing_id( $job );
		$identity_key        = $this->duplicate_checker->build_identity_key( $job );
		$content_fingerprint = $this->build_content_fingerprint( $job );
		$postarr             = array(
			'post_type'    => 'job_listing',
			'post_status'  => apply_filters( 'job_aggregator_import_post_status', 'publish', $job ),
			'post_title'   => wp_strip_all_tags( $job->title ),
			'post_content' => $job->description,
			'post_author'  => (int) apply_filters( 'job_aggregator_import_post_author', 1, $job ),
		);

		// When a source includes a publish date, preserve it so older jobs do not all appear newly posted.
		if ( ! empty( $job->published_at ) ) {
			$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', strtotime( $job->published_at ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $job->published_at ) );
		}

		if ( $existing_id ) {
			$existing_fingerprint = (string) get_post_meta(
				$existing_id,
				DuplicateChecker::META_CONTENT_FINGERPRINT,
				true
			);

			if ( '' !== $existing_fingerprint && hash_equals( $existing_fingerprint, $content_fingerprint ) ) {
				$this->logger->info(
					'Skipped unchanged job listing.',
					array(
						'post_id'    => $existing_id,
						'source_key' => $job->source_key,
						'title'      => $job->title,
					)
				);

				return array(
					'post_id' => (int) $existing_id,
					'action'  => 'skipped',
				);
			}
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}

		$this->persist_meta(
			$post_id,
			array(
				'_job_location'                            => $job->location,
				'_application'                             => $this->resolve_application_value( $job ),
				'_company_name'                            => $job->company_name,
				'_company_website'                         => $job->company_website,
				'_company_tagline'                         => $job->company_tagline,
				'_filled'                                  => 0,
				'_featured'                                => 0,
				'_remote_position'                         => $job->remote_position ? 1 : 0,
				'_job_salary'                              => $job->salary,
				'_job_salary_currency'                     => $job->salary_currency,
				'_job_salary_unit'                         => $job->salary_unit,
				'_job_expires'                             => $job->expires_at,
				'_job_aggregator_source_key'               => $job->source_key,
				'_job_aggregator_external_id'              => $job->external_id,
				DuplicateChecker::META_IDENTITY_KEY        => $identity_key,
				DuplicateChecker::META_CONTENT_FINGERPRINT => $content_fingerprint,
				'_job_aggregator_source_url'               => $job->source_url,
				'_job_aggregator_imported_at'              => current_time( 'mysql' ),
			)
		);
		$this->persist_company_logo( $post_id, $job );

		if ( taxonomy_exists( 'job_listing_type' ) && ! empty( $job->employment_types ) ) {
			wp_set_object_terms( $post_id, $this->ensure_named_terms( 'job_listing_type', $job->employment_types ), 'job_listing_type', false );
		}

		if ( taxonomy_exists( 'job_listing_category' ) && ! empty( $job->job_categories ) ) {
			$category_ids = $this->ensure_category_terms( (array) $job->job_categories );
			if ( ! empty( $category_ids ) ) {
				wp_set_object_terms( $post_id, $category_ids, 'job_listing_category', false );
			}
		}

		$this->logger->info(
			$existing_id ? 'Updated job listing.' : 'Created job listing.',
			array(
				'post_id'    => $post_id,
				'source_key' => $job->source_key,
				'title'      => $job->title,
			)
		);

		return array(
			'post_id' => (int) $post_id,
			'action'  => $existing_id ? 'updated' : 'created',
		);
	}

	public function build_content_fingerprint( JobData $job ) {
		$employment_types = $this->normalize_list( (array) $job->employment_types );
		$job_categories   = $this->normalize_list( (array) $job->job_categories );
		$logo_reference   = $this->resolve_logo_reference( $job );

		$fingerprint_payload = array(
			'post_title'        => wp_strip_all_tags( $job->title ),
			'post_content'      => (string) $job->description,
			'source_key'        => (string) $job->source_key,
			'external_id'       => (string) $job->external_id,
			'source_url'        => (string) $job->source_url,
			'application_value' => (string) $this->resolve_application_value( $job ),
			'company_name'      => (string) $job->company_name,
			'company_website'   => (string) $job->company_website,
			'company_tagline'   => (string) $job->company_tagline,
			'location'          => (string) $job->location,
			'employment_types'  => $employment_types,
			'job_categories'    => $job_categories,
			'remote_position'   => $job->remote_position ? 1 : 0,
			'salary'            => (string) $job->salary,
			'salary_currency'   => (string) $job->salary_currency,
			'salary_unit'       => (string) $job->salary_unit,
			'published_at'      => (string) $job->published_at,
			'expires_at'        => (string) $job->expires_at,
			'company_logo'      => $logo_reference,
		);

		return hash( 'sha256', wp_json_encode( $fingerprint_payload ) );
	}

	private function persist_meta( $post_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			if ( '' === $value || array() === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}
	}

	private function resolve_application_value( JobData $job ) {
		if ( ! empty( $job->application_url ) ) {
			return $job->application_url;
		}

		if ( ! empty( $job->application_email ) ) {
			return $job->application_email;
		}

		return $job->source_url;
	}

	private function persist_company_logo( $post_id, JobData $job ) {
		$attachment_id = is_numeric( $job->company_logo_id ) ? absint( $job->company_logo_id ) : 0;
		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );

			return;
		}

		$logo_url = '';
		if ( is_string( $job->company_logo_url ) ) {
			$logo_url = trim( $job->company_logo_url );
		}

		if ( '' === $logo_url ) {
			return;
		}

		$sanitized_logo_url = esc_url_raw( $logo_url );
		if ( '' === $sanitized_logo_url ) {
			return;
		}

		update_post_meta( $post_id, '_company_logo', $sanitized_logo_url );
	}

	private function resolve_logo_reference( JobData $job ) {
		$attachment_id = is_numeric( $job->company_logo_id ) ? absint( $job->company_logo_id ) : 0;
		if ( $attachment_id > 0 ) {
			return 'attachment:' . $attachment_id;
		}

		$logo_url = '';
		if ( is_string( $job->company_logo_url ) ) {
			$logo_url = trim( $job->company_logo_url );
		}

		if ( '' === $logo_url ) {
			return '';
		}

		return (string) esc_url_raw( $logo_url );
	}

	private function normalize_list( array $values ) {
		$normalized = array();

		foreach ( $values as $value ) {
			$text = trim( (string) $value );
			if ( '' === $text ) {
				continue;
			}

			$normalized[] = $text;
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	private function ensure_named_terms( $taxonomy, array $terms ) {
		$term_ids = array();

		foreach ( $terms as $term_name ) {
			$term_name = trim( (string) $term_name );
			if ( '' === $term_name ) {
				continue;
			}

			$slug = sanitize_title( $term_name );
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $term ) {
				$created = wp_insert_term( $term_name, $taxonomy, array( 'slug' => $slug ) );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		return $term_ids;
	}

	private function ensure_category_terms( array $category_slugs ) {
		$term_ids = array();

		foreach ( $category_slugs as $category_slug ) {
			$category_slug = sanitize_title( (string) $category_slug );
			if ( '' === $category_slug ) {
				continue;
			}

			$term = get_term_by( 'slug', $category_slug, 'job_listing_category' );

			if ( ! $term ) {
				$term_name = 'other-automated' === $category_slug
					? 'Other (automated)'
					: ucwords( str_replace( '-', ' ', $category_slug ) );

				$created = wp_insert_term(
					$term_name,
					'job_listing_category',
					array(
						'slug' => $category_slug,
					)
				);

				if ( is_wp_error( $created ) ) {
					if ( 'term_exists' === $created->get_error_code() ) {
						$existing_id = (int) $created->get_error_data();
						if ( $existing_id > 0 ) {
							$term_ids[] = $existing_id;
						}
					}

					continue;
				}

				$term_ids[] = (int) $created['term_id'];

				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		return array_values( array_unique( $term_ids ) );
	}
}
