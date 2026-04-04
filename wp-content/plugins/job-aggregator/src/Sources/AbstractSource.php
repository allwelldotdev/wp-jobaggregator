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

    protected function defaults() {
        return isset( $this->config['defaults'] ) && is_array( $this->config['defaults'] ) ? $this->config['defaults'] : [];
    }

    protected function require_config( $key ) {
        if ( empty( $this->config[ $key ] ) ) {
            throw new RuntimeException( sprintf( 'Missing required config key "%s" for source "%s".', $key, $this->get_key() ) );
        }

        return $this->config[ $key ];
    }
}
