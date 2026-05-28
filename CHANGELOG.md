# Vouch Changelog

## 5.0.3 - 2026-05-28

### Changed
- The reviews screen on the user edit page now lives at `/admin/users/<id>/vouch` (and `/admin/myaccount/vouch` for the current user), shortened from the previous `…/vouch-reviews`.
- The **Reviewer** column is hidden on that screen - every row is the same user, so it was redundant. The main reviews index at `/admin/vouch/reviews` is unchanged and still shows it.

## 5.0.2 - 2026-05-27

### Added
- **Points plugin integration**. Vouch registers a "Review approved" trigger with [Points](https://plugins.craftcms.com/points?craft5) when installed, along with three bundled conditions for narrowing rules: **Is from source**, **Rating is at least**, and **Review length is at least**. Points are only awarded for reviews tied to a Craft user.

## 5.0.1 - 2026-05-27

### Added
- **Trustpilot Business Unit finder** on the Trustpilot source edit page. Search by company name or domain and click a result to fill in the Business Unit ID.
- **Sources dashboard widget** - lists each pull-based source with its last-synced timestamp and a one-click Sync button. Manual sources are excluded. `sourceId` setting lets you scope the widget to a single source.
- **Honeypot field** on the front-end submission controller. Include a `vouchHoneypot` input in your form; any non-empty value is silently discarded.
- **Per-IP submission rate limiting**. New `submissionRateLimit` (default 5) and `submissionRateWindow` (default 60s) settings.
- **`craft.vouch.settings`** Twig variable - direct access to the plugin's settings model (e.g. `craft.vouch.settings.headlineMaxLength`).

### Changed
- **Renamed** `Reviews::averageRatingForElement` → `Reviews::rating` (and the matching `craft.vouch.ratingForElement` → `craft.vouch.rating`).
- **Renamed** `Reviews::ratingBreakdownForElement` → `Reviews::ratingsBySource` (and the matching `craft.vouch.ratingBreakdownForElement` → `craft.vouch.ratingsBySource`).
- Cron-driven sync now runs inline. The `--sync` flag on `vouch/sync/*` commands has been dropped (it's the default and only mode).

### Removed
- Queue-backed sync path: `Sync::queue()`, `Sync::queueAll()`, and `SyncSourceJob` are gone. The CLI runs syncs inline; the queue runner is no longer involved.
- Third-party provider extension API: `EVENT_REGISTER_PROVIDERS`, `RegisterProvidersEvent`, and `ProviderRegistry`'s registration event. Built-in providers are now a fixed list.
- `craft.vouch.pluginName()` - use `craft.vouch.settings.pluginName` instead.

## 5.0.0 - 2026-05-26

### Added

#### Core data model & plugin scaffold
- Plugin skeleton with `Source` model, `Review` element type, `Settings` model.
- Install migration for `{{%vouch_sources}}` and `{{%vouch_reviews}}` tables.
- `Review` element type with element index, status filtering (Live / Pending approval), per-source sub-sources, and standard `craft.vouch.reviews()` queries.

#### Source UX
- **"Find a Place ID" search** on the Google Reviews source edit page. Proxies the Places Text Search endpoint via `SourcesController::actionFindGooglePlace` using the API key from the form (works on new, unsaved sources too) and lists matching places with click-to-fill behaviour. Saves admins from hand-pasting opaque `ChIJ…` IDs.
- **Google Business Profile API mode** on the Google source. The source edit page has a Mode dropdown: "Places API" (existing key-based behaviour, 5-review cap) or "Business Profile API" (OAuth, full review history). Business Profile mode wires:
  - Per-source OAuth 2.0 client (Client ID + Client Secret stored encrypted on the source)
  - "Connect Google account" button → standard `accounts.google.com` consent flow → callback exchanges the auth code for a refresh token, stored encrypted alongside the client credentials
  - `GoogleConnector::fetchReviews()` branches on mode; the Business Profile path paginates `mybusiness.googleapis.com/v4/.../reviews`, normalises into the existing `FetchedReview` DTO, and respects the global `backfillDays` cursor.
  - Note: Google's reviews endpoint is gated behind partner approval as of 2023 - the feature exists in Vouch but won't return data until the operator's Google Cloud project is approved by Google.

#### Provider connectors
- `ConnectorInterface` + `BaseConnector` + `FetchedReview` DTO + event-driven `ProviderRegistry` - third-party plugins can add their own connectors via `EVENT_REGISTER_PROVIDERS`.
- **Google Reviews** connector (Places API New): `apiKey` + `placeId`, documents the 5-review cap upstream.
- **Trustpilot** connector (public Business Units API): `apiKey` + `businessUnitId`, paginated newest-first with cursor early-exit.
- **Feefo** connector (Reviews API v20): `merchantIdentifier` + optional `apiKey`, service-review extraction.
- **Reviews.io** connector (Merchant Reviews API): `storeId` + `apiKey`, reviewer email passed through where present.
- **Manual** connector: no API behind it, used as the source type for CP-authored and front-end-submitted reviews.
- Brand-accurate SVG logos stored in `src/resources/icons/` and loaded via `BaseConnector::loadIcon()` - adding a new connector is just dropping its SVG into that folder.

#### Sync orchestration
- `Sync` service with `run()` (synchronous), `queue()` (enqueues `SyncSourceJob`), and `queueAll()` (enqueues every enabled source).
- `SyncSourceJob` queue job - failures rethrow so Craft's retry policy kicks in. The source row's `lastSyncError` persists the human-readable message either way.
- Cron-driven cadence (no per-source schedule field - the cron entry is the schedule):
  - `craft vouch/sync/all` - enqueue every enabled source.
  - `craft vouch/sync/source <id|handle>` - enqueue or run a single source.
  - Both accept `--sync` to bypass the queue.
- Connector "Test connection" auto-fires on the source edit page; renders as a status dot + label (Akeneo-style).
- Per-row "Sync" button on the sources index runs synchronously via the controller.
- Backfill window for first sync is a global setting (`backfillDays`), `0` = pull all history.

#### Manual reviews
- "New review" button on the reviews index opens a full element-edit form (rating, headline, review, reviewer, related element, business reply, approved toggle).
- Front-end submission via anonymous `vouch/reviews/submit` controller action - hard-rejects non-manual sources so customer submissions can't bypass Trustpilot/Feefo moderation.
- API-sourced reviews show a "will be overwritten on next sync" warning when edited in the CP.
- `Reviewer email` field is read-only for existing reviews (editable on create).
- Front-end submit attaches per-field validation errors to the review model + sets a flash error + `requiresLogin` route param when applicable, so re-rendered forms can show inline errors and a "Log in" link.
- Configurable `headlineMaxLength` (default 120) and `reviewMaxLength` (default 2000) length caps - applied as validation rules and surfaced as `maxlength` attributes in the documented Twig form example.

#### Moderation
- Per-source `Require manual approval` toggle on the source edit page. When on, reviews below the global `autoApproveThreshold` (default 5) land as Pending until approved by an admin.
- `applyModeration()` now runs on **every** save path (front-end submit, CP author, API sync) - the threshold is consistent across all sources, not just provider syncs.
- **Bulk Approve** element-index action on the reviews index. Skips already-approved rows, batches the work, fires `EVENT_AFTER_APPROVE_REVIEW` identically to single-row approvals. Visible to users with `vouch-editReviews`.

#### Spam / attribution controls
- Front-end submissions only link `reviewerUserId` when the submitter is authenticated AND their account email matches the submitted email - blocks the forge-by-email attribution attack.
- `requireLoginForKnownEmails` setting (default `true`): rejects any submission whose email matches an existing Craft user when the submitter isn't logged in as them. Returns a 403 with `{ ok: false, requiresLogin: true, loginUrl, message, errors }` for JSON requests and attaches the rejection as a `reviewerEmail` validation error for HTML submits.

#### Dashboard widgets
- **Reviews Pending Approval** - lists pending reviews with title (truncated with ellipsis), rating, reviewer + relative date. Footer links to the pending source on the reviews index.
- **Latest Reviews** - most recent approved reviews; optional per-source filter. Reviewer name links to the matched Craft user when known.
- **Top Reviewed Elements** - ranks elements by review count or average rating. Configurable element type (Entries / Assets / Categories / Users / Commerce Products); when "Entries" is picked an additional section filter appears. Column header reflects the chosen element type's display name.
- All three widgets' display names use the configured `pluginName` so a CP rename flows through.

#### Permissions
- `vouch-approveReviews` - top-level permission (sibling of "View reviews") that gates both the single-row Approve button and the bulk Approve element-index action. Lets a moderator-only role approve without granting full edit/delete.
- `vouch-manageSources` split into three: `vouch-createSources`, `vouch-editSources`, `vouch-deleteSources`. `actionSave` distinguishes new vs edit by `sourceId` and gates accordingly; `actionDelete` requires `vouch-deleteSources`; the "+ New source" tiles on the sources index now check `vouch-createSources`; the "Find a Place ID" helper accepts either `vouch-createSources` or `vouch-editSources`.
- `vouch-viewWidgets` - new top-level permission. All three dashboard widgets' `isSelectable()` now checks this instead of `vouch-viewReviews`, so admins can hide widgets independently of review access.
- Permissions heading uses the configured `pluginName` so a CP rename flows through to the user-group settings UI.

#### Users integration
- Opt-in "Reviews" column on the Users element index showing how many approved reviews each user has authored (matched via `reviewerUserId`).
- "Reviews" screen on the user edit page (and `/myaccount`) - mirrors the pattern Commerce uses for its "Commerce" tab. Embeds the reviews element index pre-filtered to that user. Sidebar label respects the configured `pluginName`.

#### CP UX
- Sources index uses Craft's `VueAdminTable` with built-in search, delete, and a per-row Sync button.
- Inline provider quick-add tiles above the table - one click to start a new source for any provider. The standalone `/admin/vouch/sources/new` picker page is gone.
- "Connection" column on the sources table shows live API health for each pull-capable source (async; em-dash for Manual / disabled rows).
- Status dot before the source name reflects enabled/disabled.
- Native Save dropdown ("Save and continue editing") via `formActions` on the source edit page.
- Auto-generated handle from name via `Craft.HandleGenerator`.
- `autoSuggestField` with `suggestEnvVars: true` on all credential and connection fields - supports `$ENV_VAR` references that resolve via `App::parseEnv()` at use-time.

#### Rating roll-up (entries, products, front-end)
- `Reviews::averageRatingForElement(int $elementId)` - single `AVG()` aggregate, no N+1.
- `Reviews::ratingBreakdownForElement(int $elementId)` - per-source average + count.
- **Rating column** on Entries and (when Commerce is installed) Products element indexes. Opt-in via column settings, shows "4.2 ★" or em-dash.
- **Sidebar summary** on Entry and Product edit pages (via `EVENT_DEFINE_SIDEBAR_HTML`) - overall average with star + per-source breakdown.
- Twig: `craft.vouch.ratingForElement(entry.id)` and `craft.vouch.ratingBreakdownForElement(entry.id)` mirror these for front-end use.

#### Events (public API for downstream integrations)
- `Reviews::EVENT_AFTER_SYNC_REVIEW` - fires on every successful upsert, with `isNew` flag.
- `Reviews::EVENT_AFTER_APPROVE_REVIEW` - fires exactly once per review when it transitions to approved (auto or manual); `auto` flag distinguishes the two paths.
- `Sync::EVENT_BEFORE_SOURCE_SYNC` - cancellable; set `$event->cancelled = true` to skip the run.
- `Sync::EVENT_AFTER_SOURCE_SYNC` - carries the populated `SyncResult`.

#### Front-end surface
- Twig `craft.vouch.*`: `reviews()` (chainable element query), `sources()`, `source(handle)`, `providers()`, `averageRating(sourceId?)`, `ratingForElement(elementId)`, `ratingBreakdownForElement(elementId)`, `pluginName()`.
- `craft.vouch.reviews()` defaults to `approved(true)` so pending reviews never leak to the front-end. Pass `.approved(null)` to include both, `.approved(false)` for pending only.
- Convenience getters on the `Review` element: `review.sourceName`, `review.sourceHandle`, `review.providerHandle`, `review.getReviewerUser()`.
- GraphQL type `VouchReview` + two queries:
  - `vouchReviews` - filterable by `sourceId`, `rating`, `minRating`, `approved`, `reviewerUserId`, `relatedElementId`, `limit`, `offset`. Defaults to `approved: true` on the public surface.
  - `vouchReview(id)` - single review by id.

#### PII & GDPR
- `reviewerEmailHash` (SHA-256 lowercase) stored alongside `reviewerEmail` so user-matching survives `emailRetentionDays` purge.
- Credentials encrypted at rest via Craft's security key, base64-wrapped for UTF-8-safe column storage.

#### Settings
- Plugin settings stored in Project Config (so they sync via `project.yaml`).
- `config/vouch.php` overlays Project Config values for per-environment overrides.
- Settings page accessible via Settings → Plugins → Vouch (not surfaced in Vouch's own sidebar).
- Available settings: `pluginName`, `matchAuthorsToUsers`, `emailRetentionDays`, `backfillDays`, `autoApproveThreshold`, `requireLoginForKnownEmails`, `headlineMaxLength`, `reviewMaxLength`.

### Changed
- **Field rename + DB migration** (schema 1.0.1): `title→headline`, `body→review`, `authorName→reviewerName`, `authorEmail→reviewerEmail`, `authorEmailHash→reviewerEmailHash`, `authorUserId→reviewerUserId`, `response→businessReply`. Property, DB column, GraphQL type, Twig accessor, controller form-field, and condition rule names all updated. Migration `m260526_120000_rename_review_fields` renames the columns in place; FKs/indices are dropped and re-created against the new names. Anyone consuming the GraphQL surface, `craft.vouch.reviews()` filters, or the old property names will need to update their references.

### Fixed
- Reviews index now extends `_layouts/elementindex` so the source-list sidebar renders to the left of the table.
- `Sources::saveSource()` coerces ActiveRecord date columns to `DateTime` before writing to the model's typed properties.
- Encrypted credentials are base64-encoded for UTF-8-safe storage in `TEXT` columns.
- `HandleValidator` + unique-handle check on `Source` so duplicate handles surface as form validation rather than a SQL integrity exception.
- Source dropdown on new-review form uses array-of-objects options to dodge Twig's integer-key reindexing in `merge`.
- `reviewedAt` stored via `Db::prepareDateForDb()` so the timestamp survives PHP/DB timezone mismatches (previously a tz-less literal was written and re-interpreted as UTC, shifting dates across midnight boundaries).
- `relatedElementId` no longer required on manual submissions (was rejecting otherwise-valid customer reviews).
- Section field in the **Top Reviewed Elements** widget settings now toggles live on element-type change (was waiting until Save before appearing).
- Element-index empty placeholders use em dashes (`—`) consistently.
- Google source setup: README now recommends `backfillDays=0` for Google sources (Places-New caps at 5 reviews per call anyway, so the upstream cost is already bounded). With the default 90-day filter, places whose most recent reviews were all older than 90 days returned "0 new, 0 updated" on first sync, which read like a credentials failure.
