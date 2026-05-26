<?php

namespace bymayo\vouch\connectors\trustpilot;

use bymayo\vouch\connectors\BaseConnector;
use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\models\Source;
use Craft;
use craft\helpers\App;

/**
 * Trustpilot connector - public Business Units API.
 *
 * v1 uses the public read endpoint with API-key auth, which exposes the same
 * review feed visible on the company's Trustpilot page. The richer private
 * endpoints (replies, invitation status, consumer email) require OAuth and a
 * verified business agreement - that's Phase 6 (push) territory.
 *
 *  - Auth: `apikey` query param. Keys are issued from the Trustpilot Business
 *    portal under Integrations → API Access.
 *  - Pagination: `?page=N&perPage=100`, walked until a page returns fewer
 *    than `perPage` reviews.
 *  - `consumer.email` is **not** returned by the public API. User-matching
 *    won't fire for Trustpilot reviews unless the consumer also happens to
 *    register on the Craft site with the same display name (it won't -
 *    don't rely on it).
 *
 * @see https://developers.trustpilot.com/business-units-api
 */
class TrustpilotConnector extends BaseConnector
{
    public const ENDPOINT = 'https://api.trustpilot.com/v1/business-units/';

    public static function handle(): string
    {
        return 'trustpilot';
    }

    public static function displayName(): string
    {
        return 'Trustpilot';
    }

    public static function icon(): ?string
    {
        return self::loadIcon('trustpilot');
    }

    public static function settingsSchema(): array
    {
        return [
            [
                'handle' => 'apiKey',
                'label' => 'API key',
                'instructions' => 'Trustpilot Business API key. Issue one in Integrations → API Access.',
                'type' => 'text',
                'secret' => true,
                'required' => true,
            ],
            [
                'handle' => 'businessUnitId',
                'label' => 'Business Unit ID',
                'instructions' => 'Your Trustpilot business unit id (24-char hex). Find it via the “Find a business unit” endpoint or your business profile URL.',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function testConnection(Source $source): array
    {
        try {
            $first = $this->fetchPage($source, 1, 1);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $reviews = $first['reviews'] ?? [];
        return [
            'ok' => true,
            'message' => sprintf(
                'Connected. Most recent review: %s',
                $reviews[0]['createdAt'] ?? 'none yet.',
            ),
        ];
    }

    public function fetchReviews(Source $source, ?\DateTimeInterface $since = null): iterable
    {
        $page = 1;
        $perPage = 100;

        while (true) {
            $batch = $this->fetchPage($source, $page, $perPage);
            $reviews = $batch['reviews'] ?? [];

            if (empty($reviews)) {
                break;
            }

            foreach ($reviews as $row) {
                $externalId = (string) ($row['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $reviewedAt = isset($row['createdAt']) ? $this->parseDate($row['createdAt']) : null;

                // Trustpilot's `createdat.desc` ordering means once we see a
                // review older than the cursor, all subsequent rows are too.
                // Bail early to spare us pagination noise.
                if ($since && $reviewedAt && $reviewedAt < $since) {
                    return;
                }

                yield new FetchedReview(
                    externalId: $externalId,
                    rating: (float) ($row['stars'] ?? 0),
                    headline: $row['title'] ?? null,
                    review: $row['text'] ?? null,
                    reviewerName: $row['consumer']['displayName'] ?? null,
                    reviewerEmail: null,
                    reviewedAt: $reviewedAt,
                    businessReply: $row['companyReply']['text'] ?? null,
                    raw: $row,
                );
            }

            if (count($reviews) < $perPage) {
                break;
            }
            $page++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(Source $source, int $page, int $perPage): array
    {
        $apiKey = App::parseEnv((string) ($source->credentials['apiKey'] ?? ''));
        $businessUnitId = App::parseEnv((string) ($source->settings['businessUnitId'] ?? ''));

        if ($apiKey === '' || $businessUnitId === '') {
            throw new \RuntimeException('Trustpilot source is missing apiKey or businessUnitId.');
        }

        $client = Craft::createGuzzleClient(['timeout' => 20]);
        $url = self::ENDPOINT . rawurlencode($businessUnitId) . '/reviews';

        $response = $client->request('GET', $url, [
            'query' => [
                'apikey' => $apiKey,
                'page' => $page,
                'perPage' => $perPage,
                'orderBy' => 'createdat.desc',
            ],
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = $decoded['message']
                ?? $decoded['details']
                ?? sprintf('Trustpilot API returned HTTP %d.', $status);
            throw new \RuntimeException(is_string($message) ? $message : 'Trustpilot API error.');
        }

        return is_array($decoded) ? $decoded : [];
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
