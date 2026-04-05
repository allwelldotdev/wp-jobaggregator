<?php

namespace JobAggregator\Sources;

use JobAggregator\Support\Logger;
use RuntimeException;

abstract class AbstractSource implements SourceInterface {
	protected $config;
	protected $logger;

	public function __construct( array $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function get_key() {
		return isset( $this->config['key'] ) ? (string) $this->config['key'] : '';
	}

	public function get_label() {
		return isset( $this->config['label'] ) ? (string) $this->config['label'] : $this->get_key();
	}

	public function supports_pagination() {
		return false;
	}

	public function initial_checkpoint() {
		return array();
	}

	protected function defaults() {
		return isset( $this->config['defaults'] ) && is_array( $this->config['defaults'] ) ? $this->config['defaults'] : array();
	}

	protected function require_config( $key ) {
		if ( empty( $this->config[ $key ] ) ) {
			throw new RuntimeException( sprintf( 'Missing required config key "%s" for source "%s".', $key, $this->get_key() ) );
		}

		return $this->config[ $key ];
	}

	public function get_batch_size() {
		if ( isset( $this->config['batch_size'] ) ) {
			$batch_size = (int) $this->config['batch_size'];

			return $batch_size > 0 ? $batch_size : 5;
		}

		return 5;
	}

	public function get_max_retries() {
		if ( isset( $this->config['max_retries'] ) ) {
			$max_retries = (int) $this->config['max_retries'];

			return $max_retries >= 0 ? $max_retries : 3;
		}

		return 3;
	}

	public function get_retry_delay() {
		if ( isset( $this->config['retry_delay'] ) ) {
			$retry_delay = (int) $this->config['retry_delay'];

			return $retry_delay > 0 ? $retry_delay : 120;
		}

		return 120;
	}
}
