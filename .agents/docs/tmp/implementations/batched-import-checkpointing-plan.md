# Batched Import and Checkpointing Plan

## Purpose
Convert the current importer from a single long-running cron task into a resumable batch system that:

- starts a run from cron
- processes one source or one page at a time
- saves checkpoint/progress after every chunk
- schedules the next chunk when more work remains
- keeps each request short to reduce timeout and memory issues
- records operational state for a future WordPress admin UI

## Current Codebase Fit
This plan is designed to extend the existing plugin rather than replace it.

- [Plugin.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Plugin.php) already owns cron orchestration.
- [SourceRegistry.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/SourceRegistry.php) already resolves enabled sources.
- [RssFeedSource.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Sources/RssFeedSource.php) and [JoobleApiSource.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Sources/JoobleApiSource.php) are the correct place for source-specific paging and checkpoint logic.
- [PostWriter.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Jobs/PostWriter.php) remains responsible for persistence into `job_listing`.
- [Logger.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Support/Logger.php) can remain a human-readable log, but structured run/checkpoint state should be stored separately.

## Target Behavior
The importer should work like this:

1. A recurring cron event starts or resumes an import run.
2. The plugin creates a run record and source checkpoints.
3. A single processing event handles only one small chunk.
4. The plugin writes progress immediately after that chunk.
5. If more work remains, it schedules the next single-event continuation.
6. When all sources are complete, it marks the run finished.

This avoids looping every source and every job in one request.

## Recommended New Components
Add these classes under `wp-content/plugins/job-aggregator/src/`:

- `Cron/Scheduler.php`
  Handles registration of recurring and single-event cron hooks.

- `Batch/BatchRunManager.php`
  Creates runs, updates run status, stores timestamps and totals.

- `Batch/CheckpointStore.php`
  Persists per-source progress, retry state, and next work position.

- `Batch/BatchProcessor.php`
  Loads the active run, selects the next source chunk, executes it, stores the result, and decides whether to queue another chunk.

- `Batch/SourceBatchResult.php`
  A small result object returned by sources, containing jobs, `has_more`, next checkpoint, and retry/error information.

- `Batch/RunLock.php`
  Prevents overlapping workers.

## Cron Design
Use two cron hooks instead of one:

- `job_aggregator_start_batch`
  Recurring event that starts a new run or resumes a stalled one.

- `job_aggregator_process_batch`
  Single event that processes one chunk only.

Recommended flow:

1. `job_aggregator_start_batch` runs on schedule.
2. It creates a run if no active run exists.
3. It schedules `job_aggregator_process_batch` immediately.
4. Each `job_aggregator_process_batch` processes one source page or one bounded item chunk.
5. If work remains, it uses `wp_schedule_single_event()` to queue another batch.
6. If work is complete, it closes the run.

## Locking
Add a run lock to prevent duplicate workers.

Suggested behavior:

- store lock in an option or transient
- include `run_id`, owner hook, and timestamp
- refuse to process if a healthy lock already exists
- expire stale locks after a conservative timeout

This prevents multiple WP-Cron triggers from double-processing the same source.

## Source Contract Changes
The current source contract uses `fetch_jobs()`. For batch processing, extend it to support checkpoints.

Suggested methods:

- `initial_checkpoint(): array`
- `fetch_batch(array $checkpoint): SourceBatchResult`
- `supports_pagination(): bool`

### RSS behavior
RSS often is not truly paginated. For RSS sources:

- fetch the feed once per chunk
- process only a bounded number of unprocessed items
- checkpoint using last processed item ID, URL, or published date
- stop when no newer items remain

### API behavior
API sources should use real pagination when available:

- checkpoint page number, cursor, or offset
- process one page per chunk
- return `has_more` when another page exists

## SourceBatchResult Shape
The source result object should contain:

- `jobs`
- `has_more`
- `next_checkpoint`
- `source_status`
- `retry_after`
- `error_message`
- `fetched_count`

This keeps source-specific logic in source classes and keeps orchestration generic.

## Data To Persist
The future admin UI will need structured operational state. Persist data at two levels.

### Run-level state
- `run_id`
- `status`
- `triggered_by`
- `started_at`
- `last_activity_at`
- `completed_at`
- `total_sources`
- `processed_sources`
- `created_count`
- `updated_count`
- `skipped_count`
- `error_count`
- `retry_count`

### Source-level state
- `run_id`
- `source_key`
- `status`
- `last_run_at`
- `last_success_at`
- `last_error_at`
- `last_error_message`
- `attempt_count`
- `retry_count`
- `next_retry_at`
- `processed_items`
- `remaining_hint`
- `checkpoint_payload`
- `has_more`

These fields directly support future UI needs such as:

- last run time
- source status
- failures and retries
- whether follow-up batches are queued

## Storage Recommendation
Two storage approaches are possible:

### Short-term
Use options for initial implementation:

- one option for active run summary
- one option for source checkpoints/state
- one option for recent run history

### Recommended long-term
Use custom tables once implementation stabilizes:

- `wp_job_aggregator_runs`
- `wp_job_aggregator_run_sources`

Reason:
Run and checkpoint data is operational state, not content. Custom tables will scale better and make the future admin screens easier to query.

## Retry Strategy
Retries should exist at both the HTTP and orchestration layers.

### HTTP layer
Extend [HttpClient.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Support/HttpClient.php) to retry only transient failures:

- timeouts
- connection failures
- HTTP `429`
- HTTP `5xx`

Avoid retrying permanent failures such as bad credentials or invalid requests.

Suggested behavior:

- capped exponential backoff
- max retry attempts per request
- return enough error detail for structured source state

### Batch orchestration layer
If a chunk fails:

- increment source retry count
- store last error message
- set source status to `waiting_retry`
- schedule next attempt using `retry_after`
- stop retrying permanently after a configured max
- continue processing other sources instead of failing the whole run

## Processing Rules
Each batch request should:

- process only one source chunk
- write posts for only that chunk
- save checkpoint immediately
- update run and source counters
- release lock cleanly
- schedule the next chunk only if work remains

Do not process all sources in one request.

## Plugin Refactor Path
The current [Plugin.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Plugin.php) should become a thin coordinator.

Suggested refactor:

- move cron hook registration into `Cron/Scheduler.php`
- replace the current full import loop with `start_batch()` and `process_batch()`
- keep dependency checks in the plugin bootstrap
- delegate batch state transitions to `BatchRunManager`
- delegate actual chunk execution to `BatchProcessor`

## Suggested Execution Order
Implement in this order:

1. Add run storage and checkpoint storage.
2. Split cron into `start_batch` and `process_batch`.
3. Add run locking.
4. Refactor source contract to support checkpoints and paged batches.
5. Update RSS and Jooble sources to return `SourceBatchResult`.
6. Add retry/backoff support in HTTP and batch layers.
7. Update logging so structured state and message logs are separate.
8. Build the admin UI on top of the structured run/source state.

## Practical Notes For This Repo
- Keep ingestion logic in `wp-content/plugins/job-aggregator/`.
- Keep future admin screens in the plugin, not the child theme.
- Keep child-theme work limited to presentation of `job_listing`.
- Use the existing `job_listing` post type and current [PostWriter.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Jobs/PostWriter.php) as the canonical persistence path.
- Keep [Logger.php](/home/allwell/Local%20Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator/src/Support/Logger.php) for readable logs, but do not rely on it as the main checkpoint store.

## Outcome
If implemented this way, the importer will:

- keep requests short
- resume safely after interruptions
- reduce timeout and memory pressure
- isolate failures to a source or page instead of a whole run
- provide real state for an admin UI
- make future manual-run and monitoring features much easier to add
