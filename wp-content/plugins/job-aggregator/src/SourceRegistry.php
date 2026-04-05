<?php

namespace JobAggregator;

use JobAggregator\Sources\JoobleApiSource;
use JobAggregator\Sources\RssFeedSource;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;

class SourceRegistry {
	private $config_path;
	private $logger;
	private $http;
	private $sources_by_key;

	public function __construct( $config_path, Logger $logger, HttpClient $http ) {
		$this->config_path    = $config_path;
		$this->logger         = $logger;
		$this->http           = $http;
		$this->sources_by_key = null;
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

		foreach ( isset( $config['rss'] ) ? (array) $config['rss'] : array() as $source_config ) {
			if ( empty( $source_config['enabled'] ) ) {
				continue;
			}

			$source = new RssFeedSource( $source_config, $this->logger );
			$key    = $source->get_key();

			if ( '' === $key ) {
				continue;
			}

			$sources[ $key ] = $source;
		}

		foreach ( isset( $config['apis'] ) ? (array) $config['apis'] : array() as $source_config ) {
			if ( empty( $source_config['enabled'] ) ) {
				continue;
			}

			if ( empty( $source_config['driver'] ) ) {
				continue;
			}

			if ( 'jooble' === $source_config['driver'] ) {
				$source = new JoobleApiSource( $source_config, $this->http, $this->logger );
				$key    = $source->get_key();

				if ( '' === $key ) {
					continue;
				}

				$sources[ $key ] = $source;
			}
		}

		$this->sources_by_key = $sources;

		return $this->sources_by_key;
	}
}
