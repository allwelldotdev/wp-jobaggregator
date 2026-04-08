## RemoteOK + WeWorkRemotely Source-Specific Fetch/Normalization Plan

### Summary
Implement source-specific RSS mappers for `remoteok` and `weworkremotely` under the existing RSS architecture, using the payload shapes verified from the first 100 lines of:
- `/home/allwell/Local Sites/tekseriescom/app/public/.agents/docs/tmp/rssfeeds/remoteok/feed.json`
- `/home/allwell/Local Sites/tekseriescom/app/public/.agents/docs/tmp/rssfeeds/weworkremotely/all_feed.json`

Useful payload fields confirmed:
- RemoteOK: `title`, `company`, `description`, `tags`, `location`, `pubDate`, `guid`, `link`, `image`
- WeWorkRemotely: `title`, `region`, `country`, `state`, `skills`, `category`, `type`, `description`, `pubDate`, `expires_at`, `guid`, `link`, `media:content.@url`

### Implementation Changes
- Add `RemoteOkRssSource` and `WeWorkRemotelyRssSource` in RSS sources, and wire both in source dispatch in `SourceRegistry`.
- Keep `SourceInterface` unchanged; logic remains inside per-source `map_item_to_job` via `AbstractRssSource::fetch_batch`.
- Apply lowercase/trim normalization for all string checks in both sources.
- Global rules for both sources:
  - Use `guid` as `JobData::external_id`.
  - Remote detection default: use `defaults['remote_position']`; if `true`, set `remote_position=true` for all items from that source.
  - Expiry mapping:
    - If `expiryDate`/`expires_at` present: parse and map to `JobData::expires_at`.
    - Else: parse `pubDate + 31 days` for `expires_at`.
  - Map available fields only; fallback to defaults for missing `JobData` fields.
- RemoteOK rules:
  - Skip if `title` contains blocked seniority tokens.
  - Skip if `tags` contains blocked tokens.
  - Location rule (locked): keep only when `location` is `null` or equals `remote` (case-insensitive); otherwise skip.
  - If `location` is `null`, map `JobData::location` to source default location.
  - Map `image` URL to `JobData::company_logo_url` (support scalar URL and nested url value defensively).
- WeWorkRemotely rules:
  - Skip if `title` contains blocked seniority tokens.
  - Ignore `country`.
  - If `state` exists, map location as `"{default location}, {state}"`; else default location.
  - Parse `title` prefix before first `:` as `company_name`; remainder as job `title`.
  - If no `:` exists: keep full title, fallback company name to default.
  - Map `type` to `employment_types` using normalized allowlist behavior consistent with prior MyJobMag policy (hyphen/spacing tolerant, default `Full Time`, unmatched value recorded as normalization signal).
  - Map `media:content.@url` to `company_logo_url` when present.

### Public Interfaces / Types
- No contract changes to `SourceInterface`, `SourceBatchResult`, or `JobData` shape.
- Internal additions only: new RSS source classes + registry routing for `remoteok` and `weworkremotely`.

### Test Plan
- Unit: RemoteOK title/tag blocked-word filtering.
- Unit: RemoteOK location allow/skip behavior (`null`, `remote`, non-remote).
- Unit: RemoteOK expiry fallback (`pubDate + 31`) and `guid` external id mapping.
- Unit: WeWorkRemotely title split (`Company: Role`) and no-colon fallback behavior.
- Unit: WeWorkRemotely `state` location concatenation behavior.
- Unit: WeWorkRemotely `type` normalization (hyphenated values, allowlist mapping, default fallback).
- Unit: WeWorkRemotely unmatched `type` writes normalization signal.
- Unit: `media:content.@url` and RemoteOK `image` logo mapping.

### Assumptions Locked
- RemoteOK location interpretation: allow only `null` or `remote`; skip all other values.
- WeWorkRemotely fallback policy: no-colon title is not skipped; it falls back safely.
- WeWorkRemotely unmatched employment type values should record normalization signals while still importing with default `['Full Time']`.
- Date output for `expires_at` should remain consistent with existing source behavior (normalized date string used by current pipeline).
