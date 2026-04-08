# AGENTS

Repository purpose: local `tekseries` WordPress site for automated RSS/API job ingestion into the existing `wp-job-manager` `job_listing` flow.

Must follow:
- `.agents/policies/mandatory-agent-workflow.md`

Read next:
- `.agents/docs/conversation-decisions.md`
- `.agents/docs/architecture-blueprint.md`
- `.agents/docs/PROJECT_STATUS.md`
- `.agents/skills/localwp-runtime/SKILL.md` when running PHP, WP-CLI, Composer, or WordPress DB commands
- `.agents/docs/conversation.yaml` only when the summaries are insufficient

Primary code locations:
- `wp-content/plugins/job-aggregator/`
- `wp-content/themes/divi-child-theme/`

Runtime rule:
- Prefer the `localwp` wrapper for PHP, WP-CLI, Composer, and WordPress DB commands in this codebase instead of system binaries.
- You can use the internet or Context7 to access up-to-date external docs when needed.
