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
    public const ENDPOINT_SEARCH = 'https://places.googleapis.com/v1/places:searchText';

    /** OAuth + Business Profile API endpoints. */
    public const OAUTH_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const OAUTH_TOKEN = 'https://oauth2.googleapis.com/token';
    public const OAUTH_SCOPE = 'https://www.googleapis.com/auth/business.manage';
    public const ENDPOINT_BUSINESS_REVIEWS = 'https://mybusiness.googleapis.com/v4/';
    public const ENDPOINT_ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    public const MODE_PLACES = 'places';
    public const MODE_BUSINESS_PROFILE = 'businessProfile';

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

    /**
     * Schema declares every field across both modes. The source edit template
     * has a Google-specific UI block that renders the mode dropdown and uses
     * JS to show only the active mode's fields.
     */
    public static function settingsSchema(): array
    {
        return [
            [
                'handle' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'default' => self::MODE_PLACES,
                'options' => [
                    ['value' => self::MODE_PLACES, 'label' => 'Places API (5 most recent, any place)'],
                    ['value' => self::MODE_BUSINESS_PROFILE, 'label' => 'Business Profile API (full history, only owned profiles)'],
                ],
            ],
            // --- Places API mode fields ---
            [
                'handle' => 'apiKey',
                'label' => 'API key',
                'instructions' => 'A Google Cloud API key with the Places API (New) enabled.',
                'type' => 'text',
                'secret' => true,
                'mode' => self::MODE_PLACES,
            ],
            [
                'handle' => 'placeId',
                'label' => 'Place ID',
                'instructions' => 'The Google Place ID for the location to pull reviews from. Use the "Find a Place ID" helper below or find yours at https://developers.google.com/maps/documentation/places/web-service/place-id.',
                'type' => 'text',
                'placeholder' => 'ChIJ…',
                'mode' => self::MODE_PLACES,
            ],
            // --- Business Profile API mode fields ---
            [
                'handle' => 'clientId',
                'label' => 'OAuth Client ID',
                'instructions' => 'From your Google Cloud project\'s OAuth 2.0 Client (type: Web application).',
                'type' => 'text',
                'secret' => true,
                'mode' => self::MODE_BUSINESS_PROFILE,
            ],
            [
                'handle' => 'clientSecret',
                'label' => 'OAuth Client Secret',
                'instructions' => 'From the same OAuth 2.0 Client. Treated as a secret credential.',
                'type' => 'text',
                'secret' => true,
                'mode' => self::MODE_BUSINESS_PROFILE,
            ],
            [
                'handle' => 'refreshToken',
                'label' => 'Refresh Token',
                'instructions' => 'Set by the "Connect Google account" button. Do not paste manually.',
                'type' => 'text',
                'secret' => true,
                'readOnly' => true,
                'mode' => self::MODE_BUSINESS_PROFILE,
            ],
            [
                'handle' => 'locationName',
                'label' => 'Location resource name',
                'instructions' => 'Format: accounts/{accountId}/locations/{locationId}. After connecting, pick from the discovered list - or paste manually if you already have it.',
                'type' => 'text',
                'placeholder' => 'accounts/123/locations/456',
                'mode' => self::MODE_BUSINESS_PROFILE,
            ],
        ];
    }

    public function testConnection(Source $source): array
    {
        $mode = (string) ($source->settings['mode'] ?? self::MODE_PLACES);

        if ($mode === self::MODE_BUSINESS_PROFILE) {
            return $this->testBusinessProfileConnection($source);
        }

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
        $mode = (string) ($source->settings['mode'] ?? self::MODE_PLACES);

        if ($mode === self::MODE_BUSINESS_PROFILE) {
            yield from $this->fetchBusinessProfileReviews($source, $since);
            return;
        }

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
            // Set `backfillDays` to 0 to disable this and import every review
            // the Places API returns (recommended for Google sources since
            // Places-New caps at 5 anyway).
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

    /**
     * Pull all reviews for the configured location via Business Profile API v4.
     * Paginates via `nextPageToken`. Each review is normalised through the
     * same FetchedReview DTO the Places-mode path uses - so the sync service
     * doesn't need to know which mode is in play.
     *
     * @return iterable<FetchedReview>
     */
    private function fetchBusinessProfileReviews(Source $source, ?\DateTimeInterface $since): iterable
    {
        $accessToken = $this->resolveAccessToken($source);
        $location = (string) ($source->settings['locationName'] ?? '');
        if ($location === '') {
            throw new \RuntimeException('Business Profile source is missing a location resource name.');
        }

        $client = Craft::createGuzzleClient(['timeout' => 20]);
        $url = self::ENDPOINT_BUSINESS_REVIEWS . $location . '/reviews';
        $pageToken = null;

        do {
            $response = $client->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
                'query' => array_filter([
                    'pageSize' => 50,
                    'pageToken' => $pageToken,
                ]),
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            $decoded = json_decode((string) $response->getBody(), true);
            if ($status >= 400 || !is_array($decoded)) {
                $message = $decoded['error']['message']
                    ?? sprintf('Business Profile API returned HTTP %d.', $status);
                throw new \RuntimeException($message);
            }

            foreach (($decoded['reviews'] ?? []) as $row) {
                $externalId = $row['reviewId'] ?? $row['name'] ?? null;
                if (!$externalId) continue;

                $publishTime = isset($row['createTime']) ? $this->parseDate($row['createTime']) : null;

                // Apply the global backfill cursor same as other connectors -
                // Business Profile DOES paginate, so this saves API calls.
                if ($since && $publishTime && $publishTime < $since) {
                    return;
                }

                $starRating = $row['starRating'] ?? null;
                $rating = match ($starRating) {
                    'ONE' => 1.0, 'TWO' => 2.0, 'THREE' => 3.0, 'FOUR' => 4.0, 'FIVE' => 5.0,
                    default => 0.0,
                };

                yield new FetchedReview(
                    externalId: (string) $externalId,
                    rating: $rating,
                    headline: null,
                    review: $row['comment'] ?? null,
                    reviewerName: $row['reviewer']['displayName'] ?? null,
                    reviewerEmail: null,
                    reviewedAt: $publishTime,
                    businessReply: $row['reviewReply']['comment'] ?? null,
                    raw: $row,
                );
            }

            $pageToken = $decoded['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    /**
     * Cheap "is everything wired up?" probe for the Business Profile mode.
     * Issues a single, page-size-1 request so the test doesn't drag the
     * full review history.
     *
     * @return array{ok: bool, message: string}
     */
    private function testBusinessProfileConnection(Source $source): array
    {
        try {
            $accessToken = $this->resolveAccessToken($source);
            $location = (string) ($source->settings['locationName'] ?? '');
            if ($location === '') {
                return ['ok' => false, 'message' => 'Set a location resource name (accounts/.../locations/...) first.'];
            }

            $client = Craft::createGuzzleClient(['timeout' => 15]);
            $response = $client->request('GET', self::ENDPOINT_BUSINESS_REVIEWS . $location . '/reviews', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
                'query' => ['pageSize' => 1],
                'http_errors' => false,
            ]);
            $decoded = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() >= 400 || !is_array($decoded)) {
                return [
                    'ok' => false,
                    'message' => $decoded['error']['message'] ?? sprintf('HTTP %d from Business Profile API.', $response->getStatusCode()),
                ];
            }
            $total = $decoded['totalReviewCount'] ?? null;
            return [
                'ok' => true,
                'message' => sprintf('Connected. %s reviews available on this location.', $total ?? 'unknown number of'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Centralised access-token resolver for Business Profile calls. Reads the
     * stored refresh token + OAuth client credentials from the source and
     * trades them for a fresh access token.
     */
    private function resolveAccessToken(Source $source): string
    {
        $clientId = App::parseEnv((string) ($source->credentials['clientId'] ?? ''));
        $clientSecret = App::parseEnv((string) ($source->credentials['clientSecret'] ?? ''));
        $refreshToken = App::parseEnv((string) ($source->credentials['refreshToken'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new \RuntimeException('Business Profile source is missing OAuth credentials. Click "Connect Google account" on the source edit page.');
        }

        return self::refreshAccessToken($clientId, $clientSecret, $refreshToken);
    }

    private function parseDate(string $value): ?\DateTime
    {
        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Trade a one-shot OAuth auth code (received on the callback) for an
     * access token + refresh token. Only the refresh token is persisted -
     * we mint fresh access tokens for each API call via `refreshAccessToken`.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws \RuntimeException on token-endpoint failure
     */
    public static function exchangeAuthCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $client = Craft::createGuzzleClient(['timeout' => 15]);
        $response = $client->request('POST', self::OAUTH_TOKEN, [
            'form_params' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
            'http_errors' => false,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || empty($decoded['refresh_token'])) {
            $err = $decoded['error_description'] ?? $decoded['error'] ?? 'Unknown token-exchange error.';
            throw new \RuntimeException($err);
        }
        return $decoded;
    }

    /**
     * Mint a fresh access token from a stored refresh token. Google's tokens
     * expire in ~60 minutes, so this runs on each API call (cheap, no caching
     * needed for the scale Vouch operates at).
     *
     * @throws \RuntimeException on refresh failure (e.g. revoked grant)
     */
    public static function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): string
    {
        $client = Craft::createGuzzleClient(['timeout' => 15]);
        $response = $client->request('POST', self::OAUTH_TOKEN, [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
            'http_errors' => false,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            $err = $decoded['error_description'] ?? $decoded['error'] ?? 'Could not refresh access token.';
            throw new \RuntimeException($err);
        }
        return (string) $decoded['access_token'];
    }

    /**
     * Search Google Places by free-text query, returning the candidate
     * matches with their Place IDs. Used by the "Find Place ID" helper on
     * the source edit page so admins can avoid hand-pasting a Place ID.
     *
     * @return array<int, array{id: string, displayName: string, address: string}>
     * @throws \RuntimeException when the API key is rejected or returns an error
     */
    public static function searchPlaces(string $apiKey, string $query): array
    {
        $apiKey = trim($apiKey);
        $query = trim($query);
        if ($apiKey === '' || $query === '') {
            throw new \RuntimeException('Both an API key and a search query are required.');
        }

        $client = Craft::createGuzzleClient(['timeout' => 15]);

        $response = $client->request('POST', self::ENDPOINT_SEARCH, [
            'headers' => [
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => ['textQuery' => $query],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);

        if ($status >= 400) {
            $message = $decoded['error']['message']
                ?? sprintf('Google Places API returned HTTP %d.', $status);
            throw new \RuntimeException($message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Google Places API returned an unparseable response.');
        }

        return array_map(fn(array $row) => [
            'id' => (string) ($row['id'] ?? ''),
            'displayName' => (string) ($row['displayName']['text'] ?? ''),
            'address' => (string) ($row['formattedAddress'] ?? ''),
        ], $decoded['places'] ?? []);
    }
}
