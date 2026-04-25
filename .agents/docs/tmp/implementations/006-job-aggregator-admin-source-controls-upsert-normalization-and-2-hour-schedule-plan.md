# 006 Job Aggregator Admin Source Controls, Smarter Upsert, MyJobMag Remote Mapping, and 2-Hour Schedule

## Summary
- Add admin-managed source on/off controls on the `Settings` screen using a DB override model, while keeping `config/sources.php` as the source catalog/default definition file.
- Verify the manual import path against the LocalWP runtime; keep the existing batch-run flow if it works, patch it only if runtime verification exposes a real break.
- Replace the current always-update upsert behavior with a two-key strategy: a stable identity key for matching existing listings and a content fingerprint for cheap no-op skips.
- Update MyJobMag normalization so `Remote` is treated as a remote-work signal instead of noise in employment-type normalization.
- Add a custom recurring schedule for every 2 hours and expose it in settings.

## Key Changes
- Settings/schema:
  - Extend `job_aggregator_settings` with per-source override state, e.g. `source_states[source_key] = 0|1`.
  - Keep `config/sources.php` entries as available source definitions; admin settings decide whether a configured source is effectively enabled at runtime.
  - Change install defaults to explicit opt-in for new installs: recurring imports off by default, source overrides default off.
  - Preserve upgrade safety by seeding source overrides once from current config-enabled values for existing installs that already have the plugin configured, so upgrades do not silently shut off active sites.

- Source registry and admin UI:
  - Refactor `SourceRegistry` to expose both configured source definitions and effective enabled sources.
  - Update the `Settings` page source table into a real control surface with labeled toggle inputs per source and clear effective-state messaging.
  - Update dashboard/manual-import messaging so it refers to admin-enabled sources, not only `config/sources.php`.
  - Keep manual import scoped to “all currently enabled sources”; no per-source manual-run UI in this change.

- Manual import verification:
  - First verify runtime truth with `localwp` once DB access is healthy: trigger manual import, inspect run rows, confirm queued/process flow, and confirm `job_listing` writes.
  - If the current path works, leave behavior intact and only update notices/copy as needed.
  - If runtime verification shows a break, patch the narrow failing point in `ManualRunController` / `Plugin::trigger_manual_batch()` / batch scheduling rather than redesigning the flow.

- Upsert/duplicate handling:
  - Replace the current source hash usage for matching because it includes mutable fields like title/company and can misclassify changed jobs as new jobs.
  - Introduce a stable identity key meta value for lookup:
    - Prefer `source_key + external_id` when `external_id` exists.
    - Fallback to `source_key + normalized source_url` when `external_id` is absent.
  - Introduce a separate content fingerprint meta value covering persisted post/meta/taxonomy fields that matter for change detection: title, description, source/application URLs, company fields, location, employment types, categories, remote flag, salary fields, published/expiry dates, and logo reference.
  - `DuplicateChecker` should match by stable identity only.
  - `PostWriter` should:
    - Insert when no identity match exists.
    - Skip when identity matches and content fingerprint is unchanged.
    - Update only when identity matches and content fingerprint differs.
  - Skip path should avoid `wp_update_post()`, taxonomy rewrites, meta rewrites, and `_job_aggregator_imported_at` churn so unchanged jobs do not touch `post_modified`.
  - Batch metrics should count unchanged matches as `skipped`, not `updated`.

- MyJobMag normalization:
  - Treat `Remote` in MyJobMag `working_hours` / employment-type-like fields as a remote-work signal, not as an unmatched employment type.
  - Preserve a real employment type when both exist, e.g. `Full Time , Remote` => `employment_types = ['Full Time']`, `remote_position = true`.
  - For remote-only values, set `remote_position = true` and fall back to the default employment type only if no real type token is present.
  - Record normalization signals only for truly unknown employment-type tokens after remote-token handling.

- Scheduling:
  - Add a custom cron schedule slug for every 2 hours.
  - Make it available through `Settings` sanitization/default schedule resolution and the recurrence dropdown.
  - Keep the existing 8-hour option; this is an additive interval only.

- Docs/config policy:
  - Update `.agents/policies/mandatory-agent-workflow.md` and the relevant `.agents/docs/*` summaries to reflect that source definitions stay in config, but runtime source enablement now lives in plugin settings overrides.
  - Do not overwrite the user’s current dirty `config/sources.php`; treat it as the source catalog/default state only.

## Public Interfaces / Persistent State
- `job_aggregator_settings` gains source override state.
- New post meta for change detection:
  - stable identity key meta
  - content fingerprint meta
- `SourceRegistry` interface/behavior expands from “enabled-only” lookup to “configured definitions + effective enabled set”.
- Cron schedules gain a new 2-hour recurrence slug.

## Test Plan
- Unit:
  - settings defaults/sanitization for recurring off, 2-hour interval, and per-source overrides
  - source registry resolution for config definitions vs effective enabled sources
  - duplicate checker identity matching with external ID and source URL fallback
  - post writer skip/update/insert branching from content fingerprint results
  - MyJobMag normalization for `Remote`, `Full Time , Remote`, and unknown employment-type values

- Integration/E2E:
  - first import inserts posts
  - second import with identical fixtures yields `skipped_count > 0` and `updated_count = 0`
  - changed fixture updates only the affected listing
  - no duplicate insert when title/description changes on the same identity
  - source toggle overrides exclude disabled sources from manual and scheduled runs

- Runtime smoke:
  - with Local DB available, run manual import through `localwp`, inspect run records and `job_listing` results, and verify the 2-hour cron schedule registers correctly

## Assumptions
- Chosen model: DB override for source enablement.
- Fresh installs should be explicit opt-in; upgrades should preserve current active behavior through one-time seeding rather than forcing everything off.
- Manual import remains “run all enabled sources now”.
- Unchanged matched jobs should be true no-ops in persistence terms, not “lightweight updates”.
- The current environment cannot fully confirm manual import today because LocalWP DB access is failing; implementation should include runtime verification once that dependency is healthy.
