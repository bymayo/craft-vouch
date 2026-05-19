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
