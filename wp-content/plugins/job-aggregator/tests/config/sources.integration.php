<?php

return array(
	'rss'  => array(
		array(
			'enabled'    => true,
			'key'        => 'e2e_myjobmag',
			'driver'     => 'myjobmag',
			'label'      => 'MyJobMag E2E',
			'url'        => 'https://fixtures.job-aggregator.test/myjobmag.xml',
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
			'key'        => 'e2e_remoteok',
			'driver'     => 'remoteok',
			'label'      => 'RemoteOK E2E',
			'url'        => 'https://fixtures.job-aggregator.test/remoteok.xml',
			'limit'      => 25,
			'batch_size' => 25,
			'defaults'   => array(
				'location'         => 'Worldwide',
				'company_name'     => '',
				'employment_types' => array( 'Full Time' ),
				'remote_position'  => true,
			),
		),
		array(
			'enabled'    => true,
			'key'        => 'e2e_weworkremotely',
			'driver'     => 'weworkremotely',
			'label'      => 'We Work Remotely E2E',
			'url'        => 'https://fixtures.job-aggregator.test/weworkremotely.xml',
			'limit'      => 25,
			'batch_size' => 25,
			'defaults'   => array(
				'location'         => 'Anywhere in the World',
				'company_name'     => '',
				'employment_types' => array( 'Full Time' ),
				'remote_position'  => true,
			),
		),
	),
	'apis' => array(),
);
