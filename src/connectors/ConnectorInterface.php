<?php

namespace bymayo\vouch\connectors;

use bymayo\vouch\models\Source;

/**
 * Contract every provider connector must implement.
 *
 * A connector is the bridge between Vouch's `Source` model and a single
 * third-party review platform. It is responsible for:
 *
 *  - Declaring its identity (handle, display name, icon, capability flags).
 *  - Validating a Source's settings/credentials and reporting connection health.
 *  - Fetching reviews for a Source, page by page, normalising each one into a
 *    `FetchedReview` DTO that the sync service writes as a `Review` element.
 *
 * Connectors are registered with the ProviderRegistry as fully-qualified class
 * names; the registry instantiates and caches a single instance per handle.
 */
interface ConnectorInterface
{
    /**
     * Stable, unique handle for this provider (e.g. `google`, `trustpilot`).
     * Stored on each `Source` row, never shown to end users directly.
     */
    public static function handle(): string;

    /**
     * Human-readable display name (e.g. "Google Reviews", "Trustpilot").
     */
    public static function displayName(): string;

    /**
     * Optional SVG markup for the provider logo, rendered in source pickers
     * and list rows. Return null to fall back to a generic icon.
     */
    public static function icon(): ?string;

    /**
     * Capability flags this provider supports - pull/push/invite. Drives which
     * UI affordances are shown for sources using this provider.
     *
     * @return array<string, bool>
     */
    public static function capabilities(): array;

    /**
     * Field schema for the source edit form: which credential / setting fields
     * to render, their handles, types, and validation hints. Used by the
     * source edit controller to build the form dynamically per provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function settingsSchema(): array;

    /**
     * Probe the connection using the Source's stored credentials. Returns a
     * tuple-like array: `['ok' => bool, 'message' => string]`.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(Source $source): array;

    /**
     * Fetch reviews for the given Source, optionally only those created after
     * the cursor's timestamp. Implementations should paginate internally and
     * yield each `FetchedReview` so the sync service can stream-write them
     * without holding the full result set in memory.
     *
     * @param Source $source
     * @param \DateTimeInterface|null $since
     * @return iterable<FetchedReview>
     */
    public function fetchReviews(Source $source, ?\DateTimeInterface $since = null): iterable;
}
