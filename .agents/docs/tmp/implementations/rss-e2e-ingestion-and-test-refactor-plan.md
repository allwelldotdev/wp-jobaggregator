# RSS E2E Ingestion + Test Directory Refactor Plan

## Summary
- Add a deterministic, fixture-backed end-to-end integration test that validates RSS ingestion into WPJM `job_listing` records through the real plugin pipeline.
- Keep live-feed verification as an opt-in smoke test, not a CI gate.
- Refactor `tests/` so it is immediately clear which tests are unit behavior checks versus full pipeline ingestion checks.
- Lock cleanup behavior to `isolated` by default; `persist` is only enabled by explicit flag.

## Locked Decisions
- Default test data policy: `isolated` cleanup mode.
- Live-smoke persistence policy: `persist` only behind explicit runtime flag (`--persist=1`).
- CI policy: run unit + fixture E2E only; do not gate CI on live network feeds.
- Backward compatibility policy: keep `composer test` fast and deterministic (unit suite only), add separate commands for integration and live smoke.

## Implementation Changes

### 1) Test Harness and Runtime
- Introduce WordPress integration test runtime for plugin E2E (`WP_UnitTestCase`) while keeping the existing pure-PHP unit runtime.
- Add integration bootstrap and install script plumbing so integration tests run against a dedicated WP test database, not the site DB.
- Keep unit and integration execution separated at command level:
  - `composer test` -> unit only
  - `composer test:e2e` -> fixture-backed integration E2E
  - `composer test:all` -> unit + fixture E2E

### 2) Source Config Override for Tests
- Add a narrow filter in plugin wiring to override the sources config path in tests/scripts without mutating committed `config/sources.php`.
- Filter name: `job_aggregator_sources_config_path`.
- Default behavior remains unchanged (still uses `wp-content/plugins/job-aggregator/config/sources.php` when filter is absent).

### 3) Deterministic Fixture-Backed E2E
- Add per-source RSS XML fixtures for all enabled RSS sources currently in config:
  - MyJobMag
  - RemoteOK
  - We Work Remotely
- Use `pre_http_request` in integration tests to return fixture bodies for source URLs.
- Disable or clear feed/transient cache during integration tests to ensure deterministic parsing.
- Use an integration-only sources config with test-prefixed keys (for example `e2e_myjobmag`, `e2e_remoteok`, `e2e_weworkremotely`) and `driver` preserved so source-specific normalizers still execute.
- Execute full pipeline in tests:
  - boot plugin
  - start run (`trigger_manual_batch`)
  - loop `process_batch($run_id)` until run status is terminal (`completed` or `partial`)
- Assert E2E outcomes:
  - expected `job_listing` count created/updated
  - expected core fields/meta (`_job_location`, `_application`, `_company_name`, `_remote_position`, `_job_expires`, source metadata keys)
  - expected taxonomy assignment (`job_listing_type`, `job_listing_category`)
  - expected run/checkpoint table status and counters

### 4) Live RSS Smoke Command (Opt-in)
- Add `tests/scripts/live-rss-smoke.php` executed via WP-CLI `eval-file`.
- Script flow:
  - build temporary all-enabled RSS config copy with unique run key prefix (timestamped)
  - force plugin to use that config path via `job_aggregator_sources_config_path`
  - start and process batch synchronously
  - output run summary and source-level status/counters
- Cleanup mode behavior:
  - default (`isolated`): delete created `job_listing` posts for generated source keys, remove related plugin run rows/signals created by the smoke run, clear scheduled process events used by the run
  - explicit `persist`: skip cleanup and leave rows/posts for manual inspection
- Runtime flag contract:
  - no flag -> isolated cleanup
  - `--persist=1` -> keep data

### 5) Test Directory Refactor (Structure + Naming)
- Refactor `tests/` into behavior-oriented layout:
  - `tests/Unit/Sources/RSS/`
  - `tests/Unit/Jobs/`
  - `tests/Integration/E2E/`
  - `tests/Support/`
  - `tests/fixtures/rss/`
  - `tests/scripts/`
- Split current mixed remote-source unit file into source-specific files so each test file maps to one subsystem/class.
- Keep assertion behavior unchanged in moved unit tests unless required by path/bootstrap changes.

## Cleanup and Data Safety Rules
- Integration E2E (`composer test:e2e`) always runs against dedicated WP test DB and performs teardown:
  - truncate plugin-owned tables:
    - `{prefix}job_aggregator_runs`
    - `{prefix}job_aggregator_run_sources`
    - `{prefix}job_aggregator_normalization_signals`
  - delete created `job_listing` posts for integration test source keys
  - clear cron hooks used by plugin test runs
  - clear relevant transients/feed cache used in the run
- Live smoke (`test:e2e:live-rss`) defaults to `isolated` and performs the same logical cleanup against generated source keys/run IDs.
- Persisted live smoke data is only allowed when `--persist=1` is provided intentionally.

## Commands
- Unit tests:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test`
- Fixture E2E integration tests:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test:e2e`
- Full deterministic suite:
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp --cwd wp-content/plugins/job-aggregator composer test:all`
- Live RSS smoke (default isolated cleanup):
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp wp eval-file wp-content/plugins/job-aggregator/tests/scripts/live-rss-smoke.php`
- Live RSS smoke (explicit persist mode):
  - `LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh localwp wp eval-file wp-content/plugins/job-aggregator/tests/scripts/live-rss-smoke.php -- --persist=1`

## Acceptance Criteria
- Integration suite proves end-to-end ingestion from RSS payload to published WPJM `job_listing` with expected normalization and persistence.
- Test directory clearly communicates test intent by suite/subsystem without reading file contents.
- Default execution leaves no leftover ingestion data in test DB or local site DB for isolated runs.
- Persisted artifacts only exist when explicitly requested with `--persist=1`.
- Existing unit suite remains green after directory refactor.
