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

    public function __construct( $config_path, Logger $logger, HttpClient $http ) {
        $this->config_path = $config_path;
        $this->logger      = $logger;
        $this->http        = $http;
    }

    public function all() {
        if ( ! file_exists( $this->config_path ) ) {
            return [];
        }

        $config  = require $this->config_path;
        $sources = [];

        foreach ( isset( $config['rss'] ) ? (array) $config['rss'] : [] as $source_config ) {
            if ( empty( $source_config['enabled'] ) ) {
                continue;
            }

            $sources[] = new RssFeedSource( $source_config, $this->logger );
        }

        foreach ( isset( $config['apis'] ) ? (array) $config['apis'] : [] as $source_config ) {
            if ( empty( $source_config['enabled'] ) ) {
                continue;
            }

            if ( empty( $source_config['driver'] ) ) {
                continue;
            }

            if ( 'jooble' === $source_config['driver'] ) {
                $sources[] = new JoobleApiSource( $source_config, $this->http, $this->logger );
            }
        }

        return $sources;
    }
}
