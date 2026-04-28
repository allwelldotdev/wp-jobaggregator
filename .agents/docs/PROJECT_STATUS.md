# Project Status

## Current State
- Local WordPress site is present at repository root.
- Git has been initialized with active custom-plugin development commits.
- Original product intent has been captured in `.agents/docs/conversation.yaml`.
- The site already includes `wp-job-manager`.
- The active customization layer appears to be `wp-content/themes/divi-child-theme/`.
- Single job listings are already customized in the child theme.
- A first-pass `job-aggregator` plugin scaffold now exists at `wp-content/plugins/job-aggregator/`.
- Local PHP, WP-CLI, and Composer commands for this project should now be run through the `localwp` wrapper backed by Local's generated `ssh-entry` script.

## What Exists Today
- WordPress core files at repo root.
- Managed-host mu-plugins under `wp-content/mu-plugins/`.
- Third-party plugins including `wp-job-manager`, `wp-all-import`, `advanced-custom-fields`, `seo-by-rank-math`, and others.
- Divi parent theme plus a custom child theme.
- Runtime/generated directories such as `wp-content/uploads/`, `wp-content/et-cache/`, and `wp-content/wpaas-updates-log/`.
- A plugin scaffold with cron registration, source registry, RSS and Jooble source classes, duplicate checking, and `job_listing` persistence.
- Source architecture now supports format-level + source-level classes under `src/Sources/RSS` and `src/Sources/API`, including source-specific RSS normalization for MyJobMag, RemoteOK, We Work Remotely, and Hot Nigerian Jobs.
- Source catalog/default definitions stay in `config/sources.php`, while effective source enablement is now managed in `job_aggregator_settings[source_states]` from the admin Settings screen.
- Import orchestration now runs in resumable batches using a start hook and a process hook instead of processing all sources in one request.
- Custom run-state tables are now part of the plugin runtime:
  - `{prefix}job_aggregator_runs`
  - `{prefix}job_aggregator_run_sources`
  - `{prefix}job_aggregator_normalization_signals`
  - `{prefix}job_aggregator_listing_origins`
- Source progress/checkpoints, retries, and per-run counters are now persisted in custom tables and surfaced in admin screens.
- Automated imports now default `job_listing_category` assignment to slug `other-automated`, with term auto-create on write if missing.
- Upsert behavior now separates stable identity matching from change detection so unchanged jobs are skipped without touching post/meta/taxonomy timestamps.
- MyJobMag title normalization now strips trailing company suffixes using standalone case-insensitive ` at ` splitting.
- Cross-source dedup now blocks duplicates for runtime-enabled Nigeria-default sources by normalized title + company matching, independent of ingestion order.
- Plugin settings now include:
  - `delete_expired_job_listings` for WPJM-native expired-job trashing behavior.
  - `run_retention_days` and `run_keep_min` for run-history retention policy control.
- Plugin runtime now includes daily history cleanup (`job_aggregator_cleanup_history`) with two-stage lifecycle:
  - old terminal runs are first marked `archived`.
  - archived runs beyond grace window are hard-deleted with related run-source rows.
- WordPress admin UI now exists for:
  - Manual import trigger.
  - Run history and per-run source summaries.
  - Monitoring source status, failures, and queued follow-up batches.
  - Monitoring normalization-signal rows for unmatched source values.
  - Recurring schedule and queue pacing settings.
- Monitoring "Recent Failures" now supports fixed pagination at 20 rows/page.
- Admin module is now split into focused classes under `src/Admin/` (`Pages/`, `Support/`, settings registrar, and manual run controller) with `AdminPages.php` acting as a thin coordinator.
- A standalone `localwp-wrapper` repo at `/home/allwell/Code/wp/localwp-wrapper` with the `localwp` executable symlinked into `~/Code/wp/bin/` and `~/.local/bin/`.

## What Does Not Exist Yet
- No live source credentials or production feed URLs are configured.
- Integration test harness for full WordPress persistence behavior is still not in place, though parser/normalization unit tests now exist.
- Broad source-specific mapping coverage beyond MyJobMag RSS, RemoteOK RSS, We Work Remotely RSS, Hot Nigerian Jobs RSS, and Jooble API is still pending.
- Direct Local `mysql` CLI access still needs OS compatibility libraries if you want to use that binary instead of `wp db ...`.

## Current Decision
- Version control should concentrate on custom code and agent docs, not WordPress core, uploads, cache directories, or bundled third-party code.
- Sensitive values belong in `wp-config.php`; non-sensitive source definitions belong in `wp-content/plugins/job-aggregator/config/sources.php`.
- For this repo, agents should prefer the `localwp` wrapper over system PHP/WP-CLI/Composer.

## Immediate Next Build Targets
1. Configure the first real RSS feed and API key.
2. Add more source-specific mapping classes for additional feeds/APIs using the new format-level source layout.
3. Add integration tests for `PostWriter` taxonomy assignment and normalization-signal persistence against a WordPress test runtime.
4. Add admin UX for viewing and exporting archived run history when needed.
