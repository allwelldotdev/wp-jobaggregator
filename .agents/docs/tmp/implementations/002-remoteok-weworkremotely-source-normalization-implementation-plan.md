### 002 Implementation Plan: RemoteOK + WeWorkRemotely Source-Specific Fetch/Normalization

#### Summary
- [x] Discovery complete: reviewed mandatory docs, current source architecture, MyJobMag implementation-plan pattern, and first 100 lines of both payload fixtures.
- [x] Implement source-specific RSS normalization/filtering for `remoteok` and `weworkremotely` in their own source classes, following the MyJobMag checklist style.
- [x] Keep rollout incremental: update source mapping/registry/tests only; do not change unrelated pipeline contracts.

#### Key Implementation Changes (Decision-Complete Checklist)
- [x] **Refactor source dispatch for source-specific RSS classes**
- Add `RemoteOkRssSource` and `WeWorkRemotelyRssSource` under `src/Sources/RSS/`.
- Update `SourceRegistry::build_rss_source()` to dispatch:
  - `myjobmag` -> `MyJobMagRssSource`
  - `remoteok` -> `RemoteOkRssSource`
  - `weworkremotely` -> `WeWorkRemotelyRssSource`
  - fallback -> generic `RssFeedSource`
- Keep `SourceInterface` and batch checkpoint flow unchanged.

- [x] **Implement shared cross-source mapping rules exactly**
- Parse payload fields and map only fields that exist in each source payload.
- Apply lowercase + trimmed comparison policy to all string checks used in filtering/normalization.
- Remote detection policy:
  - Use `defaults['remote_position']` from `config/sources.php` as source-level remote default.
  - For sources where that default resolves true, map `JobData::remote_position = true` for imported items from that source.
- Expiry mapping policy:
  - If item has `expiryDate` or `expires_at`, parse and map to `JobData::expires_at`.
  - Otherwise parse `pubDate`, add `31` days, map to `JobData::expires_at`.
- Parse `guid` as item id and map to `JobData::external_id`.
- Ignore non-`JobData` extras.

- [x] **Implement RemoteOK-specific `fetch_batch` normalization/filtering**
- Parse/use fields observed in sample payload: `title`, `company`, `description`, `tags`, `location`, `pubDate`, `guid`, `link`, `image`.
- Skip item when `title` contains any blocked seniority token (case-insensitive):
  - `senior`, `staff`, `manager`, `specialist`, `consultant`, `director`, `associate`, `principal`, `latam`, `lead`, `head`, `expert`
- Skip item when `tags` contains any blocked tag token (case-insensitive):
  - `senior`, `management`, `manager`, `leader`, `director`, `consulting`, `expert`
- Ignore `tags` field.
- Location filter:
  - Skip item when `location` is non-null and not equal to `remote` (case-insensitive).
  - If `location` is null, map `JobData::location` to `defaults['location']` (for this source currently `Worldwide`).
  - If `location` is `remote`, map normalized `remote`/source-default location per existing mapper convention.
- Map `image` URL to `JobData::company_logo_url` (handle both direct string value and nested URL shape defensively).
- Map available core fields:
  - `company` -> `JobData::company_name`
  - `title` -> `JobData::title`
  - `description` -> `JobData::description`
  - `link` -> `source_url` + `application_url`
  - `pubDate` -> `published_at`

- [x] **Implement WeWorkRemotely-specific `fetch_batch` normalization/filtering**
- Parse/use fields observed in sample payload: `media:content.@url`, `title`, `region`, `country`, `state`, `skills`, `category`, `type`, `description`, `pubDate`, `expires_at`, `guid`, `link`.
- Skip item when `title` contains any blocked seniority token (case-insensitive):
  - `senior`, `staff`, `manager`, `specialist`, `consultant`, `director`, `associate`, `principal`, `latam`, `lead`, `head`, `expert`
- Ignore `country`, `skills`, and `category` fields.
- Location mapping:
  - Base value = source default location (`defaults['location']`, currently `Anywhere in the World`).
  - If `state` is non-null/non-empty, map location as `"{defaults['location']}, {state}"` (e.g., `Anywhere in the World, California`).
- Title/company split rule:
  - Parse substring from index `0` to first `:` as `JobData::company_name`.
  - Strip that prefix + colon from title and map remainder to `JobData::title`.
  - If no colon exists, keep full title and fallback company to default.
- Employment type mapping from `type` (adapted from MyJobMag plan lines 35-39 behavior):
  - Normalize hyphen/spacing (`Full-Time` -> `full time`) before lookup.
  - Strict allowlist values: `Full Time`, `Freelance`, `Internship`, `Part Time`, `Temporary`, `Contract`, `Onsite`, `Hybrid`.
  - Default fallback: `['Full Time']` when missing/unmatched.
  - Record normalization signal for unmatched non-empty raw `type` values.
- Logo mapping:
  - If `media:content` exists, map `media:content.@url` to `JobData::company_logo_url`.
  - Else no-op for logo.
- Map available core fields:
  - `description` -> `JobData::description`
  - `link` -> `source_url` + `application_url`
  - `pubDate` -> `published_at`

- [x] **Quality gates**
- Run after implementation:
  - `localwp --cwd wp-content/plugins/job-aggregator composer format`
  - `localwp --cwd wp-content/plugins/job-aggregator composer lint`
  - `localwp --cwd wp-content/plugins/job-aggregator composer test` (if tests are available and runnable)

#### Public Interfaces / Type Changes
- [x] No `SourceInterface` signature changes.
- [x] No `JobData` property changes in this pass.
- [x] Internal additions only: two new RSS source classes + registry routing updates.

#### Test Plan
- [x] Unit test: RemoteOK title blocked-token filtering skips matching items.
- [x] Unit test: RemoteOK tags blocked-token filtering skips matching items.
- [x] Unit test: RemoteOK location rule allows `null` and `remote`, rejects other non-null values.
- [x] Unit test: `guid` maps to `external_id` for RemoteOK and WeWorkRemotely.
- [x] Unit test: expiry mapping uses `expires_at`/`expiryDate` when present, else `pubDate + 31 days`.
- [x] Unit test: WeWorkRemotely title split extracts `company_name` and cleans `title`.
- [x] Unit test: WeWorkRemotely state concatenates with source default location.
- [x] Unit test: WeWorkRemotely `type` normalization handles hyphenated inputs and strict allowlist fallback.
- [x] Unit test: unmatched WeWorkRemotely `type` writes normalization signal.
- [x] Unit test: logo mapping uses `image` (RemoteOK) and `media:content.@url` (WeWorkRemotely).

#### Assumptions and Defaults Locked
- Source-specific class strategy follows the existing MyJobMag pattern under `src/Sources/RSS`.
- For these two sources, `defaults['remote_position']` currently resolves `true`, so imported jobs are treated as remote in code.
- Location fallback for null/empty RemoteOK location uses source default location value, not an inferred geolocation.
- Unmatched employment type values do not block import; they fallback to default and emit normalization signals.
