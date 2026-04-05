# Project Status

## Current State
- Local WordPress site is present at repository root.
- Git has been initialized, but there are no commits yet.
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
- Import orchestration now runs in resumable batches using a start hook and a process hook instead of processing all sources in one request.
- Custom run-state tables are now part of the plugin runtime:
  - `{prefix}job_aggregator_runs`
  - `{prefix}job_aggregator_run_sources`
- Source progress/checkpoints, retries, and per-run counters are now persisted in custom tables and surfaced in admin screens.
- WordPress admin UI now exists for:
  - Manual import trigger.
  - Run history and per-run source summaries.
  - Monitoring source status, failures, and queued follow-up batches.
  - Recurring schedule and queue pacing settings.
- Admin module is now split into focused classes under `src/Admin/` (`Pages/`, `Support/`, settings registrar, and manual run controller) with `AdminPages.php` acting as a thin coordinator.
- A standalone `localwp-wrapper` repo at `/home/allwell/Code/wp/localwp-wrapper` with the `localwp` executable symlinked into `~/Code/wp/bin/` and `~/.local/bin/`.

## What Does Not Exist Yet
- No live source credentials or production feed URLs are configured.
- No dedicated test runner or PHPUnit bootstrap exists yet.
- No source-specific field mapping beyond generic RSS and a first-pass Jooble parser exists yet.
- Direct Local `mysql` CLI access still needs OS compatibility libraries if you want to use that binary instead of `wp db ...`.

## Current Decision
- Version control should concentrate on custom code and agent docs, not WordPress core, uploads, cache directories, or bundled third-party code.
- Sensitive values belong in `wp-config.php`; non-sensitive source definitions belong in `wp-content/plugins/job-aggregator/config/sources.php`.
- For this repo, agents should prefer the `localwp` wrapper over system PHP/WP-CLI/Composer.

## Immediate Next Build Targets
1. Configure the first real RSS feed and API key.
2. Add per-source mapping overrides for feeds whose fields do not fit the generic RSS parser.
3. Add PHPUnit scaffolding and fixture-backed parser tests.
4. Add expiry and archival policy decisions for stale listings.
