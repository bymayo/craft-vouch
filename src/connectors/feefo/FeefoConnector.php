<?php

namespace bymayo\vouch\connectors\feefo;

use bymayo\vouch\connectors\BaseConnector;
use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\models\Source;
use Craft;
use craft\helpers\App;

/**
 * Feefo connector — Reviews API v20.
 *
 * Feefo's public read API uses a merchant identifier (the "merchant_identifier"
 * value on your Feefo dashboard) as the only required parameter. An API key
 * is also accepted via `Authorization: Bearer …` for paid plans that expose
 * extra fields (sentiment, media attachments). We send it when present but
 * fall back to the public feed gracefully.
 *
 *  - Endpoint: `https://api.feefo.com/api/20/reviews/all`
 *  - Pagination: `page_size` + `page_number`. The response's `pagination`
 *    block tells us the total page count.
 *  - Each Feefo "review" can contain BOTH a service review and one or more
 *    product reviews. v1 surfaces the service review only — products will
 *    land in Phase 3.1 when we add per-review element relations.
 *  - Ratings are typically on a 0–5 scale; we trust the `rating.rating`
 *    field and clamp at 5 for safety.
 *
 * @see https://developers.feefo.com/docs
 */
class FeefoConnector extends BaseConnector
{
    public const ENDPOINT = 'https://api.feefo.com/api/20/reviews/all';

    public static function handle(): string
    {
        return 'feefo';
    }

    public static function displayName(): string
    {
        return 'Feefo';
    }

    public static function icon(): ?string
    {
        return self::loadIcon('feefo');
    }

    public static function settingsSchema(): array
    {
        return [
            [
                'handle' => 'merchantIdentifier',
                'label' => 'Merchant identifier',
                'instructions' => 'The merchant identifier from your Feefo dashboard (e.g. `acme-inc`).',
                'type' => 'text',
                'required' => true,
            ],
            [
                'handle' => 'apiKey',
                'label' => 'API key',
                'instructions' => 'Only required for paid plans that expose richer fields. Leave blank to use the public feed.',
                'type' => 'text',
                'secret' => true,
                'required' => false,
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

        $summary = $first['summary']['meta'] ?? [];
        $count = $summary['count'] ?? null;
        $rating = $first['summary']['service']['rating'] ?? null;

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected. %s reviews on file%s.',
                $count !== null ? (string) $count : 'an unknown number of',
                $rating !== null ? ', average ' . number_format((float) $rating, 1) . '★' : '',
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
                $service = $row['service'] ?? null;
                if (!$service) {
                    // Pure product-review row — defer to Phase 3.1.
                    continue;
                }

                // Feefo doesn't always include a stable `id`. Compose one
                // from `url` (which is unique per review) as a fallback so
                // dedup still works.
                $externalId = (string) ($service['review_id'] ?? $row['url'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $reviewedAt = isset($service['created_at'])
                    ? $this->parseDate($service['created_at'])
                    : null;

                if ($since && $reviewedAt && $reviewedAt < $since) {
                    // Default ordering is newest-first; once one's older
                    // than the cursor we can stop walking pages too.
                    return;
                }

                $rawRating = $row['rating']['rating']
                    ?? $service['rating']['rating']
                    ?? 0;

                yield new FetchedReview(
                    externalId: $externalId,
                    rating: min(5.0, (float) $rawRating),
                    title: $service['title'] ?? null,
                    body: $service['review'] ?? null,
                    authorName: $row['customer']['name'] ?? null,
                    // Feefo redacts customer email by default; some plans
                    // surface it under `customer.email`. Pass it through
                    // when present, leave null otherwise.
                    authorEmail: $row['customer']['email'] ?? null,
                    reviewedAt: $reviewedAt,
                    response: $service['reply'] ?? null,
                    raw: $row,
                );
            }

            $pagination = $batch['summary']['meta']['pagination'] ?? null;
            $totalPages = $pagination['total_pages'] ?? null;
            if ($totalPages !== null && $page >= (int) $totalPages) {
                break;
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
        $merchant = App::parseEnv((string) ($source->settings['merchantIdentifier'] ?? ''));
        if ($merchant === '') {
            throw new \RuntimeException('Feefo source is missing merchantIdentifier.');
        }

        $apiKey = App::parseEnv((string) ($source->credentials['apiKey'] ?? ''));
        $client = Craft::createGuzzleClient(['timeout' => 20]);

        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = $client->request('GET', self::ENDPOINT, [
            'query' => [
                'merchant_identifier' => $merchant,
                'page_size' => $perPage,
                'page_number' => $page,
                'order' => 'date_desc',
                'full_thread' => 'true',
            ],
            'headers' => $headers,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = $decoded['message']
                ?? $decoded['error']
                ?? sprintf('Feefo API returned HTTP %d.', $status);
            throw new \RuntimeException(is_string($message) ? $message : 'Feefo API error.');
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
