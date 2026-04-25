# Mandatory Agent Workflow

These rules are mandatory for any agent working in this repository.

## Read Order
- Start with `AGENTS.md`.
- Then read `.agents/policies/mandatory-agent-workflow.md` in full.
- For product and architecture context, read `.agents/docs/conversation-decisions.md`, `.agents/docs/architecture-blueprint.md`, and `.agents/docs/PROJECT_STATUS.md`.
- Open `.agents/docs/conversation.yaml` only when the summarized docs are insufficient.

## Command Safety
- Before running any command you are not confident about, check in with the user first.
- When implementation details may have changed or are uncertain, verify them against current practical docs, official references, or installed source code before implementing.
- Prefer WP-CLI for repeatable WordPress operations when it is the appropriate tool.

## Runtime Rules
- For this codebase, prefer the `localwp` wrapper over system `php`, `wp`, `composer`, or direct MySQL client usage when Local runtime context matters.
- Use the Local-generated `ssh-entry` script to load the correct environment instead of hardcoding Local PHP or MySQL version paths.
- Prefer `wp db ...` commands before using the raw `mysql` client.
- Default `localwp` behavior is to run from the WordPress root defined in the `ssh-entry` script.
- For Composer commands that target the plugin, the agent must use `localwp --cwd wp-content/plugins/job-aggregator ...` so Composer resolves the plugin's `composer.json`.

## Code Boundaries
- Put ingestion, normalization, scheduling, deduplication, logging, and tests in `wp-content/plugins/job-aggregator/`.
- Keep job presentation changes in `wp-content/themes/divi-child-theme/`.
- Treat `wp-admin/`, `wp-includes/`, `wp-content/mu-plugins/`, the Divi parent theme, and third-party plugins as external code unless the task explicitly requires touching them.
- Use the existing `wp-job-manager` `job_listing` model instead of introducing a parallel jobs CPT unless there is a demonstrated schema gap.
- Keep new ingestion logic out of `wp-content/themes/divi-child-theme/functions.php` unless the change is purely presentational.

## Configuration Rules
- Keep secrets such as API keys in environment-specific config such as `wp-config.php`.
- Keep non-sensitive source definitions such as RSS feed URLs, source labels, request defaults, and source metadata in `wp-content/plugins/job-aggregator/config/sources.php`.
- Runtime source enable/disable state must be managed through `job_aggregator_settings[source_states]` so imports use admin-managed overrides instead of direct config edits.
- Do not commit live secrets into plugin source files, docs, or test fixtures.

## Git Scope
- Keep repository context focused on custom work.
- Preserve `.gitignore` so WordPress core, uploads, caches, host-managed files, and third-party code stay out of version control unless explicitly needed.
- Non-essential child-theme assets such as screenshots, theme readmes, backup files, and the current `webfonts/` directory should remain ignored unless a task explicitly requires versioning them.

## Commenting Standard
- Use comments sparingly and holistically.
- Comment code groups, invariants, integration boundaries, or non-obvious actions.
- Do not add comments to restate straightforward code line by line.

## Mandatory Post-Change Quality Gates
- After any code change in `wp-content/plugins/job-aggregator/`, use the tooling defined in `wp-content/plugins/job-aggregator/composer.json`.
- Run formatting first, then linting.
- Use these commands from `wp-content/plugins/job-aggregator/`:
  - `localwp --cwd wp-content/plugins/job-aggregator composer format`
  - `localwp --cwd wp-content/plugins/job-aggregator composer lint`
- If Composer dependencies are installed and tests are available for the touched code, run tests after linting:
  - `localwp --cwd wp-content/plugins/job-aggregator composer test`
- Run tests only when tests are available.
- If required tooling is unavailable in the environment, state that explicitly in the handoff.
- Do not claim lint, format, or test success unless the commands actually ran successfully.

## Documentation Maintenance
- When architecture, workflow, runtime approach, or repository structure changes, update the relevant files in `.agents/` in the same change.
- Important durable workflow changes must be documented starting from `AGENTS.md`, then in the appropriate `.agents/docs/`, `.agents/policies/`, or external wrapper README file.
- Keep `AGENTS.md` short; put durable workflow and policy detail in `.agents/policies/`.
