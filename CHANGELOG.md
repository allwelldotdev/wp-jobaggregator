# Changelog

All notable changes to this project are documented in this file.

This changelog follows Keep a Changelog principles and Semantic Versioning.

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
