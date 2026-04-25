<?php

if (!defined("ABSPATH")) {
    exit();
}

return [
    // Keep secrets in wp-config.php and only reference them here.
    "rss" => [
        [
            "enabled" => true,
            "key" => "myjobmag",
            "driver" => "myjobmag",
            "label" => "MyJobMag Nigeria",
            "url" => "https://www.myjobmag.com/aggregate_feed.xml",
            "limit" => 100,
            "batch_size" => 25,
            "defaults" => [
                "location" => "Nigeria",
                "company_name" => "",
                "employment_types" => ["Full Time"],
                "remote_position" => false,
            ],
        ],
        [
            "enabled" => true,
            "key" => "remoteok",
            "driver" => "remoteok",
            "label" => "RemoteOK",
            "url" => "https://remoteok.com/rss",
            "limit" => 100,
            "batch_size" => 25,
            "defaults" => [
                "location" => "Worldwide",
                "company_name" => "",
                "employment_types" => ["Full Time"],
                "remote_position" => true,
            ],
        ],
        [
            "enabled" => true,
            "key" => "weworkremotely",
            "driver" => "weworkremotely",
            "label" => "We Work Remotely",
            "url" => "https://weworkremotely.com/remote-jobs.rss",
            "limit" => 100,
            "batch_size" => 25,
            "defaults" => [
                "location" => "Anywhere in the World",
                "company_name" => "",
                "employment_types" => ["Full Time"],
                "remote_position" => true,
            ],
        ],
        [
            "enabled" => false,
            "key" => "hotnigerianjobs",
            "driver" => "hotnigerianjobs",
            "label" => "Hot Nigerian Jobs",
            "url" => "https://www.hotnigerianjobs.com/feed/rss.xml",
            "limit" => 1000,
            "batch_size" => 100,
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
