<?php

namespace JobAggregator\Support;

class HttpClient {
	public function get( $url, array $args = array() ) {
		return $this->request_with_retry(
			static function ( $target_url, $request_args ) {
				return wp_remote_get( $target_url, $request_args );
			},
			$url,
			array_merge(
				array(
					'timeout' => 20,
					'headers' => array( 'Accept' => 'application/json' ),
				),
				$args
			)
		);
	}

	public function post_json( $url, array $payload, array $args = array() ) {
		return $this->request_with_retry(
			static function ( $target_url, $request_args ) {
				return wp_remote_post( $target_url, $request_args );
			},
			$url,
			array_merge(
				array(
					'timeout' => 20,
					'headers' => array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
				),
				$args
			)
		);
	}

	private function request_with_retry( callable $requester, $url, array $args ) {
		$max_retries = isset( $args['max_retries'] ) ? max( 0, (int) $args['max_retries'] ) : 2;
		$base_delay  = isset( $args['retry_backoff_base'] ) ? max( 1, (int) $args['retry_backoff_base'] ) : 2;

		unset( $args['max_retries'], $args['retry_backoff_base'] );

		$attempt = 0;
		do {
			++$attempt;
			$response = call_user_func( $requester, $url, $args );

			try {
				return $this->normalize_response( $response, $url );
			} catch ( HttpRequestException $exception ) {
				if ( ! $exception->is_transient() || $attempt > $max_retries ) {
					throw $exception;
				}

				$retry_after = $exception->retry_after();
				if ( $retry_after < 1 ) {
					$retry_after = (int) pow( $base_delay, $attempt );
				}

				sleep( min( $retry_after, 30 ) );
			}
		} while ( $attempt <= $max_retries );

		throw new HttpRequestException( sprintf( 'HTTP request to %s failed after retries.', $url ), 0, false );
	}

	private function normalize_response( $response, $url ) {
		if ( is_wp_error( $response ) ) {
			throw new HttpRequestException( $response->get_error_message(), 0, true );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$is_transient = ( 429 === $code || $code >= 500 );
			$retry_after  = $this->extract_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );

			throw new HttpRequestException( sprintf( 'HTTP %d from %s', (int) $code, $url ), (int) $code, $is_transient, $retry_after );
		}

		return array(
			'code' => $code,
			'body' => $body,
		);
	}

	private function extract_retry_after( $retry_after_header ) {
		if ( empty( $retry_after_header ) ) {
			return 0;
		}

		if ( is_numeric( $retry_after_header ) ) {
			return max( 0, (int) $retry_after_header );
		}

		$timestamp = strtotime( (string) $retry_after_header );
		if ( false === $timestamp ) {
			return 0;
		}

		return max( 0, $timestamp - time() );
	}
}
