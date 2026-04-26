# Tekseries Job Aggregator WordPress Site

This repository contains the local Tekseries WordPress site used to develop and release a custom `Job Aggregator` plugin. The plugin imports jobs from configured RSS feeds and APIs into the existing WP Job Manager `job_listing` flow instead of creating a parallel job system.

The repository includes a full local WordPress tree, but version control should stay focused on custom code, release docs, and agent docs. WordPress core, uploads, caches, host-managed files, and third-party plugin code are not the application surface for this project.

## Primary Code

- `wp-content/plugins/job-aggregator/` - ingestion plugin for sources, normalization, batching, persistence, admin controls, and tests.
- `wp-content/themes/divi-child-theme/` - site presentation customizations, including job listing templates.
- `.agents/` - durable project decisions, workflow instructions, implementation plans, and local skills.
- `CHANGELOG.md` - release notes for the Job Aggregator plugin.

## Job Aggregator Plugin

Current plugin version: `0.5.0`.

The plugin imports external jobs into WP Job Manager posts (`job_listing`) using source-specific normalization and duplicate protection.

Implemented capabilities include:

- RSS/API source registry driven by `wp-content/plugins/job-aggregator/config/sources.php`.
- Runtime source-state controls in WordPress admin settings.
- Catalog-disabled source locking so a source with `enabled => false` in config cannot be enabled through admin overrides.
- Resumable batch imports with custom run/checkpoint tables.
- Source-specific RSS normalization for MyJobMag, RemoteOK, We Work Remotely, and Hot Nigerian Jobs.
- Jooble API source scaffold.
- Duplicate identity keys and content fingerprints to avoid duplicate posts and skip unchanged listings.
- WP Job Manager metadata and taxonomy persistence.
- Run history, monitoring screens, normalization-signal tracking, and manual import controls.
- Fixture-driven unit and E2E tests.

## Runtime Model

The plugin stores operational state in custom WordPress tables:

- `{prefix}job_aggregator_runs`
- `{prefix}job_aggregator_run_sources`
- `{prefix}job_aggregator_normalization_signals`

Import flow:

1. A recurring or manual start action creates or resumes a batch run.
2. Source states and checkpoints are recorded per run.
3. A single process event handles one due source chunk at a time.
4. Source adapters return normalized `JobData` objects.
5. Duplicate detection and `PostWriter` create, update, or skip WP Job Manager listings.
6. Follow-up process events are queued while work remains.
7. The run is marked `completed`, `partial`, or `failed`.

## Source Configuration

Non-sensitive source definitions live in:

```text
wp-content/plugins/job-aggregator/config/sources.php
```

Use this file for source keys, drivers, feed URLs, request defaults, batch sizes, and default metadata. Keep secrets out of source files. API credentials belong in environment-specific config such as `wp-config.php` constants.

Important source-state behavior:

- `enabled => true` in `config/sources.php` means the source is available for runtime enablement in admin settings.
- `enabled => false` means the source is cataloged but locked off; admin checkboxes are disabled and server-side sanitization prevents override bypasses.
- Effective runtime enablement is stored in the `job_aggregator_settings[source_states]` option.

## Local Runtime

This codebase is developed against a LocalWP site. Use the [`localwp`](https://github.com/allwelldotdev/localwp-cli-wrapper) shell wrapper for PHP, WP-CLI, Composer, and WordPress DB commands so commands run inside the correct Local site environment.

Common command prefix using [`localwp`](https://github.com/allwelldotdev/localwp-cli-wrapper):

```bash
LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator <command>
```

Examples:

```bash
LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer test

LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer test:e2e

LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator wp eval 'echo get_bloginfo("name");'
```

## Development Workflow

Before changing code, read:

- `AGENTS.md`
- `.agents/policies/mandatory-agent-workflow.md`
- `.agents/docs/conversation-decisions.md`
- `.agents/docs/architecture-blueprint.md`
- `.agents/docs/PROJECT_STATUS.md`

For source onboarding or ingestion tests, also read:

- `.agents/skills/job-aggregator-source-test-ops/SKILL.md`

For release work, read:

- `.agents/skills/job-aggregator-changelog-release/SKILL.md`
- `.agents/skills/job-aggregator-package-release/SKILL.md`

## Testing And Quality Gates

After changes in `wp-content/plugins/job-aggregator/`, run formatting first, then linting, then tests.

```bash
LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer format

LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer lint

LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer test
```

Run all deterministic tests before release:

```bash
LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer test:all
```

Available Composer scripts:

- `composer format` - run PHPCBF with the plugin coding standard.
- `composer lint` - run PHPCS with the plugin coding standard.
- `composer test` / `composer test:unit` - run unit tests.
- `composer test:e2e` - run fixture-based WordPress E2E tests.
- `composer test:all` - run unit and E2E suites.
- `composer test:e2e:live-rss` - run isolated live RSS smoke tests.

Known lint note: the current codebase permits a non-fatal PHPCS warning for `error_log()` in `src/Support/Logger.php`.

## Adding A New RSS Source

1. Add or update the source definition in `config/sources.php`.
2. Add a source-specific class under `src/Sources/RSS/` when generic mapping is insufficient.
3. Wire the driver/key in `src/SourceRegistry.php`.
4. Add unit coverage under `tests/Unit/Sources/RSS/`.
5. Add a fixture under `tests/fixtures/rss/`.
6. Extend `tests/config/sources.integration.php` and `tests/config/sources.integration.updated.php` when fixture E2E coverage is needed.
7. Update `tests/Integration/E2E/RssIngestionE2ETest.php` assertions and cleanup expectations.
8. Run `composer format`, `composer lint`, and the relevant test suites through [`localwp`](https://github.com/allwelldotdev/localwp-cli-wrapper).

Test data must clean up after itself. E2E sources should use the `e2e_` source-key prefix.

## Releases

Release workflow:

1. Update `CHANGELOG.md` with `## [<version>] - <YYYY-MM-DD>`.
2. Bump the plugin header version in `wp-content/plugins/job-aggregator/job-aggregator.php`.
3. Run deterministic tests through LocalWP.
4. Commit release changes with `release(job-aggregator): v<version>`.
5. Create an annotated tag `v<version>`.
6. Push `main` and the tag.
7. Publish the GitHub release.

Latest release:

- `v0.5.0`
- GitHub release: `https://github.com/allwelldotdev/wp-jobaggregator/releases/tag/v0.5.0`

## Packaging

Create an uploadable plugin zip from the repository root:

```bash
cd wp-content/plugins
zip -r ../../job-aggregator-<version>.zip job-aggregator \
  -x "job-aggregator/tests/*" \
     "job-aggregator/vendor/*" \
     "job-aggregator/.phpunit.result.cache" \
     "job-aggregator/phpunit*.xml*" \
     "job-aggregator/phpcs.xml.dist" \
     "job-aggregator/composer.json" \
     "job-aggregator/composer.lock"
```

The package should contain only runtime plugin files:

- `job-aggregator/job-aggregator.php`
- `job-aggregator/src/`
- `job-aggregator/config/`

Generated zips are release artifacts and should not be committed.

## Git Scope And Safety

Do not commit secrets, uploads, caches, LocalWP generated files, WordPress core changes, or third-party plugin/theme changes unless explicitly required.

Keep ingestion logic in the plugin. Keep presentation changes in the child theme. Avoid placing ingestion logic in `functions.php`.

If a working tree has unrelated local changes, preserve them and avoid destructive commands such as `git reset --hard` or file checkout reverts unless explicitly approved.
