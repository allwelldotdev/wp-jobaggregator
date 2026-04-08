<?php

namespace JobAggregator\Support;

use RuntimeException;

/**
 * Represents source HTTP failures with transient and retry timing metadata.
 */
class HttpRequestException extends RuntimeException {
	private $status_code;
	private $is_transient;
	private $retry_after;

	public function __construct( $message, $status_code = 0, $is_transient = false, $retry_after = 0 ) {
		parent::__construct( (string) $message );
		$this->status_code  = (int) $status_code;
		$this->is_transient = (bool) $is_transient;
		$this->retry_after  = (int) $retry_after;
	}

	public function status_code() {
		return $this->status_code;
	}

	public function is_transient() {
		return $this->is_transient;
	}

	public function retry_after() {
		return $this->retry_after;
	}
}
