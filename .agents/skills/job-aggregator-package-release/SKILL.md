---
name: job-aggregator-package-release
description: Use when packaging the custom job-aggregator WordPress plugin into a release zip for manual upload, WP-CLI install, or handoff to a live WordPress site.
---

# Job Aggregator Package Release

Use this skill when:
- creating a release zip for `wp-content/plugins/job-aggregator/`
- preparing the plugin for manual upload in WordPress admin
- preparing the plugin for `wp plugin install <zip>`
- verifying that the zip contains only runtime files
- uploading the packaged zip to the matching GitHub release tag asset list

## Packaging Rules

- Build the archive from `wp-content/plugins/` so the top-level folder inside the zip is `job-aggregator/`.
- Exclude development-only files and folders:
  - `tests/`
  - `vendor/`
  - `.phpunit.result.cache`
  - `phpunit*.xml*`
  - `phpcs.xml.dist`
  - `composer.json`
  - `composer.lock`
- Keep runtime files:
  - `job-aggregator.php`
  - `src/`
  - `config/`
- Do not commit the generated zip to git.

## Default Output Name

- Prefer `job-aggregator-<version>.zip`.
- Read the version from the plugin header in `wp-content/plugins/job-aggregator/job-aggregator.php`.
- Write the archive at the repo root unless the user asks for another location.

## Command

From the repository root:

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

## Verification

After packaging:
- run `ls -lh <zip-path>` to confirm the archive exists
- run `unzip -l <zip-path>` and confirm the archive contains `job-aggregator/` at the top level
- confirm excluded dev files are absent
- run `git status --short` and confirm the zip is not tracked

## GitHub Release Asset Upload (Optional)

When requested, upload the generated zip to the matching GitHub release tag:

- Release repo: `allwelldotdev/wp-jobaggregator`
- Tag format: `v<version>` (for example plugin version `0.6.0` -> tag `v0.6.0`)
- Asset file: `job-aggregator-<version>.zip`

Command:

```bash
gh release upload v<version> job-aggregator-<version>.zip --repo allwelldotdev/wp-jobaggregator
```

If the asset already exists, replace it:

```bash
gh release upload v<version> job-aggregator-<version>.zip --repo allwelldotdev/wp-jobaggregator --clobber
```

Post-upload verification:
- run `gh release view v<version> --repo allwelldotdev/wp-jobaggregator --json assets,url`
- confirm `job-aggregator-<version>.zip` is present in `assets`

## Install Paths

- WordPress admin upload: `Plugins -> Add New -> Upload Plugin`
- WP-CLI:

```bash
wp plugin install /path/to/job-aggregator-<version>.zip --activate
```

## Guardrails

- If runtime structure changes, update this skill in the same change.
- If the plugin begins requiring Composer runtime dependencies, stop excluding `vendor/` and re-evaluate the package contents.
- Do not create release tags or publish release notes in this skill; only upload/verify package assets when requested.
