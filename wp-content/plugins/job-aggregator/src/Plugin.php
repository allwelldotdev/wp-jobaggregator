<?php

namespace JobAggregator;

use JobAggregator\Jobs\DuplicateChecker;
use JobAggregator\Jobs\PostWriter;
use JobAggregator\Support\HttpClient;
use JobAggregator\Support\Logger;

class Plugin {
    const CRON_HOOK = 'job_aggregator_run_import';

    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function boot() {
        add_action( self::CRON_HOOK, [ $this, 'run_import' ] );
        add_action( 'admin_notices', [ $this, 'render_dependency_notice' ] );
    }

    public static function activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    public function run_import() {
        if ( ! $this->dependencies_available() ) {
            $this->logger->error( 'WP Job Manager is required before imports can run.' );
            return;
        }

        $registry   = new SourceRegistry( JOB_AGGREGATOR_PATH . 'config/sources.php', $this->logger, new HttpClient() );
        $post_writer = new PostWriter( new DuplicateChecker(), $this->logger );
        $sources     = $registry->all();

        foreach ( $sources as $source ) {
            try {
                $jobs = $source->fetch_jobs();

                foreach ( $jobs as $job ) {
                    if ( empty( $job->title ) || empty( $job->source_url ) ) {
                        continue;
                    }

                    $post_writer->upsert( $job );
                }
            } catch ( \Throwable $exception ) {
                $this->logger->error(
                    'Source import failed.',
                    [
                        'source_key' => $source->get_key(),
                        'message'    => $exception->getMessage(),
                    ]
                );
            }
        }
    }

    public function render_dependency_notice() {
        if ( $this->dependencies_available() || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>Job Aggregator requires WP Job Manager and the <code>job_listing</code> post type.</p></div>';
    }

    private function dependencies_available() {
        return class_exists( 'WP_Job_Manager' ) || post_type_exists( 'job_listing' );
    }
}
