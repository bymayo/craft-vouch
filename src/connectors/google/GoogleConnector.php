<?php

namespace bymayo\vouch\connectors\google;

use bymayo\vouch\connectors\BaseConnector;
use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\models\Source;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Google Reviews connector - Places API (New).
 *
 * Notes / known limits:
 *
 *  - The Places API (New) returns at most **5** reviews per place. This is a
 *    Google-side cap, not a Vouch one. The Business Profile API (OAuth-only,
 *    verified-listing-only) is the only way to get more, and is out of scope
 *    for v1.
 *  - There is no `since` filtering. We always pull the 5 most recent and let
 *    the dedup layer ignore ones we've already stored.
 *  - Google doesn't expose reviewer email addresses, so `reviewerEmail` is
 *    always null. That means the Points "match by email" path won't fire for
 *    Google reviews - by design, not a bug.
 *  - Auth is a simple API key. The key needs the Places API (New) enabled
 *    and, for production, an HTTP referrer or IP restriction.
 *
 * @see https://developers.google.com/maps/documentation/places/web-service/place-details
 */
class GoogleConnector extends BaseConnector
{
    public const ENDPOINT_PLACE = 'https://places.googleapis.com/v1/places/';

    public static function handle(): string
    {
        return 'google';
    }

    public static function displayName(): string
    {
        return 'Google Reviews';
    }

    public static function icon(): ?string
    {
        return self::loadIcon('google');
    }

    public static function settingsSchema(): array
    {
        return [
            [
                'handle' => 'apiKey',
                'label' => 'API key',
                'instructions' => 'A Google Cloud API key with the Places API (New) enabled.',
                'type' => 'text',
                'secret' => true,
                'required' => true,
            ],
            [
                'handle' => 'placeId',
                'label' => 'Place ID',
                'instructions' => 'The Google Place ID for the location to pull reviews from. Find yours at https://developers.google.com/maps/documentation/places/web-service/place-id.',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'ChIJ…',
            ],
        ];
    }

    public function testConnection(Source $source): array
    {
        try {
            $place = $this->fetchPlace($source);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $name = $place['displayName']['text'] ?? null;
        $rating = $place['rating'] ?? null;
        $count = $place['userRatingCount'] ?? null;

        if (!$name) {
            return ['ok' => false, 'message' => 'Google returned no place data. Check the Place ID.'];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected to %s - %s★ from %s reviewers.',
                $name,
                $rating !== null ? number_format((float) $rating, 1) : '–',
                $count ?? '–',
            ),
        ];
    }

    public function fetchReviews(Source $source, ?\DateTimeInterface $since = null): iterable
    {
        $place = $this->fetchPlace($source);
        $reviews = $place['reviews'] ?? [];

        foreach ($reviews as $row) {
            $externalId = $row['name'] ?? null;
            if (!$externalId) {
                continue;
            }

            $publishTime = isset($row['publishTime'])
                ? $this->parseDate($row['publishTime'])
                : null;

            // The cursor-style `since` filter Places-New doesn't support: we
            // honour it client-side so callers get a consistent semantics
            // across providers, even though we always fetch the full 5.
            if ($since && $publishTime && $publishTime < $since) {
                continue;
            }

            yield new FetchedReview(
                externalId: (string) $externalId,
                rating: (float) ($row['rating'] ?? 0),
                headline: null,
                review: $row['text']['text'] ?? ($row['originalText']['text'] ?? null),
                reviewerName: $row['authorAttribution']['displayName'] ?? null,
                reviewerEmail: null,
                reviewedAt: $publishTime,
                businessReply: null,
                raw: $row,
            );
        }
    }

    /**
     * Single HTTP call to the Places API (New) Place Details endpoint.
     *
     * @return array<string, mixed>
     * @throws GuzzleException
     * @throws \RuntimeException When credentials/settings are missing or the API errors.
     */
    private function fetchPlace(Source $source): array
    {
        $apiKey = App::parseEnv((string) ($source->credentials['apiKey'] ?? ''));
        $placeId = App::parseEnv((string) ($source->settings['placeId'] ?? ''));

        if ($apiKey === '' || $placeId === '') {
            throw new \RuntimeException('Google source is missing apiKey or placeId.');
        }

        $client = Craft::createGuzzleClient([
            'timeout' => 15,
        ]);

        $response = $client->request('GET', self::ENDPOINT_PLACE . $placeId, [
            'headers' => [
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'id,displayName,rating,userRatingCount,reviews',
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = $decoded['error']['message']
                ?? sprintf('Google Places API returned HTTP %d.', $status);
            throw new \RuntimeException($message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Google Places API returned an unparseable response.');
        }

        return $decoded;
    }

    private function parseDate(string $value): ?\DateTime
    {
        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
