# Architecture Blueprint

## Goal
Add a maintainable automation layer that imports jobs from RSS feeds and job APIs into the existing WordPress job experience, using the current `wp-job-manager` setup instead of building a parallel jobs system.

## Current Repo Reality
- WordPress site lives at repo root.
- `wp-content/plugins/wp-job-manager/` is present, so `job_listing` already exists.
- `wp-content/themes/divi-child-theme/single-job_listing.php` already customizes single job presentation.
- `wp-content/themes/divi-child-theme/functions.php` contains site-specific code unrelated to ingestion.
- GoDaddy/managed-host mu-plugins and caches are present and should not be treated as application code.

## Recommended Custom Code Layout
```text
wp-content/
  plugins/
    job-aggregator/
      job-aggregator.php
      composer.json
      config/
        sources.php
      src/
        Plugin.php
        Cron/
          Scheduler.php
        Sources/
          SourceInterface.php
          AbstractSource.php
          RSS/
            AbstractRssSource.php
            RssFeedSource.php
            MyJobMagRssSource.php
            RemoteOkRssSource.php
            WeWorkRemotelyRssSource.php
          API/
            AbstractApiSource.php
            JoobleApiSource.php
        Jobs/
          JobDTO.php
          Normalizer.php
          DuplicateChecker.php
          PostWriter.php
          NormalizationSignalStore.php
          Expirer.php
        Admin/
          AdminPages.php
          ManualRunController.php
          SettingsRegistrar.php
          Pages/
            DashboardPage.php
            RunsPage.php
            MonitoringPage.php
          Support/
            AdminView.php
        Support/
          Logger.php
          HttpClient.php
      tests/
        Unit/
        Integration/
        fixtures/
```

## Responsibility Split
- Plugin: data ingestion, normalization, deduplication, scheduling, logging, expiry, admin controls, and tests.
- Child theme: visual rendering for `job_listing` entries and any front-end adjustments specific to the active site.
- `wp-job-manager`: remains the canonical storage/display layer for job posts unless proven insufficient.

## Data Flow
1. Recurring cron hook (`job_aggregator_start_batch`) starts or resumes an import run.
2. Run manager writes a run row and source state rows into custom tables.
3. Single-event cron hook (`job_aggregator_process_batch`) processes one source chunk at a time.
4. Source adapters return a batch result (`jobs`, `has_more`, next checkpoint).
5. Duplicate checker and post writer create/update `job_listing` posts for that chunk only.
6. Checkpoint store persists source progress, retries, and failure details after each chunk.
7. Processor schedules the next single-event chunk when work remains.
8. Run manager marks the run `completed` or `partial` when all source work is exhausted.

## Key Implementation Decisions
- Use a source interface so new RSS/API providers can be added without changing the aggregation loop.
- Treat `config/sources.php` as the source catalog/default definition and apply runtime source on/off state from plugin settings (`job_aggregator_settings[source_states]`).
- Use a normalized DTO to isolate remote schema differences from WordPress persistence.
- Match existing posts using a stable identity key (`source_key + external_id` with source-URL fallback) and use a separate content fingerprint to skip unchanged updates.
- Keep secrets out of committed files; read API keys from `wp-config.php` constants or equivalent environment-specific config.
- Prefer a real server cron hitting `wp-cron.php` in production if reliable scheduling is required.
- Persist operational run/source state in custom tables (`{prefix}job_aggregator_runs`, `{prefix}job_aggregator_run_sources`) for reliable checkpointing and admin visibility.
- Persist normalization/mapping drift signals in `{prefix}job_aggregator_normalization_signals` for source-specific mapping iteration.
- Separate recurring-start scheduling from per-chunk continuation scheduling to keep each request short and reduce timeout pressure.
- Use per-run lock state to avoid overlapping workers processing the same run concurrently.

## Testing Strategy
- Unit tests for source parsers, normalization, and duplicate hashing.
- Integration tests for writing `job_listing` posts and updating existing listings.
- Fixture-based tests for RSS and API payloads so test runs do not depend on live services.

## Non-Goals
- Do not commit WordPress core, uploads, caches, or host-managed code to this repo.
- Do not move ingestion logic into the child theme.
- Do not create a second jobs CPT unless `wp-job-manager` cannot satisfy a validated requirement.
