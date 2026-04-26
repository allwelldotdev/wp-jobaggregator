<?php

namespace JobAggregator\Tests\Unit;

use JobAggregator\Cron\Scheduler;
use JobAggregator\SourceRegistry;
use JobAggregator\Sources\RSS\HotNigerianJobsRssSource;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;
use JobAggregator\Support\Settings;
use JobAggregator\Tests\Support\MemoryNormalizationSignalStore;
use JobAggregator\Tests\Support\UnitWpState;
use PHPUnit\Framework\TestCase;

class SourceRegistryTest extends TestCase {
	private $config_path = '';

	protected function setUp(): void {
		UnitWpState::reset();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		UnitWpState::$schedules = array(
			Scheduler::EVERY_TWO_HOURS => array(
				'interval' => 2 * HOUR_IN_SECONDS,
				'display'  => 'Every 2 Hours',
			),
			Scheduler::EVERY_EIGHT_HOURS => array(
				'interval' => 8 * HOUR_IN_SECONDS,
				'display'  => 'Every 8 Hours',
			),
		);
		$this->config_path    = $this->create_test_config();
	}

	protected function tearDown(): void {
		if ( '' !== $this->config_path && file_exists( $this->config_path ) ) {
			unlink( $this->config_path );
		}
	}

	public function test_configured_sources_include_catalog_entries_while_effective_state_uses_settings() {
		UnitWpState::$options[ Settings::OPTION_KEY ] = array(
			'enable_recurring' => 0,
			'recurrence'       => Scheduler::EVERY_TWO_HOURS,
			'process_delay'    => 5,
			'runs_per_page'    => 20,
			'source_states'    => array(
				'test_remoteok' => 1,
				'test_myjobmag' => 0,
			),
		);

		$registry = $this->new_registry();

		$configured = $registry->configured();
		$this->assertCount( 3, $configured );
		$this->assertSame( 'test_myjobmag', $configured[0]['key'] );
		$this->assertTrue( $configured[0]['config_enabled'] );
		$this->assertFalse( $configured[0]['effective_enabled'] );
		$this->assertSame( 'test_remoteok', $configured[1]['key'] );
		$this->assertFalse( $configured[1]['config_enabled'] );
		$this->assertFalse( $configured[1]['effective_enabled'] );
		$this->assertSame( 'test_hotnigerianjobs', $configured[2]['key'] );
		$this->assertFalse( $configured[2]['config_enabled'] );
		$this->assertFalse( $configured[2]['effective_enabled'] );

		$enabled_sources = $registry->all();
		$this->assertCount( 0, $enabled_sources );
	}

	public function test_configured_source_states_reflect_catalog_enabled_flags() {
		$registry = $this->new_registry();

		$this->assertSame(
			array(
				'test_myjobmag' => 1,
				'test_remoteok' => 0,
				'test_hotnigerianjobs' => 0,
			),
			$registry->configured_source_states()
		);
	}

	public function test_hotnigerianjobs_driver_builds_dedicated_rss_source() {
		$registry = $this->new_registry();
		$source   = $registry->get( 'test_hotnigerianjobs' );

		$this->assertInstanceOf( HotNigerianJobsRssSource::class, $source );
	}

	private function new_registry() {
		return new SourceRegistry(
			$this->config_path,
			new Logger(),
			new HttpClient(),
			new MemoryNormalizationSignalStore()
		);
	}

	private function create_test_config() {
		$temp_file = tempnam( sys_get_temp_dir(), 'job-agg-registry-' );
		$this->assertIsString( $temp_file );

		$config = <<<'PHP'
<?php

return array(
	'rss' => array(
		array(
			'enabled'    => true,
			'key'        => 'test_myjobmag',
			'driver'     => 'myjobmag',
			'label'      => 'MyJobMag Test',
			'url'        => 'https://example.test/myjobmag.xml',
			'batch_size' => 25,
		),
		array(
			'enabled'    => false,
			'key'        => 'test_remoteok',
			'driver'     => 'remoteok',
			'label'      => 'RemoteOK Test',
			'url'        => 'https://example.test/remoteok.xml',
			'batch_size' => 25,
		),
		array(
			'enabled'    => false,
			'key'        => 'test_hotnigerianjobs',
			'driver'     => 'hotnigerianjobs',
			'label'      => 'Hot Nigerian Jobs Test',
			'url'        => 'https://example.test/hotnigerianjobs.xml',
			'batch_size' => 25,
		),
	),
	'apis' => array(),
);
PHP;

		file_put_contents( $temp_file, $config );

		return $temp_file;
	}
}
