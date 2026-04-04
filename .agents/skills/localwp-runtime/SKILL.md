---
name: localwp-runtime
description: Use when running PHP, WP-CLI, Composer, or related WordPress commands for this codebase. Prefer the `localwp` wrapper over system `php`, `wp`, or `composer` so commands run inside Local's site environment loaded from a Local `ssh-entry` script.
---

# LocalWP Runtime

Use this skill whenever repo work requires `php`, `wp`, `composer`, or database-related WordPress commands.

## Default Rule
- For this codebase, do not default to system `php`, `wp`, or `composer`.
- Prefer `localwp` so commands inherit Local's `PHPRC`, `WP_CLI_CONFIG_PATH`, `MYSQL_HOME`, `LD_LIBRARY_PATH`, Local PHP path, and site root.

## Wrapper
- Command: `localwp`
- Source file: `/home/allwell/Code/wp/bin/localwp`
- User PATH symlink: `/home/allwell/.local/bin/localwp`
- Wrapper README: `/home/allwell/Code/wp/bin/README.md`

## Required Input
Pass the site's Local ssh-entry path, or export it once:
```bash
localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" wp plugin list
```

```bash
export LOCALWP_SSH_ENTRY="$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh"
localwp wp plugin list
```

Linux example ssh-entry path:
```bash
~/.config/Local/ssh-entry/rYLMOKnKH.sh
```

## Working Directory Rules
- Default: `localwp` runs commands from the WordPress root defined in the Local `ssh-entry` script.
- Use `--cwd` to override the working directory for one invocation only.
- Relative `--cwd` values are resolved from the WordPress root.
- Absolute `--cwd` values are also accepted.

Examples:
```bash
localwp wp plugin list
localwp --cwd wp-content/plugins/job-aggregator composer install
localwp --cwd "/home/allwell/Local Sites/tekseriescom/app/public/wp-content/plugins/job-aggregator" composer lint
```

## Commands To Prefer
### Context and state
```bash
localwp wp --info
localwp wp core version
localwp wp option get siteurl
localwp wp option get home
localwp wp plugin list
localwp wp theme list
localwp wp cron event list
```

### Plugin-local Composer and PHP commands
For plugin Composer commands, always scope to the plugin directory with `--cwd`:
```bash
localwp --cwd wp-content/plugins/job-aggregator composer install
localwp --cwd wp-content/plugins/job-aggregator composer lint
localwp --cwd wp-content/plugins/job-aggregator composer format
localwp --cwd wp-content/plugins/job-aggregator composer test
localwp --cwd wp-content/plugins/job-aggregator php -v
```

### Useful repo commands
```bash
localwp wp post list --post_type=job_listing --fields=ID,post_title,post_status,post_date --format=table
localwp wp post meta list <POST_ID>
localwp wp option get job_aggregator_run_log --format=json
localwp wp eval "var_dump( defined( 'JOB_AGGREGATOR_JOOBLE_API_KEY' ) );"
```

### Search-replace
```bash
localwp wp search-replace 'https://production.example.com' 'https://tekseries.local' --skip-columns=guid
```

## `wp db` Commands
Prefer these before reaching for the raw `mysql` client:
```bash
localwp wp db check
localwp wp db tables
localwp wp db query "SHOW TABLES LIKE 'wp_%';"
localwp wp db size
localwp wp db export /tmp/tekseries-local.sql
```

For this repo, useful examples are:
```bash
localwp wp db query "SELECT option_name FROM wp_options LIMIT 5;"
localwp wp db query "SHOW TABLES LIKE '%job%';"
```

## Binary Path Inspection
To inspect what binary Local is actually resolving inside the wrapper, use:
```bash
localwp bash -lc 'command -v php'
localwp bash -lc 'command -v wp'
localwp bash -lc 'command -v composer'
localwp bash -lc 'command -v mysql'
```

Do not use command substitution like `which $(localwp mysql)` for this purpose.

## Guardrails
- If the Local ssh-entry path changes, update `LOCALWP_SSH_ENTRY`.
- If direct `mysql` fails due to missing OS libraries, prefer `wp db ...` first.
- For plugin changes in `wp-content/plugins/job-aggregator/`, run quality gates through `localwp --cwd wp-content/plugins/job-aggregator ...`.
- Use tests only when tests exist.
