<?php

namespace bymayo\vouch\connectors\manual;

use bymayo\vouch\connectors\BaseConnector;
use bymayo\vouch\models\Source;

/**
 * Manual connector — the no-API provider.
 *
 * A "manual" source is a real Source row with all the usual moderation,
 * scheduling, and Points-trigger behaviour, but no third-party API behind
 * it. It exists so manually-authored reviews (CP form, front-end customer
 * submissions, CSV imports) fit the same data model as synced reviews:
 *
 *  - `sourceId` stays required on every review (dedup index still works,
 *    Points trigger still has a source on the payload).
 *  - Different manual sources can exist for different use cases — "Phone
 *    support feedback", "Beta tester reviews", "In-store kiosk" — each
 *    with its own moderation settings and target element.
 *
 * `fetchReviews()` is a no-op so scheduled syncs walking enabled sources
 * cleanly skip these. `testConnection()` always succeeds — there's nothing
 * to test, and surfacing that helps disambiguate "haven't configured
 * anything yet" from "API is broken".
 */
class ManualConnector extends BaseConnector
{
    public static function handle(): string
    {
        return 'manual';
    }

    public static function displayName(): string
    {
        return 'Manual';
    }

    public static function icon(): ?string
    {
        return self::loadIcon('manual');
    }

    public static function capabilities(): array
    {
        return [
            self::CAPABILITY_PULL => false,
            self::CAPABILITY_PUSH => false,
            self::CAPABILITY_INVITE => false,
        ];
    }

    public static function settingsSchema(): array
    {
        return [];
    }

    public function testConnection(Source $source): array
    {
        return [
            'ok' => true,
            'message' => 'Manual sources don’t need an API connection — reviews are authored directly in Craft.',
        ];
    }

    public function fetchReviews(Source $source, ?\DateTimeInterface $since = null): iterable
    {
        return [];
    }
}
