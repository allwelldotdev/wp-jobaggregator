<?php

namespace JobAggregator\Sources;

use JobAggregator\Jobs\JobData;
use RuntimeException;

class RssFeedSource extends AbstractSource {
    public function fetch_jobs() {
        include_once ABSPATH . WPINC . '/feed.php';

        $url  = $this->require_config( 'url' );
        $feed = fetch_feed( $url );

        if ( is_wp_error( $feed ) ) {
            throw new RuntimeException( $feed->get_error_message() );
        }

        $max_items = isset( $this->config['limit'] ) ? (int) $this->config['limit'] : 20;
        $items     = $feed->get_items( 0, $max_items );
        $defaults  = $this->defaults();
        $jobs      = [];

        // RSS feeds vary widely, so this source only maps the common fields and leaves the rest to per-source defaults.
        foreach ( $items as $item ) {
            $author = $item->get_author();
            $jobs[] = JobData::from_array(
                [
                    'source_key'       => $this->get_key(),
                    'external_id'      => (string) ( $item->get_id() ?: $item->get_link() ),
                    'title'            => (string) $item->get_title(),
                    'description'      => (string) ( $item->get_content() ?: $item->get_description() ),
                    'source_url'       => (string) $item->get_link(),
                    'application_url'  => (string) $item->get_link(),
                    'company_name'     => ! empty( $defaults['company_name'] ) ? $defaults['company_name'] : ( $author ? (string) $author->get_name() : '' ),
                    'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
                    'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
                    'location'         => isset( $defaults['location'] ) ? (string) $defaults['location'] : '',
                    'employment_types' => isset( $defaults['employment_types'] ) ? (array) $defaults['employment_types'] : [],
                    'remote_position'  => ! empty( $defaults['remote_position'] ),
                    'salary'           => isset( $defaults['salary'] ) ? (string) $defaults['salary'] : '',
                    'salary_currency'  => isset( $defaults['salary_currency'] ) ? (string) $defaults['salary_currency'] : '',
                    'salary_unit'      => isset( $defaults['salary_unit'] ) ? (string) $defaults['salary_unit'] : '',
                    'published_at'     => (string) $item->get_date( 'c' ),
                    'expires_at'       => isset( $defaults['expires_at'] ) ? (string) $defaults['expires_at'] : '',
                ]
            );
        }

        return $jobs;
    }
}
