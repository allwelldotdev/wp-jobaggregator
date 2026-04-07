## Core Ingestion Test Suite Plan (Current Code State)

### Summary
Build a PHPUnit unit-test harness (with deterministic WordPress stubs) for the plugin’s ingestion core, then add a focused suite that validates all critical ingestion touchpoints at today’s behavior: source loading/parsing, batch orchestration decisions, job upsert mapping, retry handling, scheduling/settings normalization, and run-lock safety.

### Implementation Changes
- Add PHPUnit runtime scaffolding for this plugin:
  - `phpunit.xml.dist` with `tests/bootstrap.php`, `tests/Unit/` suite, and coverage include for `src/`.
  - `tests/bootstrap.php` to load plugin classes and define controlled stubs for WP functions/classes used by core ingestion paths.
- Add reusable test doubles under `tests/Support/`:
  - Fake source, fake scheduler, fake run manager, fake checkpoint store, fake post writer, fake logger, fake run lock, fake `wpdb`, fake `WP_Query`/HTTP wrappers as needed.
- Add fixture files in `tests/fixtures/`:
  - RSS XML fixture with multiple items and pagination boundary conditions.
  - Jooble JSON fixtures for normal response, malformed shape, and total-count pagination behavior.
- Add unit tests under `tests/Unit/` for ingestion-only scope (admin excluded).
- Keep DB-table manager/store class coverage deferred in v1 per your choice (no `BatchRunManager`/`CheckpointStore` query tests yet).

### Test Inventory (What Each Test Verifies)
1. `SourceRegistryTest::it_loads_only_enabled_and_keyed_sources`
- Verifies config parsing includes only enabled sources, ignores missing keys, and returns source instances keyed correctly.

2. `SourceRegistryTest::it_returns_null_for_unknown_source_key`
- Verifies unresolved source lookup returns `null` without side effects.

3. `RssFeedSourceTest::it_maps_common_rss_fields_into_jobdata`
- Verifies RSS item fields map to `JobData` core fields and defaults are applied as currently implemented.

4. `RssFeedSourceTest::it_advances_offset_and_sets_has_more_correctly`
- Verifies batch pagination checkpoint (`offset`) and `has_more` decisions for slice boundaries and max limit.

5. `JoobleApiSourceTest::it_builds_endpoint_and_maps_jobs_from_response`
- Verifies API key constant usage, endpoint construction, request payload page override, and job normalization from Jooble payload.

6. `JoobleApiSourceTest::it_computes_has_more_from_totalcount_and_max_pages`
- Verifies page progression/termination behavior using `totalCount` and `max_pages`.

7. `JoobleApiSourceTest::it_throws_on_missing_api_key_or_unexpected_shape`
- Verifies hard-fail behavior on missing key constant and malformed API response shape.

8. `SourceBatchResultTest::success_and_retry_factories_emit_expected_state`
- Verifies `success()` and `retry_later()` produce correct status flags, retry timing, checkpoint, and fetched counts.

9. `DuplicateCheckerTest::build_source_hash_uses_current_field_strategy`
- Verifies stable hash generation from current source fields and empty result when no fields exist.

10. `PostWriterTest::it_creates_new_job_listing_with_expected_meta_mapping`
- Verifies create path writes expected post fields/meta and current application URL resolution precedence.

11. `PostWriterTest::it_updates_existing_listing_and_sets_employment_terms`
- Verifies update path when duplicate exists, taxonomy term ensure/set behavior, and update action result.

12. `HttpClientTest::it_retries_transient_failures_and_returns_normalized_response`
- Verifies retry loop for transient failures, max-retry handling, and normalized successful response structure.

13. `HttpClientTest::it_throws_non_transient_or_exhausted_failures`
- Verifies immediate throw for non-transient errors and throw after retry exhaustion for transient errors.

14. `SettingsTest::all_and_sanitize_enforce_bounds_and_valid_recurrence`
- Verifies defaults merge, clamping (`process_delay`, `runs_per_page`), recurrence fallback, and enable flag normalization.

15. `SchedulerTest::schedule_recurring_start_and_process_event_obey_settings`
- Verifies recurring schedule behavior (enable/disable/reschedule/no-op on same schedule) and process event dedupe/timestamp logic.

16. `RunLockTest::acquire_blocks_when_unexpired_and_release_respects_token`
- Verifies lock acquisition TTL behavior, overwrite semantics, and token-protected release behavior.

17. `BatchProcessorTest::no_run_or_no_lock_or_no_due_source_short_circuits_safely`
- Verifies early-exit branches without side effects when run invalid, lock unavailable, or no due source.

18. `BatchProcessorTest::missing_source_marks_retry_and_schedules_follow_up`
- Verifies unresolved source path increments counters, updates processed sources, and queues continuation.

19. `BatchProcessorTest::http_exception_marks_retry_or_failure_using_source_policy`
- Verifies retry/failure transition logic for `HttpRequestException` with retry-after and source retry policy.

20. `BatchProcessorTest::successful_batch_persists_metrics_and_finishes_or_requeues`
- Verifies job loop metric accounting (created/updated/skipped/errors), success checkpoint marking, and final run status decision (`completed` vs `partial`).

### Public Interfaces / Type Changes
- No production public API/interface/type changes.
- New test-only bootstrap, fixtures, and doubles are added.

### Validation
- Run (via LocalWP wrapper):
  - `localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" --cwd wp-content/plugins/job-aggregator composer test`
  - `localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" --cwd wp-content/plugins/job-aggregator composer lint`

### Assumptions and Defaults
- Scope fixed to ingestion core only; admin page/controller rendering tests are excluded.
- v1 intentionally skips direct `BatchRunManager`/`CheckpointStore` DB query tests.
- Tests assert **current behavior** (including current defaults, retry semantics, and mapping logic), not redesigned behavior.
- WordPress dependencies are simulated through deterministic stubs/doubles in unit tests, not full WP integration harness.
