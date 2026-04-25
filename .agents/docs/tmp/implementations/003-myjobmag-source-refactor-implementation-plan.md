### 003 Implementation Plan: Source-Specific Fetch/Normalization + MyJobMag RSS Onboarding

#### Summary
- [x] Discovery complete: reviewed workflow/docs, current source architecture, MyJobMag sample payload (`aggregate_feed.json` first 100+ lines), and WP taxonomy state.
- [x] Confirmed via WP-CLI (`localwp`) that `job_listing_category` term exists: `Other (automated)` with slug `other-automated` and term_id `103`.
- [ ] Implement source refactor into format-level (`RSS`/`API`) plus source-level classes, then onboard MyJobMag-specific normalization/filtering in its own `fetch_batch`.
- [ ] Add taxonomy category assignment through `JobData` + `PostWriter`, and add observability for unmatched employment-type strings in plugin custom DB + Admin Monitoring UI.
- [ ] Keep rollout forward-only for categories in this pass (new/updated imports only, no backfill migration).

#### Key Implementation Changes (Decision-Complete Checklist)
- [ ] **Refactor source layout and registry wiring**
- Create folders and classes under:
  - `wp-content/plugins/job-aggregator/src/Sources/RssFeedSource.php` -> move/replace with `src/Sources/RSS/AbstractRssSource.php` (shared RSS batching mechanics).
  - `wp-content/plugins/job-aggregator/src/Sources/JoobleApiSource.php` -> move to `src/Sources/API/JoobleApiSource.php`.
  - Add `src/Sources/API/AbstractApiSource.php` (shared API source behavior/helpers to reduce duplication across API drivers).
  - Add `src/Sources/RSS/MyJobMagRssSource.php` with source-specific mapping/filters.
- Update `wp-content/plugins/job-aggregator/src/SourceRegistry.php`:
  - Instantiate RSS/API sources by format + source key/driver.
  - Keep behavior extensible for incremental onboarding (new source class per source).
  - For RSS: use source key dispatch (`myjobmag` -> `MyJobMagRssSource`), fallback to generic RSS class if needed.
  - For APIs: resolve driver to API source class (`jooble` now, additional drivers later) on top of `AbstractApiSource`.
- Keep `SourceInterface` contract unchanged (`fetch_batch`, checkpoints, pagination).

- [ ] **Implement MyJobMag-specific `fetch_batch` normalization and filtering**
- Parse payload fields from feed item shape observed in `aggregate_feed.json`: `id`, `link`, `title`, `position`, `introduction`, `company`, `description`, `contract`, `working_hours`, `location`, `salary`, `pubDate`, `expiryDate`.
- Apply lowercase comparison policy to all string checks.
- Enforce Southeast filter before mapping:
  - Allowed values (normalized lowercase): `abia, anambra, ebonyi, enugu, imo, all, rivers, akwa ibom, cross river, delta, benue, kogi`.
  - If `location` contains comma, split on `,`, trim parts, and match any part against set.
  - Skip item if no match.
- Remote detection (`remote_position=true`) if any of `title`, `position`, `introduction`, `contract`, `working_hours` contains `remote`.
- Expiry mapping:
  - If `expiryDate` present/truthy: parse and map to `JobData::expires_at`.
  - Else parse `pubDate`, add 31 days, map to `expires_at`.
- Employment type mapping (strict allowlist only): `Full Time`, `Freelance`, `Internship`, `Part Time`, `Temporary`, `Contract`, `Onsite`, `Hybrid`.
  - Default `['Full Time']`.
  - Parse from `working_hours` first, then fallback to `contract`.
  - For comma-joined `working_hours`: split+trim; if includes `Full Time`, return array with the other recognized value when available.
  - If nothing maps, keep default and emit normalization signal (below).
- Salary mapping:
  - If feed item `salary` is `null`/empty, do nothing.
  - If `salary` has value, map to `JobData::salary`, set `JobData::salary_currency = 'NGN'`, and set `JobData::salary_unit = 'Monthly'`.
- Map available fields only; use defaults for unavailable `JobData` properties; ignore non-JobData extras.

- [ ] **Add category support in job DTO/persistence**
- Extend `wp-content/plugins/job-aggregator/src/Jobs/JobData.php`:
  - Add `public $job_categories = array( 'other-automated' );` (default slug array for automated imports).
- Do not hardcode `job_categories` in MyJobMag mapper; source-level mapping can override default later when direct feed category mapping is introduced.
- Update `wp-content/plugins/job-aggregator/src/Jobs/PostWriter.php`:
  - If `job_categories` not empty and taxonomy `job_listing_category` exists, assign via `wp_set_object_terms`.
  - Reuse term resolution helper pattern (slug/name safe resolution), ensuring stable assignment to slug `other-automated`.
  - If slug `other-automated` does not exist, create term name `Other (automated)` with slug `other-automated` before assignment.

- [ ] **Track unmatched employment-type values in plugin custom DB + Admin Monitoring**
- Add new table via `BatchRunManager::install_schema()` (dbDelta), e.g. `{prefix}job_aggregator_normalization_signals` with columns:
  - `id`, `source_key`, `signal_type`, `raw_value`, `normalized_value`, `seen_count`, `first_seen_at`, `last_seen_at`, `example_external_id`, `example_title`, `created_at`, `updated_at`.
- Add a lightweight service (new class under `src/Jobs/` or `src/Support/`) to upsert signals keyed by (`source_key`, `signal_type`, `raw_value`).
- In MyJobMag employment-type mapping, record signal when raw value is present but unmatched.
- Extend Monitoring page to include a “Normalization Signals” table (latest rows + counts) so developer/admin can quickly see unmapped values and prioritize mapping updates.

- [ ] **API-source abstraction and Jooble migration**
- Introduce `AbstractApiSource` as API-side equivalent to RSS abstraction for future source onboarding.
- Keep a constructor in Jooble source only for injected `HttpClient`; parent handles config/logger.
- Move Jooble into `Sources/API` and keep constructor minimal (`parent::__construct` + `$this->http`).
- Optional hardening in same pass: make parent logger type usage consistent and avoid widening constructor ambiguity.

- [ ] **Config updates for incremental source onboarding**
- Update `wp-content/plugins/job-aggregator/config/sources.php` minimally to support class dispatch fields when needed (e.g., RSS `driver` or key-based resolver), while keeping existing entries valid.
- Keep MyJobMag disabled/enabled behavior unchanged unless explicitly toggled.

- [ ] **Quality gates**
- Run after implementation:
  - `localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" --cwd wp-content/plugins/job-aggregator composer format`
  - `localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" --cwd wp-content/plugins/job-aggregator composer lint`
  - `localwp --ssh-entry "$HOME/.config/Local/ssh-entry/rYLMOKnKH.sh" --cwd wp-content/plugins/job-aggregator composer test` (only if test bootstrap is present by then)

#### Public Interfaces / Type Changes
- [ ] `JobData` adds `job_categories` (array of `job_listing_category` slugs) with default `['other-automated']`.
- [ ] Source class namespace/file locations change for RSS/API separation; registry instantiation paths updated accordingly.
- [ ] `AbstractApiSource` added for shared API behavior; `JoobleApiSource` now extends it.
- [ ] New internal normalization-signal persistence interface/service (write path from source mapping, read path in monitoring UI).

#### Test Plan
- [ ] Unit test: MyJobMag location filter accepts single-state and comma-joined strings containing target states; rejects non-target locations.
- [ ] Unit test: `expires_at` derivation uses `expiryDate` when present, otherwise `pubDate + 31 days`.
- [ ] Unit test: remote detection flips true when `remote` appears in any designated fields.
- [ ] Unit test: employment type normalization respects allowlist/default and comma-split rule (`Full Time` + other).
- [ ] Unit test: salary mapping sets `salary`/`salary_currency`/`salary_unit` only when feed `salary` has value; leaves defaults when `salary` is null.
- [ ] Unit test: unmatched employment values create/aggregate normalization signal rows.
- [ ] Integration test: `PostWriter` auto-creates missing slug `other-automated` (name `Other (automated)`) and assigns it while preserving existing `job_listing_type` behavior.
- [ ] Integration test: forward-only behavior confirmed (new/updated imports get category assignment; no historical backfill step executed).

#### Assumptions and Defaults Locked
- Source strategy locked: **format folders + source-specific classes**.
- API strategy locked: **introduce `AbstractApiSource` for shared API mechanics and future drivers**.
- Category representation locked: **default `JobData.job_categories` to `['other-automated']`**, assign taxonomy terms in `PostWriter`.
- Employment type policy locked: **strict allowlist with default `['Full Time']`**.
- Salary policy locked (MyJobMag): **only map salary when source `salary` is not null; set currency `NGN`, unit `Monthly`**.
- Unmatched type values are **not blocking**; jobs still import, but signals are persisted and surfaced in Monitoring.
- All string comparisons for filter/normalization use lowercase and trimmed values.
- MyJobMag mapping starts first; additional source normalizers are added incrementally using same class pattern.
- Category rollout is **forward-only in this pass** (no backfill migration).
