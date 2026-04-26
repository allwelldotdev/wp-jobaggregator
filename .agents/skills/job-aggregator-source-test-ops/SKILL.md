---
name: job-aggregator-source-test-ops
description: Use when adding or enabling RSS/API sources in job-aggregator and when extending or troubleshooting unit, fixture E2E, or live smoke ingestion tests with isolated cleanup expectations.
---

# Job Aggregator Source Test Operations

Use this skill when:
- adding a new RSS source or enabling an existing RSS source
- adding or enabling an API source
- adding ingestion tests for new sources
- troubleshooting ingestion test failures or leftover test data

## Ground Truths

- Runtime commands must use `localwp` with `LOCALWP_SSH_ENTRY` (do not default to system `php`, `wp`, `composer`).
- Unit suite runs with `tests/bootstrap-unit.php`.
- Integration E2E suite runs with `tests/bootstrap-integration.php` and loads real WordPress through `wp-load.php`.
- Source config can be overridden via `job_aggregator_sources_config_path` filter; do not mutate production `config/sources.php` for tests.
- Default data policy is isolated cleanup.
- Persisted test artifacts are opt-in only (`--persist=1` in live smoke script).

## Current Test Topology

- Unit RSS tests:
  - `tests/Unit/Sources/RSS/MyJobMagRssSourceTest.php`
  - `tests/Unit/Sources/RSS/RemoteOkRssSourceTest.php`
  - `tests/Unit/Sources/RSS/WeWorkRemotelyRssSourceTest.php`
  - `tests/Unit/Sources/RSS/HotNigerianJobsRssSourceTest.php`
- Shared doubles:
  - `tests/Support/FakeRssItem.php`
  - `tests/Support/MemoryNormalizationSignalStore.php`
- Fixture E2E:
  - `tests/Integration/E2E/RssIngestionE2ETest.php`
  - `tests/config/sources.integration.php`
  - `tests/fixtures/rss/*.xml`
- Live smoke:
  - `tests/scripts/live-rss-smoke.php`

## RSS Source Expansion Checklist

1. Add or update source definition in `config/sources.php` (production config intent).
2. Add source-specific class/mapping logic in `src/Sources/RSS/` as needed.
3. Add or update unit coverage in `tests/Unit/Sources/RSS/`.
4. Add fixture XML in `tests/fixtures/rss/`.
5. Add source entry in `tests/config/sources.integration.php` with:
   - test key prefix `e2e_...`
   - correct driver
   - test URL mapped to fixture
6. Update fixture URL mapping and assertions in `RssIngestionE2ETest`.
7. Ensure teardown still removes all rows/posts by `e2e_` source key prefix.

## API Source Expansion Checklist

1. Add or update API source class in `src/Sources/API/`.
2. Add unit tests for payload mapping, pagination, retry behavior, and key handling.
3. Extend integration config `tests/config/sources.integration.php` with `apis` entries for test-only enabled API sources.
4. Intercept API HTTP requests in integration tests using `pre_http_request` and fixture payloads.
5. Define required API key constants in integration setup when source requires them.
6. Assert `job_listing` persistence and source/run counters in integration tests.
7. Keep live smoke API-disabled by default unless explicitly needed and credential-safe.

## Cleanup Rules (Must Hold)

- Integration teardown must remove:
  - `job_listing` posts created by test source keys (`e2e_%`)
  - plugin run rows in:
    - `{prefix}job_aggregator_runs`
    - `{prefix}job_aggregator_run_sources`
    - `{prefix}job_aggregator_normalization_signals`
  - scheduled events for:
    - `job_aggregator_start_batch`
    - `job_aggregator_process_batch`
  - feed-related transients/cache used during tests
- Live smoke defaults to isolated cleanup and only keeps records when called with `--persist=1`.

## Commands

- Unit:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test`
- Fixture E2E:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test:e2e`
- All deterministic tests:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test:all`
- Live smoke (isolated default):
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test:e2e:live-rss`
- Live smoke persist mode:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator wp eval-file tests/scripts/live-rss-smoke.php -- --persist=1`

## Similar Operations This Also Covers

- Refactoring test layout while preserving command behavior.
- Introducing new source normalization fields and updating assertions across unit + E2E.
- Verifying no residual DB artifacts after manual smoke runs.
- Diagnosing source onboarding regressions by comparing unit mapping outcomes versus persisted WPJM meta/taxonomy values.
