# Project Tree

## Versioned Focus Areas
```text
.agents/
  docs/
    conversation.yaml
    architecture-blueprint.md
    conversation-decisions.md
    PROJECT_STATUS.md
    PROJECT_TREE.md
  policies/
    mandatory-agent-workflow.md
  skills/
    localwp-runtime/
      SKILL.md
    job-aggregator-source-test-ops/
      SKILL.md
    job-aggregator-package-release/
      SKILL.md
    job-aggregator-changelog-release/
      SKILL.md
AGENTS.md
.gitignore
wp-content/
  plugins/
    job-aggregator/
      job-aggregator.php
      config/
        sources.php
      src/
        Plugin.php
        SourceRegistry.php
        Jobs/
        Sources/
        Support/
      tests/
        fixtures/
  themes/
    divi-child-theme/
      functions.php
      single-job_listing.php
      includes/
```

## Important Existing Runtime Structure
```text
wp-content/
  mu-plugins/                      # Host-managed code; do not treat as app code
  plugins/
    wp-job-manager/                # Existing job content model
    wp-all-import/                 # Existing import-related plugin, third-party
    ... other vendor plugins
  themes/
    Divi/                          # Parent theme, third-party
    divi-child-theme/              # Site-specific presentation layer
  uploads/                         # Generated media; gitignored
  et-cache/                        # Generated cache; gitignored
  upgrade/                         # Runtime upgrade artifacts; gitignored
```

## Scaffolded Import Layer
```text
wp-content/plugins/job-aggregator/
  config/
    sources.php                    # Feed URLs and non-secret source config
  src/
    Plugin.php                     # Hooks, cron entrypoint, and manual-run orchestration
    SourceRegistry.php             # Instantiates enabled sources
    Admin/
      AdminPages.php               # Slim coordinator for admin hooks + menu wiring
      ManualRunController.php      # Manual run action handling + admin notices
      SettingsRegistrar.php        # Settings API registration + settings page rendering
      Pages/
        DashboardPage.php
        RunsPage.php
        MonitoringPage.php
      Support/
        AdminView.php              # Shared table/render helpers used by admin pages
    Batch/
      BatchProcessor.php
      BatchRunManager.php
      CheckpointStore.php
      RunLock.php
      SourceBatchResult.php
    Cron/
      Scheduler.php
    Jobs/
      DuplicateChecker.php
      JobData.php
      PostWriter.php               # Writes into job_listing + WPJM meta
    Sources/
      AbstractSource.php
      JoobleApiSource.php
      RssFeedSource.php
      SourceInterface.php
    Support/
      Autoloader.php
      HttpClient.php
      Logger.php
      Settings.php
```
