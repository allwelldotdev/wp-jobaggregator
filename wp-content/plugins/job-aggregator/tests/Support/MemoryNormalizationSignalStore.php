<?php

namespace JobAggregator\Tests\Support;

use JobAggregator\Jobs\NormalizationSignalStore;

class MemoryNormalizationSignalStore extends NormalizationSignalStore {
	public $records = array();

	public function __construct() {}

	public function record( $source_key, $signal_type, $raw_value, $normalized_value = '', $example_external_id = '', $example_title = '' ) {
		$this->records[] = array(
			'source_key'          => (string) $source_key,
			'signal_type'         => (string) $signal_type,
			'raw_value'           => (string) $raw_value,
			'normalized_value'    => (string) $normalized_value,
			'example_external_id' => (string) $example_external_id,
			'example_title'       => (string) $example_title,
		);
	}
}
