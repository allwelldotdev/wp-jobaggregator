<?php

namespace JobAggregator\Sources;

use JobAggregator\Batch\SourceBatchResult;
use JobAggregator\Jobs\JobData;
use JobAggregator\Support\HttpClient;
use RuntimeException;

class JoobleApiSource extends AbstractSource {
	private $http;

	public function __construct( array $config, HttpClient $http, $logger ) {
		parent::__construct( $config, $logger );
		$this->http = $http;
	}

	public function supports_pagination() {
		return true;
	}

	public function initial_checkpoint() {
		$request = isset( $this->config['request'] ) && is_array( $this->config['request'] ) ? $this->config['request'] : array();

		return array(
			'page' => isset( $request['page'] ) ? max( 1, (int) $request['page'] ) : 1,
		);
	}

	public function fetch_batch( array $checkpoint ) {
		$constant_name = $this->require_config( 'api_key_constant' );
		$api_key       = defined( $constant_name ) ? constant( $constant_name ) : '';

		if ( empty( $api_key ) ) {
			throw new RuntimeException( sprintf( 'Missing API key constant "%s".', $constant_name ) );
		}

		$endpoint        = trailingslashit( untrailingslashit( $this->require_config( 'endpoint' ) ) ) . rawurlencode( $api_key );
		$payload         = isset( $this->config['request'] ) && is_array( $this->config['request'] ) ? $this->config['request'] : array();
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
