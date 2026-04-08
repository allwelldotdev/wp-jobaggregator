<?php

if (!defined("ABSPATH")) {
    exit();
}

return [
    // Keep secrets in wp-config.php and only reference them here.
    "rss" => [
        [
            "enabled" => false,
            "key" => "myjobmag",
            "driver" => "myjobmag",
            "label" => "MyJobMag Nigeria",
            "url" => "https://www.myjobmag.com/aggregate_feed.xml",
            "limit" => 20,
            "defaults" => [
                "location" => "Nigeria",
                "company_name" => "",
                "employment_types" => ["Full Time"],
                "remote_position" => false,
            ],
        ],
    ],
    "apis" => [
        [
            "enabled" => false,
            "driver" => "jooble",
            "key" => "jooble",
            "label" => "Jooble",
            "api_key_constant" => "JOB_AGGREGATOR_JOOBLE_API_KEY",
            "endpoint" => "https://jooble.org/api",
            "request" => [
                "keywords" => "",
                "location" => "Nigeria",
                "page" => 1,
                "ResultOnPage" => 20,
            ],
            "defaults" => [
                "employment_types" => ["Full Time"],
                "remote_position" => false,
            ],
        ],
    ],
];
