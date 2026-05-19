# Vouch Changelog

## 5.0.0 — Unreleased

### Added
- Initial scaffold: plugin skeleton, settings model, CP navigation.
- `Source` model and CRUD for connecting third-party review providers.
- `Review` element type with element index, statuses, and basic table attributes.
- Connector interface and `ProviderRegistry` service (no providers wired yet).
- Install migration for `{{%vouch_sources}}`, `{{%vouch_reviews}}`, and `{{%vouch_settings}}` tables.
- Google Reviews connector (Places API New): `apiKey` + `placeId` settings, “Test connection” and “Sync now” actions, dedup by `(sourceId, externalId)`, backfill cursor honoured.
- `Sync` service + `SyncResult` value object — synchronous v1, queue-job-ready API.
- Trustpilot connector (public Business Units API): `apiKey` + `businessUnitId`, paginated walk with newest-first early-exit on cursor.
- Feefo connector (Reviews API v20): `merchantIdentifier` + optional `apiKey`, service-review extraction, page-count pagination.
- Reviews.io connector (Merchant Reviews API): `storeId` + `apiKey`, reviewer email passed through where present — only v1 connector that drives the Points user-match path cleanly.
- `SyncSourceJob` queue job — failures rethrow so Craft's retry policy kicks in; the source row's `lastSyncError` persists the human-readable message either way.
- `Sync` service gains `queue()`, `queueAllDue()`, and `isDue()`. Cron expressions parsed via dragonmantank/cron-expression when the interval isn't one of the named presets.
- Console commands: `craft vouch/sync/all`, `craft vouch/sync/due`, `craft vouch/sync/source <id|handle>`, all with a `--sync` flag to bypass the queue.
- `Source` edit form: sync schedule selector (manual / hourly / daily). Sources index shows the schedule column.
- Public event API for downstream integrations:
  - `Reviews::EVENT_AFTER_SYNC_REVIEW` — every successful upsert, with `isNew` flag.
  - `Reviews::EVENT_AFTER_APPROVE_REVIEW` — fires exactly once per review when it transitions to approved (auto or manual); `auto` flag distinguishes the two paths.
  - `Sync::EVENT_BEFORE_SOURCE_SYNC` — cancellable; set `$event->cancelled = true` to skip the run.
  - `Sync::EVENT_AFTER_SOURCE_SYNC` — carries the populated `SyncResult`.
- `Reviews::approve()` service method — manual approval now flows through the service so the event fires consistently from both paths.
- Twig variable `craft.vouch.*` with `reviews()` (chainable query), `sources()`, `source(handle)`, `providers()`, `averageRating(sourceId?)`, and `pluginName()`.
- GraphQL type `VouchReview` and two queries: `vouchReviews` (filterable: sourceId, rating/minRating, approved, authorUserId, relatedElementId, limit, offset) and `vouchReview(id)`. Defaults to `approved: true` on the public surface to prevent pending-moderation reviews leaking.
- Manual reviews: a `ManualConnector` provider type, CP "New review" form on the reviews index, full edit form with delete action, and an `Approved` toggle. Existing reviews from API sources show a "will be overwritten on next sync" warning when edited.
- Front-end review submission: anonymous `vouch/reviews/submit` controller action accepts customer reviews into a Manual source. Front-end forms can't write into API-backed sources (Trustpilot etc.) — that would bypass the provider's own moderation.
- `Reviews::save()`, `Reviews::delete()`, `Reviews::newManualReview()` — manual creates flow through the service, fire the same `EVENT_AFTER_SYNC_REVIEW` / `EVENT_AFTER_APPROVE_REVIEW` as synced reviews.

### Fixed
- Reviews index now extends `_layouts/elementindex` so the source-list sidebar renders to the left of the table (was rendering inline above the search bar).
