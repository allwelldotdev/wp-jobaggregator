<?php

namespace JobAggregator\Sources\API;

use JobAggregator\Batch\SourceBatchResult;
use JobAggregator\Jobs\JobData;
use RuntimeException;

/**
 * Fetches and normalizes Jooble API results into paginated JobData batches.
 */
class JoobleApiSource extends AbstractApiSource {
	public function initial_checkpoint() {
		$request = $this->request_payload();

		return array(
			'page' => isset( $request['page'] ) ? max( 1, (int) $request['page'] ) : 1,
		);
	}

	public function fetch_batch( array $checkpoint ) {
		$api_key  = $this->require_api_key();
		$endpoint = trailingslashit( untrailingslashit( $this->require_config( 'endpoint' ) ) ) . rawurlencode( $api_key );
		$payload  = $this->request_payload();

		$payload['page'] = isset( $checkpoint['page'] ) ? max( 1, (int) $checkpoint['page'] ) : 1;
		$defaults        = $this->defaults();
		$response        = $this->http->post_json( $endpoint, $payload );
		$data            = json_decode( $response['body'], true );

		if ( ! is_array( $data ) || ! isset( $data['jobs'] ) || ! is_array( $data['jobs'] ) ) {
			throw new RuntimeException( 'Unexpected Jooble response shape.' );
		}

		$jobs = array();

		foreach ( $data['jobs'] as $item ) {
			$jobs[] = JobData::from_array(
				array(
					'source_key'       => $this->get_key(),
					'external_id'      => isset( $item['id'] ) ? (string) $item['id'] : ( isset( $item['link'] ) ? (string) $item['link'] : '' ),
					'title'            => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'      => isset( $item['snippet'] ) ? (string) $item['snippet'] : '',
					'source_url'       => isset( $item['link'] ) ? (string) $item['link'] : '',
					'application_url'  => isset( $item['link'] ) ? (string) $item['link'] : '',
					'company_name'     => isset( $item['company'] ) ? (string) $item['company'] : '',
					'location'         => isset( $item['location'] ) ? (string) $item['location'] : ( isset( $defaults['location'] ) ? (string) $defaults['location'] : '' ),
					'employment_types' => isset( $defaults['employment_types'] ) ? (array) $defaults['employment_types'] : array(),
					'remote_position'  => ! empty( $defaults['remote_position'] ),
					'salary'           => isset( $item['salary'] ) ? (string) $item['salary'] : '',
					'salary_currency'  => isset( $defaults['salary_currency'] ) ? (string) $defaults['salary_currency'] : '',
					'salary_unit'      => isset( $defaults['salary_unit'] ) ? (string) $defaults['salary_unit'] : '',
					'published_at'     => isset( $item['updated'] ) ? (string) $item['updated'] : '',
					'expires_at'       => isset( $defaults['expires_at'] ) ? (string) $defaults['expires_at'] : '',
				)
			);
		}

		$page      = (int) $payload['page'];
		$per_page  = isset( $payload['ResultOnPage'] ) ? max( 1, (int) $payload['ResultOnPage'] ) : count( $jobs );
		$max_pages = isset( $this->config['max_pages'] ) ? max( 0, (int) $this->config['max_pages'] ) : 0;
		$has_more  = false;

		if ( ! empty( $jobs ) ) {
			if ( $max_pages > 0 && $page >= $max_pages ) {
				$has_more = false;
			} elseif ( isset( $data['totalCount'] ) && is_numeric( $data['totalCount'] ) ) {
				$total_count = (int) $data['totalCount'];
				$has_more    = ( $page * $per_page ) < $total_count;
			} else {
				$has_more = count( $jobs ) >= $per_page;
			}
		}

		return SourceBatchResult::success(
			$jobs,
			$has_more,
			array(
				'page' => $page + 1,
			)
		);
	}
}
