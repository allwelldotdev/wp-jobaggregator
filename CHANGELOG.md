# Changelog

All notable changes to this project are documented in this file.

This changelog follows Keep a Changelog principles and Semantic Versioning.

## [0.5.0] - 2026-04-26

### Release Summary
- Plugin: `Job Aggregator`
- Release type: `Minor`
- Previous version: `0.4.0`
- New version: `0.5.0`

### Added
- Hot Nigerian Jobs RSS source normalization with source-specific filtering, title/company parsing, description-based location extraction, and expiry derivation.
- Hot Nigerian Jobs fixture coverage for unit and RSS E2E ingestion tests.
- Implementation plan for Hot Nigerian Jobs RSS normalization under `.agents/docs/tmp/implementations/`.

### Changed
- Source registry now resolves `hotnigerianjobs` to a dedicated source class instead of the generic RSS mapper.
- Admin source-state controls now respect catalog-disabled sources by disabling their runtime checkboxes.
- Runtime source-state resolution now prevents stale or forged settings from enabling sources marked `enabled => false` in `config/sources.php`.
- MyJobMag RSS location normalization now maps source location `All` to the configured default location.
- Project status and source-test operation docs now include Hot Nigerian Jobs source coverage.

### Fixed
- Catalog-disabled sources can no longer be enabled from plugin settings or by persisted source-state overrides.

### Metrics
- Files touched under plugin scope: `14` (`11` modified, `3` added).
- Net tracked plugin diff before new untracked files: `+284` lines (`284` insertions, `57` deletions across tracked diffs).
- Test files touched under plugin scope: `7` (`5` modified, `2` added).
- Areas impacted: Admin UI, settings model, source registry, MyJobMag RSS normalization, Hot Nigerian Jobs RSS normalization, RSS fixture E2E tests, source docs.

### Compatibility
- Breaking changes: `None`.
- Database migration required: `No`.

## [0.4.0] - 2026-04-25

### Release Summary
- Plugin: `Job Aggregator`
- Release type: `Minor`
- Previous version: `0.3.0`
- New version: `0.4.0`

### Added
- Runtime source-state controls in Admin settings for per-source run toggles.
- Settings bootstrap helpers and defaults seeding for source states.
- A `2-hour` scheduler recurrence for automated imports.
- Unit test support stubs and new source registry/duplicate checker coverage.

### Changed
- Source resolution flow now separates configured source states from effective run-time enabled sources.
- Import write behavior now includes fingerprint-based no-op handling and explicit skipped accounting.
- Source normalization for MyJobMag RSS remote/apply links improved.
- Admin settings UI now renders source-state controls with targeted table layout styling.

### Fixed
- `Run Import Now` buttons no longer render as always disabled.
- Runtime source-state checkbox names now serialize and persist correctly on Save.
- Runtime source-state field layout is scoped only to its own settings row.

### Metrics
- Files touched under plugin scope: `21` (`15` modified, `6` added).
- Net plugin diff: `+636` lines (`796` insertions, `160` deletions).
- Test files touched under plugin scope: `8` (`2` modified, `6` added).
- Areas impacted: Admin UI, scheduler, source registry, duplicate/upsert path, settings model, RSS normalization, tests.

### Compatibility
- Breaking changes: `None`.
- Database migration required: `No`.
