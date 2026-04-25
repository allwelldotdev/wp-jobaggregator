---
name: job-aggregator-changelog-release
description: Use when preparing a Job Aggregator plugin release with CHANGELOG.md updates, semantic version bumping, git tagging, and GitHub release publishing.
---

# Job Aggregator Changelog + Release

Use this skill when:
- shipping a new plugin release for `wp-content/plugins/job-aggregator/`
- updating `CHANGELOG.md` for release notes
- bumping plugin version in plugin header
- creating git tags and publishing a GitHub release

## Release System (Codebase Standard)

For this codebase, follow this order:
1. Update release notes in root `CHANGELOG.md`.
2. Bump plugin version in `wp-content/plugins/job-aggregator/job-aggregator.php`.
3. Validate plugin tests in LocalWP runtime.
4. Commit release changes.
5. Create and push annotated git tag.
6. Publish GitHub release for the tag.
7. Verify release exists and clean temporary artifacts.

## Changelog Rules

- Changelog file path: `CHANGELOG.md` at repository root.
- Use section heading style:
  - `## [<version>] - <YYYY-MM-DD>`
- Include at minimum:
  - Release summary (plugin name, release type, old/new version)
  - `Added`, `Changed`, `Fixed`
  - Compatibility notes (breaking changes, migration requirement)
  - Concrete metrics (files touched, diff stats, tests touched, impacted areas)
- Date must use the actual release date (`today`) in `YYYY-MM-DD`.

## Version Bump Rules

- Read current plugin version from:
  - `wp-content/plugins/job-aggregator/job-aggregator.php` (`Version:` header)
- Apply semantic versioning:
  - patch: `X.Y.Z -> X.Y.(Z+1)`
  - minor: `X.Y.Z -> X.(Y+1).0`
  - major: `X.Y.Z -> (X+1).0.0`
- Ensure changelog version and plugin header version match.

## Validation Gates

Run tests through LocalWP wrapper (not system binaries):

```bash
LOCALWP_SSH_ENTRY=~/.config/Local/ssh-entry/rYLMOKnKH.sh \
localwp --cwd wp-content/plugins/job-aggregator composer test:all
```

If `test:all` fails, fix or document blocker before release.

## Commit + Tag Convention

- Commit message format:
  - `release(job-aggregator): v<version>`
- Annotated tag format:
  - `v<version>`
- Tag message format:
  - `Release v<version> (<YYYY-MM-DD>)`

Commands:

```bash
git add -A
git commit -m "release(job-aggregator): v<version>"
git tag -a v<version> -m "Release v<version> (<YYYY-MM-DD>)"
git push origin main
git push origin v<version>
```

## GitHub Release Publishing

Preferred: create release from local changelog content.

```bash
gh release create v<version> \
  --repo allwelldotdev/wp-jobaggregator \
  --title "v<version>" \
  --notes-file CHANGELOG.md
```

Then verify using GitHub MCP:
- `get_release_by_tag`
- `list_releases`

Note:
- If GitHub MCP does not support release creation in-session, use `gh` CLI for create/publish and use MCP for verification.

## Safety + Scope Guardrails

- Do not include transient artifacts in release commits (for example `.playwright-mcp/`).
- Keep repo scratch/test temp data out of release commits unless intentionally part of release assets.
- Do not use destructive git commands (`reset --hard`, checkout file reverts) unless explicitly requested.
- If unrelated unexpected changes appear, stop and ask before proceeding.

## Post-Release Checks

- Verify HEAD has both:
  - `origin/main`
  - `tag: v<version>`
- Confirm release URL resolves on GitHub:
  - `https://github.com/allwelldotdev/wp-jobaggregator/releases/tag/v<version>`
- Confirm working tree is clean except explicitly ignored/untracked local scratch files.

## Optional Follow-Ups

- Package zip for manual install using `job-aggregator-package-release` skill.
- Update project status docs if release includes architecture/workflow changes.
