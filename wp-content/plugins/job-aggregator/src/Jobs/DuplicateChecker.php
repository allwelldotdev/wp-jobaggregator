<?php

namespace JobAggregator\Jobs;

class DuplicateChecker {
    public function find_existing_id( JobData $job ) {
        $meta_query = [];

        if ( ! empty( $job->external_id ) ) {
            $meta_query[] = [
                'key'   => '_job_aggregator_external_id',
                'value' => $job->external_id,
            ];
        }

        $source_hash = $this->build_source_hash( $job );
        if ( ! empty( $source_hash ) ) {
            $meta_query[] = [
                'key'   => '_job_aggregator_source_hash',
                'value' => $source_hash,
            ];
        }

        if ( empty( $meta_query ) ) {
            return 0;
        }

        if ( count( $meta_query ) > 1 ) {
            $meta_query['relation'] = 'OR';
        }

        $query = new \WP_Query(
            [
                'post_type'      => 'job_listing',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => $meta_query,
            ]
        );

        return empty( $query->posts ) ? 0 : (int) $query->posts[0];
    }

    public function build_source_hash( JobData $job ) {
        $parts = array_filter(
            [
                $job->source_key,
                $job->external_id,
                $job->source_url,
                $job->title,
                $job->company_name,
            ]
        );

        if ( empty( $parts ) ) {
            return '';
        }

        return md5( implode( '|', $parts ) );
    }
}
