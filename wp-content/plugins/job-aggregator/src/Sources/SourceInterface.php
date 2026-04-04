<?php

namespace JobAggregator\Sources;

interface SourceInterface {
    public function fetch_jobs();

    public function get_key();

    public function get_label();
}
