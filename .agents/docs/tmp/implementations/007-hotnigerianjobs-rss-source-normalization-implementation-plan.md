# HotNigerianJobs RSS Source Normalization Implementation Plan

## Summary
Implement a dedicated `HotNigerianJobsRssSource` so the existing `hotnigerianjobs` config entry no longer uses generic RSS mapping. The source will filter noisy listings, parse title/company/location from HotNigerianJobs payload text, and map only available RSS fields into `JobData`.

## Key Changes
- Add `HotNigerianJobsRssSource` under `wp-content/plugins/job-aggregator/src/Sources/RSS/`.
- Wire `SourceRegistry` so `key` or `driver` value `hotnigerianjobs` instantiates the new source.
- Keep the existing `config/sources.php` defaults and enabled state unchanged; use defaults for unavailable fields:
  - `location`: `Nigeria` only when no parsed location is available after accepted logic.
  - `employment_types`: `['Full Time']`.
  - `remote_position`: `false`.
- Use lowercase comparisons for all string checks.

## Normalization And Filtering Rules
- Parse RSS fields:
  - `guid` -> `JobData::external_id`.
  - `title` -> split on standalone lowercase-insensitive ` at `.
  - substring before ` at ` -> `JobData::title`.
  - substring after ` at ` -> `JobData::company_name`.
  - `description` -> `JobData::description`.
  - `link` -> `source_url` and `application_url`.
  - `pubDate` -> `published_at`.
  - `expiryDate` or `expires_at`, if present, -> `expires_at`; otherwise `pubDate + 31 days`.
  - ignore `category`.
- Skip item if title has no clear standalone ` at ` separator.
- Skip multi-position roundup titles matching `(<integer> Position)` or `(<integer> Positions)`.
- Skip titles containing blocked seniority/market terms as whole words:
  - `senior`, `staff`, `manager`, `specialist`, `consultant`, `director`, `associate`, `principal`, `latam`, `lead`, `head`, `expert`.
- Skip item unless `description` contains at least one allowed location from the MyJobMag location set excluding `all`:
  - `abia`, `anambra`, `ebonyi`, `enugu`, `imo`, `rivers`, `akwa ibom`, `cross river`, `delta`, `benue`, `kogi`.
- Parse location from `description` only when the sentence after the first `.` contains the pattern `The position is located in ... State` or `States`.
  - Extract text after `in` and before `State`/`States`.
  - Trim punctuation/whitespace.
  - Example: `The position is located in Port Harcourt, Rivers State.` -> `Port Harcourt, Rivers`.
- Skip item if the broad allowed-location check passes but formal location extraction fails.

## Test Plan
- Add unit tests for HotNigerianJobs source mapping:
  - accepts a valid item and maps `guid`, title, company, description, source/application URL, parsed location, default employment type, default remote flag, published date, and derived expiry.
  - skips `(N Positions)` roundup titles.
  - skips blocked whole-word seniority titles.
  - does not skip accidental substrings like `leadership` if not a whole word.
  - skips descriptions with only non-allowed locations.
  - skips when allowed location exists but formal location parser cannot extract a location.
- Add or extend registry test to assert `driver => hotnigerianjobs` builds the dedicated RSS source.
- If adding fixture E2E coverage, add a HotNigerianJobs fixture with one accepted item and skipped noisy items, then assert only the accepted listing is persisted.

## Assumptions
- Production source remains configured but disabled unless separately enabled through settings.
- Blocked title keywords use whole-word matching, per selected preference.
- Ambiguous titles without ` at ` are skipped.
- Ambiguous location parser failures are skipped rather than defaulted to `Nigeria`.
