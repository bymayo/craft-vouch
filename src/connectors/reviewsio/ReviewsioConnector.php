<?php

namespace bymayo\vouch\connectors\reviewsio;

use bymayo\vouch\connectors\BaseConnector;
use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\models\Source;
use Craft;
use craft\helpers\App;

/**
 * Reviews.io connector - Merchant Reviews API.
 *
 *  - Endpoint: `https://api.reviews.io/merchant/latest`
 *  - Auth: `store` (store id) + `apikey` (issued from Reviews.io dashboard
 *    under Integrations → API).
 *  - Pagination: `per_page` + `page` (1-indexed), walked until the response
 *    returns fewer than `per_page` entries.
 *  - Reviewer email is exposed when available, which makes Reviews.io the
 *    one v1 connector that can drive the Points "match by email" path
 *    cleanly. (Google + Trustpilot never expose email; Feefo does only on
 *    paid plans.)
 *
 * @see https://developer.reviews.io/docs
 */
class ReviewsioConnector extends BaseConnector
{
    public const ENDPOINT = 'https://api.reviews.io/merchant/latest';

    public static function handle(): string
    {
        return 'reviewsio';
    }

    public static function displayName(): string
    {
        return 'Reviews.io';
    }

    public static function icon(): ?string
    {
        return self::loadIcon('reviewsio');
    }

    public static function settingsSchema(): array
    {
        return [
            [
                'handle' => 'storeId',
                'label' => 'Store ID',
                'instructions' => 'Your Reviews.io store identifier.',
                'type' => 'text',
                'required' => true,
            ],
            [
                'handle' => 'apiKey',
                'label' => 'API key',
                'instructions' => 'Reviews.io API key. Generate one under Integrations → API.',
                'type' => 'text',
                'secret' => true,
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

        $stats = $first['stats'] ?? [];
        $rating = $stats['average_rating'] ?? null;
        $count = $stats['total_reviews'] ?? null;

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected. %s reviews, average %s★.',
                $count ?? '–',
                $rating !== null ? number_format((float) $rating, 1) : '–',
            ),
        ];
    }

    public function fetchReviews(Source $source, ?\DateTimeInterface $since = null): iterable
    {
        $page = 1;
        $perPage = 100;

        while (true) {
            $batch = $this->fetchPage($source, $page, $perPage);
            $reviews = $batch['reviews'] ?? $batch['stats']['reviews'] ?? [];

            if (empty($reviews)) {
                break;
            }

            foreach ($reviews as $row) {
                // Reviews.io uses `_id` on Mongo-style responses or `id` on
                // newer endpoints - accept either.
                $externalId = (string) ($row['_id'] ?? $row['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $reviewedAt = isset($row['date_created'])
                    ? $this->parseDate((string) $row['date_created'])
                    : null;

                if ($since && $reviewedAt && $reviewedAt < $since) {
                    return;
                }

                yield new FetchedReview(
                    externalId: $externalId,
                    rating: (float) ($row['rating'] ?? 0),
                    headline: $row['heading'] ?? ($row['title'] ?? null),
                    review: $row['comments'] ?? ($row['review'] ?? null),
                    reviewerName: $row['reviewer']['display_name']
                        ?? $row['reviewer']['name']
                        ?? null,
                    reviewerEmail: $row['reviewer']['email'] ?? null,
                    reviewedAt: $reviewedAt,
                    businessReply: $row['reply'] ?? null,
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
        $storeId = App::parseEnv((string) ($source->settings['storeId'] ?? ''));
        $apiKey = App::parseEnv((string) ($source->credentials['apiKey'] ?? ''));

        if ($storeId === '' || $apiKey === '') {
            throw new \RuntimeException('Reviews.io source is missing storeId or apiKey.');
        }

        $client = Craft::createGuzzleClient(['timeout' => 20]);

        $response = $client->request('GET', self::ENDPOINT, [
            'query' => [
                'store' => $storeId,
                'apikey' => $apiKey,
                'per_page' => $perPage,
                'page' => $page,
            ],
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = $decoded['message']
                ?? $decoded['error']
                ?? sprintf('Reviews.io API returned HTTP %d.', $status);
            throw new \RuntimeException(is_string($message) ? $message : 'Reviews.io API error.');
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
