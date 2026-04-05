<?php

namespace JobAggregator\Batch;

use JobAggregator\Cron\Scheduler;
use JobAggregator\Jobs\PostWriter;
use JobAggregator\SourceRegistry;
use JobAggregator\Support\HttpRequestException;
use JobAggregator\Support\Logger;
use Throwable;

class BatchProcessor {
	private $run_manager;
	private $checkpoint_store;
	private $registry;
	private $post_writer;
	private $scheduler;
	private $logger;
	private $run_lock;

	public function __construct(
		BatchRunManager $run_manager,
		CheckpointStore $checkpoint_store,
		SourceRegistry $registry,
		PostWriter $post_writer,
		Scheduler $scheduler,
		Logger $logger,
		RunLock $run_lock
	) {
		$this->run_manager      = $run_manager;
		$this->checkpoint_store = $checkpoint_store;
		$this->registry         = $registry;
		$this->post_writer      = $post_writer;
		$this->scheduler        = $scheduler;
		$this->logger           = $logger;
		$this->run_lock         = $run_lock;
	}

	public function process( $run_id ) {
		$run_id = (int) $run_id;
		if ( $run_id < 1 ) {
			return;
		}

		$token = $this->run_lock->acquire( $run_id );
		if ( '' === $token ) {
			return;
		}

		try {
			$run = $this->run_manager->get_run( $run_id );
			if ( empty( $run ) || 'running' !== $run['status'] ) {
				return;
			}

			$source_state = $this->checkpoint_store->next_due_source( $run_id );
			if ( empty( $source_state ) ) {
				$this->queue_follow_up_or_finish( $run_id );

				return;
			}

			$source = $this->registry->get( $source_state['source_key'] );
			if ( null === $source ) {
				$this->logger->error(
					'Configured source could not be resolved during batch import.',
					array(
						'run_id'     => $run_id,
						'source_key' => $source_state['source_key'],
					)
				);

				$retry_update = $this->checkpoint_store->mark_source_retry_or_failure(
					$source_state,
					'Configured source class could not be resolved.',
					1,
					0
				);

				$this->run_manager->increment_counters(
					$run_id,
					array(
						'error_count' => 1,
						'retry_count' => $retry_update['terminal'] ? 0 : 1,
					)
				);
				$this->run_manager->update_processed_sources( $run_id, $this->checkpoint_store->count_processed_sources( $run_id ) );
				$this->queue_follow_up_or_finish( $run_id );

				return;
			}

			$this->checkpoint_store->mark_source_running( $source_state );
			$checkpoint = $this->checkpoint_store->decode_checkpoint( $source_state );

			try {
				$result = $source->fetch_batch( $checkpoint );
			} catch ( HttpRequestException $exception ) {
				$retry_after = $exception->retry_after();
				if ( $retry_after < 1 ) {
					$retry_after = method_exists( $source, 'get_retry_delay' ) ? (int) $source->get_retry_delay() : 120;
				}

				$max_retries  = method_exists( $source, 'get_max_retries' ) ? (int) $source->get_max_retries() : 3;
				$retry_update = $this->checkpoint_store->mark_source_retry_or_failure(
					$source_state,
					$exception->getMessage(),
					$retry_after,
					$max_retries
				);

				$this->run_manager->increment_counters(
					$run_id,
					array(
						'error_count' => 1,
						'retry_count' => $retry_update['terminal'] ? 0 : 1,
					)
				);
				$this->run_manager->update_processed_sources( $run_id, $this->checkpoint_store->count_processed_sources( $run_id ) );

				$this->logger->error(
					'Source batch request failed.',
					array(
						'run_id'       => $run_id,
						'source_key'   => $source_state['source_key'],
						'retry_status' => $retry_update['status'],
						'retry_after'  => $retry_after,
						'error'        => $exception->getMessage(),
					)
				);
				$this->queue_follow_up_or_finish( $run_id );

				return;
			} catch ( Throwable $exception ) {
				$max_retries  = method_exists( $source, 'get_max_retries' ) ? (int) $source->get_max_retries() : 3;
				$retry_after  = method_exists( $source, 'get_retry_delay' ) ? (int) $source->get_retry_delay() : 120;
				$retry_update = $this->checkpoint_store->mark_source_retry_or_failure(
					$source_state,
					$exception->getMessage(),
					$retry_after,
					$max_retries
				);

				$this->run_manager->increment_counters(
					$run_id,
					array(
						'error_count' => 1,
						'retry_count' => $retry_update['terminal'] ? 0 : 1,
					)
				);
				$this->run_manager->update_processed_sources( $run_id, $this->checkpoint_store->count_processed_sources( $run_id ) );

				$this->logger->error(
					'Source batch failed.',
					array(
						'run_id'       => $run_id,
						'source_key'   => $source_state['source_key'],
						'retry_status' => $retry_update['status'],
						'error'        => $exception->getMessage(),
					)
				);
				$this->queue_follow_up_or_finish( $run_id );

				return;
			}

			$metrics = array(
				'created_count' => 0,
				'updated_count' => 0,
				'skipped_count' => 0,
				'error_count'   => 0,
			);

			foreach ( $result->jobs() as $job ) {
				if ( empty( $job->title ) || empty( $job->source_url ) ) {
					++$metrics['skipped_count'];
					continue;
				}

				try {
					$upsert_result = $this->post_writer->upsert_with_result( $job );
					if ( ! empty( $upsert_result['action'] ) && 'created' === $upsert_result['action'] ) {
						++$metrics['created_count'];
					} else {
						++$metrics['updated_count'];
					}
				} catch ( Throwable $exception ) {
					++$metrics['error_count'];
					$this->logger->error(
						'Failed to persist job listing during batch import.',
						array(
							'run_id'     => $run_id,
							'source_key' => $source_state['source_key'],
							'title'      => isset( $job->title ) ? $job->title : '',
							'source_url' => isset( $job->source_url ) ? $job->source_url : '',
							'error'      => $exception->getMessage(),
						)
					);
				}
			}

			$this->checkpoint_store->mark_source_success( $source_state, $result, $metrics );
			$this->run_manager->increment_counters( $run_id, $metrics );
			$this->run_manager->update_processed_sources( $run_id, $this->checkpoint_store->count_processed_sources( $run_id ) );
			$this->queue_follow_up_or_finish( $run_id );
		} finally {
			$this->run_lock->release( $run_id, $token );
		}
	}

	private function queue_follow_up_or_finish( $run_id ) {
		if ( $this->checkpoint_store->has_due_work( $run_id ) ) {
			$this->run_manager->set_has_follow_up( $run_id, true );
			$this->scheduler->schedule_process_event( $run_id, time() + 5 );

			return;
		}

		if ( $this->checkpoint_store->has_open_work( $run_id ) ) {
			$retry_timestamp = $this->checkpoint_store->earliest_retry_timestamp( $run_id );
			if ( $retry_timestamp > 0 ) {
				$this->run_manager->set_has_follow_up( $run_id, true );
				$this->scheduler->schedule_process_event( $run_id, max( time() + 5, $retry_timestamp ) );
			}

			return;
		}

		$status = $this->checkpoint_store->has_failed_sources( $run_id ) ? 'partial' : 'completed';
		$this->run_manager->mark_run_completed( $run_id, $status );
	}
}
