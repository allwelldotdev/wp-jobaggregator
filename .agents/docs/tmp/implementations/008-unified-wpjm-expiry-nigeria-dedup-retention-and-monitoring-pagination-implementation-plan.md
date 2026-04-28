# Unified Plan: WPJM Expiry Controls + Nigeria Dedup + Retention + Monitoring Pagination

## Summary
Build one cohesive release that:
- normalizes MyJobMag titles (`before " at "`)
- prevents Nigeria cross-source duplicates across all enabled Nigeria-default sources
- adds WPJM auto-delete toggle in Settings (using WPJM-native behavior)
- adds intelligent run/failure retention with trash-like two-stage cleanup for plugin tables
- paginates Monitoring -> Recent Failures at fixed 20 rows/page

## Key Implementation Changes

### WPJM auto-delete toggle (Settings UI)
- Add checkbox `delete_expired_job_listings` (default `false`) to Job Aggregator Settings.
- Wire to WPJM filter: `job_manager_delete_expired_jobs`.
- Behavior: WPJM moves old expired `job_listing` posts to Trash (`wp_trash_post`), then WordPress handles permanent deletion via `EMPTY_TRASH_DAYS`.
- Add helper text clarifying this is trash-first, not immediate hard delete.

### MyJobMag title normalization
- In `MyJobMagRssSource`, set `JobData::title` to substring before standalone case-insensitive ` at `.
- Leave title unchanged when separator is absent.
- Keep current company extraction logic unchanged (only title normalization here).

### Nigeria group cross-source dedup
- Duplicate rule: same normalized title + same normalized company name, but different source.
- Scope: all runtime-enabled sources where `defaults['location'] === 'Nigeria'`.
- Apply in all directions (not source-order dependent).
- Keep existing identity dedup (`source_key + external_id/source_url`) for same-source upserts.
- Add source-origin persistence table (index-backed) for fast lookup:
  - `{prefix}job_aggregator_listing_origins`
  - stores `post_id`, `source_key`, `group_key`, `title_norm`, `company_norm`, timestamps
  - unique/indexes for `(post_id, source_key)` and `(group_key, title_norm, company_norm)`.

### Run/failure retention with trash-like lifecycle for plugin tables
- Add settings:
  - `run_retention_days` default `62`
  - `run_keep_min` default `750`
- Add daily cleanup cron hook.
- Two-stage cleanup (WP Trash analogue for custom tables):
  - Stage 1 (soft): mark old terminal runs as `archived` (not deleted yet), respecting keep-cap.
  - Stage 2 (hard): permanently delete archived runs older than archive grace window (default 30 days), cascading delete corresponding `run_sources`.
- Exclude archived runs/sources from normal admin listings and failure views by default.
- This gives reversible operational buffer before hard delete, similar intent to Trash.

### Monitoring pagination
- Add pagination to Recent Failures table with fixed page size `20`.
- Add total-count query for failures and `failures_paged` query arg handling.

## Public/API/Data Changes
- `job_aggregator_settings` new keys:
  - `delete_expired_job_listings` (`0|1`)
  - `run_retention_days` (int)
  - `run_keep_min` (int)
- New cron hook: `job_aggregator_cleanup_history`.
- New table: `{prefix}job_aggregator_listing_origins`.
- Run lifecycle expands with `archived` status (internal).

## Test Plan

### Unit
- MyJobMag title split cases (valid split, no split, word-boundary safety).
- Nigeria dedup matcher normalization and cross-source blocking.
- Settings sanitization/defaults for new fields.
- Retention selector logic: age cutoff + keep-cap + archive/hard-delete transitions.

### Integration/E2E
- Fixture pair for MyJobMag/HotNigerianJobs duplicate title+company, verify second source is skipped in either ingestion order.
- Multi-source Nigeria group scenario (beyond two sources) for all-direction dedup.
- Monitoring failures pagination page slicing and counts.
- Retention cron effects on runs/run_sources visibility and hard deletion.

### Manual
- Enable `delete_expired_job_listings`, confirm filter returns true and WPJM expired jobs move to Trash.
- Verify archived rows disappear from default admin tables, then are hard-deleted after grace window.

## Assumptions
- Nigeria group membership is exact match on `defaults['location'] === 'Nigeria'`.
- Soft-delete for custom tables uses `archived` status (no separate archive table).
- Archive grace window defaults to 30 days unless later exposed as a setting.
