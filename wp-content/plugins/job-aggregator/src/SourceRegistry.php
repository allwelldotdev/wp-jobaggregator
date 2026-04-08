<?php

namespace JobAggregator;

use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Sources\API\JoobleApiSource;
use JobAggregator\Sources\RSS\MyJobMagRssSource;
use JobAggregator\Sources\RSS\RssFeedSource;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;

/**
 * Builds and returns enabled source instances from configuration: `config/sources.php`, for batch processing.
 */
class SourceRegistry {

	private $config_path;
	private $logger;
	private $http;
	private $normalization_signals;
	private $sources_by_key;

	public function __construct(
		$config_path,
		Logger $logger,
		HttpClient $http,
		NormalizationSignalStore $normalization_signals,
	) {
		$this->config_path           = $config_path;
		$this->logger                = $logger;
		$this->http                  = $http;
		$this->normalization_signals = $normalization_signals;
		$this->sources_by_key        = null;
	}

	public function all() {
		return array_values( $this->build_sources() );
	}

	public function get( $source_key ) {
		$sources = $this->build_sources();

		return isset( $sources[ $source_key ] ) ? $sources[ $source_key ] : null;
	}

	private function build_sources() {
		if ( null !== $this->sources_by_key ) {
			return $this->sources_by_key;
		}

		if ( ! file_exists( $this->config_path ) ) {
			$this->sources_by_key = array();

			return $this->sources_by_key;
		}

		$config  = require $this->config_path;
		$sources = array();

		foreach (
			isset( $config['rss'] ) ? (array) $config['rss'] : array()
			as $source_config
		) {
			if ( empty( $source_config['enabled'] ) ) {
				continue;
			}

			$source = $this->build_rss_source( $source_config );
			$key    = $source->get_key();

			if ( '' === $key ) {
				continue;
			}

			$sources[ $key ] = $source;
		}

		foreach (
			isset( $config['apis'] ) ? (array) $config['apis'] : array()
			as $source_config
		) {
			if ( empty( $source_config['enabled'] ) ) {
				continue;
			}

			if ( empty( $source_config['driver'] ) ) {
				continue;
			}

			$source = $this->build_api_source( $source_config );
			if ( null === $source ) {
				continue;
			}

			$key = $source->get_key();

			if ( '' === $key ) {
				continue;
			}

			$sources[ $key ] = $source;
		}

		$this->sources_by_key = $sources;

		return $this->sources_by_key;
	}

	private function build_rss_source( array $source_config ) {
		$key    = isset( $source_config['key'] )
			? strtolower( trim( (string) $source_config['key'] ) )
			: '';
		$driver = isset( $source_config['driver'] )
			? strtolower( trim( (string) $source_config['driver'] ) )
			: '';

		if ( 'myjobmag' === $key || 'myjobmag' === $driver ) {
			return new MyJobMagRssSource(
				$source_config,
				$this->logger,
				$this->normalization_signals,
			);
		}

		return new RssFeedSource( $source_config, $this->logger );
	}

	private function build_api_source( array $source_config ) {
		$driver = isset( $source_config['driver'] )
			? strtolower( trim( (string) $source_config['driver'] ) )
			: '';

		if ( 'jooble' === $driver ) {
			return new JoobleApiSource(
				$source_config,
				$this->http,
				$this->logger,
			);
		}

		return null;
	}
}
