<?php

namespace JobAggregator\Jobs;

use JobAggregator\Support\Logger;
use RuntimeException;

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
		$existing_id = $this->duplicate_checker->find_existing_id( $job );
		$postarr     = array(
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
				'_job_location'               => $job->location,
				'_application'                => $this->resolve_application_value( $job ),
				'_company_name'               => $job->company_name,
				'_company_website'            => $job->company_website,
				'_company_tagline'            => $job->company_tagline,
				'_filled'                     => 0,
				'_featured'                   => 0,
				'_remote_position'            => $job->remote_position ? 1 : 0,
				'_job_salary'                 => $job->salary,
				'_job_salary_currency'        => $job->salary_currency,
				'_job_salary_unit'            => $job->salary_unit,
				'_job_expires'                => $job->expires_at,
				'_job_aggregator_source_key'  => $job->source_key,
				'_job_aggregator_external_id' => $job->external_id,
				'_job_aggregator_source_hash' => $this->duplicate_checker->build_source_hash( $job ),
				'_job_aggregator_source_url'  => $job->source_url,
				'_job_aggregator_imported_at' => current_time( 'mysql' ),
			)
		);

		if ( taxonomy_exists( 'job_listing_type' ) && ! empty( $job->employment_types ) ) {
			wp_set_object_terms( $post_id, $this->ensure_terms( 'job_listing_type', $job->employment_types ), 'job_listing_type', false );
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

	private function ensure_terms( $taxonomy, array $terms ) {
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
}
