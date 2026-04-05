<?php

namespace JobAggregator\Batch;

class SourceBatchResult {
	private $jobs;
	private $has_more;
	private $next_checkpoint;
	private $source_status;
	private $retry_after;
	private $error_message;
	private $fetched_count;

	public function __construct( array $args = array() ) {
		$this->jobs            = isset( $args['jobs'] ) ? (array) $args['jobs'] : array();
		$this->has_more        = ! empty( $args['has_more'] );
		$this->next_checkpoint = isset( $args['next_checkpoint'] ) && is_array( $args['next_checkpoint'] ) ? $args['next_checkpoint'] : array();
		$this->source_status   = isset( $args['source_status'] ) ? (string) $args['source_status'] : 'running';
		$this->retry_after     = isset( $args['retry_after'] ) ? (int) $args['retry_after'] : 0;
		$this->error_message   = isset( $args['error_message'] ) ? (string) $args['error_message'] : '';
		$this->fetched_count   = isset( $args['fetched_count'] ) ? (int) $args['fetched_count'] : count( $this->jobs );
	}

	public static function success( array $jobs, $has_more, array $next_checkpoint = array() ) {
		return new self(
			array(
				'jobs'            => $jobs,
				'has_more'        => (bool) $has_more,
				'next_checkpoint' => $next_checkpoint,
				'source_status'   => (bool) $has_more ? 'running' : 'completed',
				'fetched_count'   => count( $jobs ),
			)
		);
	}

	public static function retry_later( $message, $retry_after_seconds ) {
		return new self(
			array(
				'jobs'          => array(),
				'has_more'      => true,
				'source_status' => 'waiting_retry',
				'retry_after'   => max( 1, (int) $retry_after_seconds ),
				'error_message' => (string) $message,
				'fetched_count' => 0,
			)
		);
	}

	public function jobs() {
		return $this->jobs;
	}

	public function has_more() {
		return $this->has_more;
	}

	public function next_checkpoint() {
		return $this->next_checkpoint;
	}

	public function source_status() {
		return $this->source_status;
	}

	public function retry_after() {
		return $this->retry_after;
	}

	public function error_message() {
		return $this->error_message;
	}

	public function fetched_count() {
		return $this->fetched_count;
	}
}
