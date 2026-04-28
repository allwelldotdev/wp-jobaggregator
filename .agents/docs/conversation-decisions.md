# Conversation Decisions

## Confirmed Product Direction
- Build an automation process that imports jobs from RSS feeds and job APIs into an existing live WordPress site.
- Develop locally in LocalWP against a local copy of the production site.
- Implement the automation in PHP.
- Scale to many sources, so source onboarding must be efficient and low-friction.
- Run imports on a schedule using WP-Cron, with the option to back it with a real system cron for reliability.

## Structural Decisions Derived From The Conversation
- Use a custom plugin for ingestion logic instead of putting automation in the theme.
- Use a declarative source registry so new RSS feeds and APIs can be added with minimal code churn.
- Keep source catalog/default definitions in `config/sources.php`, but control effective runtime source enablement through admin-managed settings overrides.
- Introduce a shared source contract for RSS and API connectors.
- Normalize remote payloads into a common internal job shape before persistence.
- Add duplicate prevention before creating `job_listing` posts.
- Add logging for runs, source failures, and import counts.
- Add job expiry handling so stale listings do not accumulate indefinitely.
- Cover source parsing and persistence with automated tests.
- Use resumable chunked imports so one request processes only one source page/chunk at a time.
- Persist checkpoints, source status, retries, and run counters in custom plugin tables.
- Queue follow-up single cron events while work remains instead of keeping one long-running request open.
- Use cross-source dedup for runtime-enabled Nigeria-default sources by normalized `(title, company)` matching while retaining same-source identity upsert logic.
- Keep a source-origin index table for cross-source dedup lookups so duplicate checks stay stable and queryable over time.
- Use WPJM-native expired-listing deletion behavior behind an admin toggle (`job_manager_delete_expired_jobs`) so cleanup is trash-first.
- Apply run history retention with a two-stage lifecycle for plugin tables: `completed/partial/failed -> archived -> hard delete`.
- Exclude archived runs from default admin run/failure listings and paginate Monitoring recent failures at a fixed page size.

## Repo-Specific Interpretation
- Because `wp-job-manager` is already installed, the practical target is the existing `job_listing` content model.
- Because the child theme already overrides single job output, display work belongs there while ingestion stays in the plugin.
- Because this repo includes full WordPress core and many vendor plugins, git tracking should focus on custom code and agent docs rather than mirroring the entire deployed site.

## Operational Guidance Already Implied
- Prefer WP-CLI for search-replace, cron testing, and other repeatable WordPress tasks.
- Keep API credentials out of committed source.
- Use fixtures instead of live network calls in tests.
