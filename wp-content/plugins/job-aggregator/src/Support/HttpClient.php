<?php

namespace JobAggregator\Support;

use RuntimeException;

class HttpClient {
    public function get( $url, array $args = [] ) {
        $response = wp_remote_get(
            $url,
            array_merge(
                [
                    'timeout' => 20,
                    'headers' => [ 'Accept' => 'application/json' ],
                ],
                $args
            )
        );

        return $this->normalize_response( $response, $url );
    }

    public function post_json( $url, array $payload, array $args = [] ) {
        $response = wp_remote_post(
            $url,
            array_merge(
                [
                    'timeout' => 20,
                    'headers' => [
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode( $payload ),
                ],
                $args
            )
        );

        return $this->normalize_response( $response, $url );
    }

    private function normalize_response( $response, $url ) {
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            throw new RuntimeException( sprintf( 'HTTP %d from %s', (int) $code, $url ) );
        }

        return [
            'code' => $code,
            'body' => $body,
        ];
    }
}
