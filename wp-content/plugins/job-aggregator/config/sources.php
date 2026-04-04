<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

return array(
	// Keep secrets in wp-config.php and only reference them here.
	'rss'  => array(
		array(
			'enabled'  => false,
			'key'      => 'myjobmag-nigeria',
			'label'    => 'MyJobMag Nigeria',
			'url'      => 'https://www.myjobmag.com/jobsxml_by_categories.xml',
			'limit'    => 20,
			'defaults' => array(
				'location'         => 'Nigeria',
				'company_name'     => '',
				'employment_types' => array( 'Full Time' ),
				'remote_position'  => false,
			),
		),
	),
	'apis' => array(
		array(
			'enabled'          => false,
			'driver'           => 'jooble',
			'key'              => 'jooble-nigeria',
			'label'            => 'Jooble Nigeria',
			'api_key_constant' => 'JOB_AGGREGATOR_JOOBLE_API_KEY',
			'endpoint'         => 'https://jooble.org/api',
			'request'          => array(
				'keywords'     => '',
				'location'     => 'Nigeria',
				'page'         => 1,
				'ResultOnPage' => 20,
			),
			'defaults'         => array(
				'employment_types' => array(),
				'remote_position'  => false,
			),
		),
	),
);
