# Vouch Changelog

## 5.0.0 — Unreleased

### Added

#### Core data model & plugin scaffold
- Plugin skeleton with `Source` model, `Review` element type, `Settings` model.
- Install migration for `{{%vouch_sources}}` and `{{%vouch_reviews}}` tables.
- `Review` element type with element index, status filtering (Live / Pending approval), per-source sub-sources, and standard `craft.vouch.reviews()` queries.

#### Provider connectors
- `ConnectorInterface` + `BaseConnector` + `FetchedReview` DTO + event-driven `ProviderRegistry` — third-party plugins can add their own connectors via `EVENT_REGISTER_PROVIDERS`.
- **Google Reviews** connector (Places API New): `apiKey` + `placeId`, documents the 5-review cap upstream.
- **Trustpilot** connector (public Business Units API): `apiKey` + `businessUnitId`, paginated newest-first with cursor early-exit.
- **Feefo** connector (Reviews API v20): `merchantIdentifier` + optional `apiKey`, service-review extraction.
- **Reviews.io** connector (Merchant Reviews API): `storeId` + `apiKey`, reviewer email passed through where present.
- **Manual** connector: no API behind it, used as the source type for CP-authored and front-end-submitted reviews.
- Brand-accurate SVG logos stored in `src/resources/icons/` and loaded via `BaseConnector::loadIcon()` — adding a new connector is just dropping its SVG into that folder.

#### Sync orchestration
- `Sync` service with `run()` (synchronous), `queue()` (enqueues `SyncSourceJob`), and `queueAll()` (enqueues every enabled source).
- `SyncSourceJob` queue job — failures rethrow so Craft's retry policy kicks in. The source row's `lastSyncError` persists the human-readable message either way.
- Cron-driven cadence (no per-source schedule field — the cron entry is the schedule):
  - `craft vouch/sync/all` — enqueue every enabled source.
  - `craft vouch/sync/source <id|handle>` — enqueue or run a single source.
  - Both accept `--sync` to bypass the queue.
- Connector "Test connection" auto-fires on the source edit page; renders as a status dot + label (Akeneo-style).
- Per-row "Sync" button on the sources index runs synchronously via the controller.
- Backfill window for first sync is a global setting (`backfillDays`), `0` = pull all history.

#### Manual reviews
- "New review" button on the reviews index opens a full element-edit form (rating, headline, review, reviewer, related element, business reply, approved toggle).
- Front-end submission via anonymous `vouch/reviews/submit` controller action — hard-rejects non-manual sources so customer submissions can't bypass Trustpilot/Feefo moderation.
- API-sourced reviews show a "will be overwritten on next sync" warning when edited in the CP.
- `Reviewer email` field is read-only for existing reviews (editable on create).

#### Moderation
- Per-source `Require manual approval` toggle on the source edit page. When on, reviews below the global `autoApproveThreshold` (default 5) land as Pending until approved by an admin.

#### CP UX
- Sources index uses Craft's `VueAdminTable` with built-in search, delete, and a per-row Sync button.
- Inline provider quick-add tiles above the table — one click to start a new source for any provider. The standalone `/admin/vouch/sources/new` picker page is gone.
- "Connection" column on the sources table shows live API health for each pull-capable source (async; em-dash for Manual / disabled rows).
- Status dot before the source name reflects enabled/disabled.
- Native Save dropdown ("Save and continue editing") via `formActions` on the source edit page.
- Auto-generated handle from name via `Craft.HandleGenerator`.
- `autoSuggestField` with `suggestEnvVars: true` on all credential and connection fields — supports `$ENV_VAR` references that resolve via `App::parseEnv()` at use-time.

#### Rating roll-up (entries, products, front-end)
- `Reviews::averageRatingForElement(int $elementId)` — single `AVG()` aggregate, no N+1.
- `Reviews::ratingBreakdownForElement(int $elementId)` — per-source average + count.
- **Rating column** on Entries and (when Commerce is installed) Products element indexes. Opt-in via column settings, shows "4.2 ★" or em-dash.
- **Sidebar summary** on Entry and Product edit pages (via `EVENT_DEFINE_SIDEBAR_HTML`) — overall average with star + per-source breakdown.
- Twig: `craft.vouch.ratingForElement(entry.id)` and `craft.vouch.ratingBreakdownForElement(entry.id)` mirror these for front-end use.

#### Events (public API for downstream integrations)
- `Reviews::EVENT_AFTER_SYNC_REVIEW` — fires on every successful upsert, with `isNew` flag.
- `Reviews::EVENT_AFTER_APPROVE_REVIEW` — fires exactly once per review when it transitions to approved (auto or manual); `auto` flag distinguishes the two paths.
- `Sync::EVENT_BEFORE_SOURCE_SYNC` — cancellable; set `$event->cancelled = true` to skip the run.
- `Sync::EVENT_AFTER_SOURCE_SYNC` — carries the populated `SyncResult`.

#### Front-end surface
- Twig `craft.vouch.*`: `reviews()` (chainable element query), `sources()`, `source(handle)`, `providers()`, `averageRating(sourceId?)`, `ratingForElement(elementId)`, `ratingBreakdownForElement(elementId)`, `pluginName()`.
- GraphQL type `VouchReview` + two queries:
  - `vouchReviews` — filterable by `sourceId`, `rating`, `minRating`, `approved`, `reviewerUserId`, `relatedElementId`, `limit`, `offset`. Defaults to `approved: true` on the public surface.
  - `vouchReview(id)` — single review by id.

#### PII & GDPR
- `reviewerEmailHash` (SHA-256 lowercase) stored alongside `reviewerEmail` so user-matching survives `emailRetentionDays` purge.
- Credentials encrypted at rest via Craft's security key, base64-wrapped for UTF-8-safe column storage.

#### Settings
- Plugin settings stored in Project Config (so they sync via `project.yaml`).
- `config/vouch.php` overlays Project Config values for per-environment overrides.
- Settings page accessible via Settings → Plugins → Vouch (not surfaced in Vouch's own sidebar).
- Available settings: `pluginName`, `matchAuthorsToUsers`, `emailRetentionDays`, `backfillDays`, `autoApproveThreshold`.

### Fixed
- Reviews index now extends `_layouts/elementindex` so the source-list sidebar renders to the left of the table.
- `Sources::saveSource()` coerces ActiveRecord date columns to `DateTime` before writing to the model's typed properties.
- Encrypted credentials are base64-encoded for UTF-8-safe storage in `TEXT` columns.
- `HandleValidator` + unique-handle check on `Source` so duplicate handles surface as form validation rather than a SQL integrity exception.
- Source dropdown on new-review form uses array-of-objects options to dodge Twig's integer-key reindexing in `merge`.
