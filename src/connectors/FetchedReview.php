<?php

namespace bymayo\vouch\connectors;

/**
 * Provider-agnostic DTO produced by a connector's `fetchReviews()`. The sync
 * service maps this onto a `Review` element, deduplicating by
 * `(sourceId, externalId)`. Everything except `externalId` and `rating` is
 * optional — providers vary wildly in what they expose.
 */
final class FetchedReview
{
    public function __construct(
        /** Provider's stable identifier for the review. Required for dedup. */
        public readonly string $externalId,
        /** Normalised rating, 0–5 (decimals allowed, e.g. 4.5). */
        public readonly float $rating,
        public readonly ?string $headline = null,
        public readonly ?string $review = null,
        public readonly ?string $reviewerName = null,
        public readonly ?string $reviewerEmail = null,
        public readonly ?\DateTimeInterface $reviewedAt = null,
        /** Optional business reply, if the provider returned one. */
        public readonly ?string $businessReply = null,
        /** Provider's full raw payload for this review — stored for forensics. */
        public readonly array $raw = [],
    ) {
    }
}
