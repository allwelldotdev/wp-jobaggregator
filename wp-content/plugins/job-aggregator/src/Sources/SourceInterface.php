<?php

namespace JobAggregator\Sources;

interface SourceInterface {
	public function initial_checkpoint();

	public function fetch_batch( array $checkpoint );

	public function supports_pagination();

	public function get_key();

	public function get_label();
}
