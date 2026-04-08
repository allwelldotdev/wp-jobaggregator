<?php

namespace JobAggregator\Sources\API;

use JobAggregator\Sources\AbstractSource;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;
use RuntimeException;

/**
 * Provides shared API source behavior, including request payload and API key resolution.
 */
abstract class AbstractApiSource extends AbstractSource {
	protected $http;

	public function __construct( array $config, HttpClient $http, Logger $logger ) {
		parent::__construct( $config, $logger );
		$this->http = $http;
	}

	public function supports_pagination() {
		return true;
	}

	protected function require_api_key() {
		$constant_name = $this->require_config( 'api_key_constant' );
		$api_key       = defined( $constant_name ) ? constant( $constant_name ) : '';

		if ( '' === trim( (string) $api_key ) ) {
			throw new RuntimeException( sprintf( 'Missing API key constant "%s".', $constant_name ) );
		}

		return (string) $api_key;
	}

	protected function request_payload() {
		return isset( $this->config['request'] ) && is_array( $this->config['request'] ) ? $this->config['request'] : array();
	}
}
