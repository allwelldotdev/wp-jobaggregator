<?php

namespace JobAggregator;

use JobAggregator\Jobs\NormalizationSignalStore;
use JobAggregator\Sources\API\JoobleApiSource;
use JobAggregator\Sources\RSS\HotNigerianJobsRssSource;
use JobAggregator\Sources\RSS\MyJobMagRssSource;
use JobAggregator\Sources\RSS\RemoteOkRssSource;
use JobAggregator\Sources\RSS\RssFeedSource;
use JobAggregator\Sources\RSS\WeWorkRemotelyRssSource;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;
use JobAggregator\Support\Settings;

/**
 * Builds source instances from configuration and resolves runtime-enabled sources from settings overrides.
 */
class SourceRegistry {

	private $config_path;
	private $logger;
	private $http;
	private $normalization_signals;
	private $configured_sources_by_key;
	private $configured_source_order;

	public function __construct(
		$config_path,
		Logger $logger,
		HttpClient $http,
		NormalizationSignalStore $normalization_signals,
	) {
		$this->config_path               = $config_path;
		$this->logger                    = $logger;
		$this->http                      = $http;
		$this->normalization_signals     = $normalization_signals;
		$this->configured_sources_by_key = null;
		$this->configured_source_order   = array();
	}

	public function all() {
		return array_values( $this->build_enabled_sources() );
	}

	public function get( $source_key ) {
		$source_key = sanitize_key( (string) $source_key );
		$sources    = $this->build_configured_sources();

		if ( '' === $source_key || ! isset( $sources[ $source_key ] ) ) {
			return null;
		}

		return $sources[ $source_key ]['source'];
	}

	public function configured() {
		$configured_sources = $this->build_configured_sources();
		$effective_states   = $this->effective_source_states();
		$rows               = array();

		foreach ( $this->configured_source_order as $source_key ) {
			if ( ! isset( $configured_sources[ $source_key ] ) ) {
				continue;
			}

			$entry = $configured_sources[ $source_key ];

			$rows[] = array(
				'key'               => $source_key,
				'label'             => $entry['source']->get_label(),
				'type'              => $entry['type'],
				'config_enabled'    => ! empty( $entry['config_enabled'] ),
				'effective_enabled' => ! empty( $effective_states[ $source_key ] ),
				'source'            => $entry['source'],
			);
		}

		return $rows;
	}

	public function configured_source_states() {
		$configured_sources = $this->build_configured_sources();
		$states             = array();

		foreach ( $configured_sources as $source_key => $entry ) {
			$states[ $source_key ] = ! empty( $entry['config_enabled'] ) ? 1 : 0;
		}

		return $states;
	}

	private function build_configured_sources() {
		if ( null !== $this->configured_sources_by_key ) {
			return $this->configured_sources_by_key;
		}

		$this->configured_sources_by_key = array();
		$this->configured_source_order   = array();

		if ( ! file_exists( $this->config_path ) ) {
			return $this->configured_sources_by_key;
		}

		$config = require $this->config_path;
		$groups = array(
			'rss'  => isset( $config['rss'] ) ? (array) $config['rss'] : array(),
			'apis' => isset( $config['apis'] ) ? (array) $config['apis'] : array(),
		);

		foreach ( $groups as $group_key => $source_configs ) {
			foreach ( $source_configs as $source_config ) {
				$source = 'rss' === $group_key
					? $this->build_rss_source( (array) $source_config )
					: $this->build_api_source( (array) $source_config );
				if ( null === $source ) {
					continue;
				}

				$source_key = sanitize_key( (string) $source->get_key() );
				if ( '' === $source_key ) {
					continue;
				}

				$config_enabled = ! empty( $source_config['enabled'] );

				$this->configured_sources_by_key[ $source_key ] = array(
					'source'         => $source,
					'type'           => 'rss' === $group_key ? 'rss' : 'api',
					'config_enabled' => $config_enabled,
				);
				$this->configured_source_order[]                = $source_key;
			}
		}

		$this->configured_source_order = array_values(
			array_unique( $this->configured_source_order )
		);

		return $this->configured_sources_by_key;
	}

	private function build_enabled_sources() {
		$configured_sources = $this->build_configured_sources();
		$effective_states   = $this->effective_source_states();
		$enabled            = array();

		foreach ( $configured_sources as $source_key => $entry ) {
			if ( empty( $effective_states[ $source_key ] ) ) {
				continue;
			}

			$enabled[ $source_key ] = $entry['source'];
		}

		return $enabled;
	}

	private function effective_source_states() {
		$configured_states = $this->configured_source_states();
		$settings          = Settings::all();
		$overrides         = isset( $settings['source_states'] ) && is_array( $settings['source_states'] )
			? $settings['source_states']
			: array();
		$effective         = array();

		foreach ( $configured_states as $source_key => $config_enabled ) {
			$effective[ $source_key ] = ! empty( $config_enabled ) && ! empty( $overrides[ $source_key ] ) ? 1 : 0;
		}

		return $effective;
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

		if ( 'remoteok' === $key || 'remoteok' === $driver ) {
			return new RemoteOkRssSource(
				$source_config,
				$this->logger,
			);
		}

		if ( 'weworkremotely' === $key || 'weworkremotely' === $driver ) {
			return new WeWorkRemotelyRssSource(
				$source_config,
				$this->logger,
				$this->normalization_signals,
			);
		}

		if ( 'hotnigerianjobs' === $key || 'hotnigerianjobs' === $driver ) {
			return new HotNigerianJobsRssSource(
				$source_config,
				$this->logger,
			);
		}

		return new RssFeedSource( $source_config, $this->logger );
	}

	private function build_api_source( array $source_config ) {
		if ( empty( $source_config['driver'] ) ) {
			return null;
		}

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
