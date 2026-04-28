<?php

return array(
	'rss'  => array(
		array(
			'enabled'    => true,
			'key'        => 'e2e_myjobmag',
			'driver'     => 'myjobmag',
			'label'      => 'MyJobMag E2E Dedup',
			'url'        => 'https://fixtures.job-aggregator.test/myjobmag-dedup.xml',
			'limit'      => 25,
			'batch_size' => 25,
			'defaults'   => array(
				'location'         => 'Nigeria',
				'company_name'     => '',
				'employment_types' => array( 'Full Time' ),
				'remote_position'  => false,
			),
		),
		array(
			'enabled'    => true,
			'key'        => 'e2e_hotnigerianjobs',
			'driver'     => 'hotnigerianjobs',
			'label'      => 'Hot Nigerian Jobs E2E Dedup',
			'url'        => 'https://fixtures.job-aggregator.test/hotnigerianjobs.xml',
			'limit'      => 25,
			'batch_size' => 25,
			'defaults'   => array(
				'location'         => 'Nigeria',
				'company_name'     => '',
				'employment_types' => array( 'Full Time' ),
				'remote_position'  => false,
			),
		),
	),
	'apis' => array(),
);
