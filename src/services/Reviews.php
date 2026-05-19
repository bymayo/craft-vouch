<?php

namespace bymayo\vouch\services;

use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\elements\Review;
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\events\ReviewSyncEvent;
use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use Craft;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Domain logic for `Review` elements — upserting from a `FetchedReview` DTO,
 * applying moderation rules, and matching authors to Craft users.
 *
 * This service is intentionally connector-agnostic: it never knows about
 * Google or Trustpilot. Connectors hand it normalised DTOs and it deals with
 * persistence + Craft user resolution + the approval flag.
 */
class Reviews extends Component
{
    /** Fired after every successful upsert from a `FetchedReview`. */
    public const EVENT_AFTER_SYNC_REVIEW = 'afterSyncReview';

    /**
     * Fired the first time a review becomes approved — once per review,
     * never re-fires on re-sync.
     */
    public const EVENT_AFTER_APPROVE_REVIEW = 'afterApproveReview';

    /**
     * Find an existing Review by `(sourceId, externalId)`. Returns null if
     * this is the first time we've seen the provider's review id.
     */
    public function findBySourceAndExternalId(int $sourceId, string $externalId): ?Review
    {
        return Review::find()
            ->sourceId($sourceId)
            ->externalId($externalId)
            ->status(null)
            ->one();
    }

    /**
     * Upsert a single fetched review against the given Source. Creates a new
     * `Review` element on first sight; updates rating/body/response on
     * subsequent syncs (providers do edit / reply to reviews after the fact).
     *
     * Returns the saved `Review` on success, or null if validation failed.
     */
    public function upsertFromFetched(Source $source, FetchedReview $fetched): ?Review
    {
        $review = $this->findBySourceAndExternalId($source->id, $fetched->externalId)
            ?? new Review();

        $isNew = !$review->id;
        // Track approval transition so we can fire the approval event below.
        // Manual re-syncs of an already-approved review must NOT re-fire it.
        $wasApproved = $isNew ? false : (bool) $review->approved;

        $review->sourceId = $source->id;
        $review->externalId = $fetched->externalId;
        $review->rating = $fetched->rating;
        $review->title = $fetched->title;
        $review->body = $fetched->body;
        $review->authorName = $fetched->authorName;
        $review->authorEmail = $fetched->authorEmail;
        $review->authorEmailHash = $fetched->authorEmail
            ? hash('sha256', strtolower(trim($fetched->authorEmail)))
            : null;
        $review->reviewedAt = $fetched->reviewedAt instanceof \DateTime
            ? $fetched->reviewedAt
            : ($fetched->reviewedAt ? new \DateTime($fetched->reviewedAt->format('c')) : null);
        $review->response = $fetched->response;
        $review->raw = $fetched->raw ? Json::encode($fetched->raw) : null;

        // Target element relation — if the source is wired to a specific
        // entry/product, every review it brings in points at that element.
        // Later phases may resolve a more granular relation per-review (e.g.
        // Trustpilot product reviews carry their own product reference).
        $review->relatedElementId = $source->targetElementId;

        $this->matchAuthorToUser($review);
        $this->applyModeration($review, $source, $isNew);

        if (!Craft::$app->getElements()->saveElement($review)) {
            return null;
        }

        $this->trigger(self::EVENT_AFTER_SYNC_REVIEW, new ReviewSyncEvent(
            review: $review,
            source: $source,
            isNew: $isNew,
        ));

        // Approval transition: either a brand-new review that landed
        // already-approved (auto-approve path), or a previously-pending
        // review whose moderation rules now resolve to approved on re-sync.
        if ($review->approved && !$wasApproved) {
            $this->trigger(self::EVENT_AFTER_APPROVE_REVIEW, new ReviewApprovalEvent(
                review: $review,
                source: $source,
                auto: true,
            ));
        }

        return $review;
    }

    /**
     * Build (don't save) a new manual review attached to the given source.
     * Use this from controllers — it sets up the externalId, applies the
     * source's moderation defaults, and matches authors-to-users. Caller is
     * responsible for setting any other fields and then calling `save()`.
     */
    public function newManualReview(Source $source): Review
    {
        $review = new Review();
        $review->sourceId = $source->id;
        $review->externalId = 'manual:' . StringHelper::UUID();
        $review->relatedElementId = $source->targetElementId;
        $review->reviewedAt = new \DateTime();
        $review->approved = !$source->requiresApproval;
        return $review;
    }

    /**
     * Persist a manually-authored review. Triggers `EVENT_AFTER_SYNC_REVIEW`
     * and (if newly approved) `EVENT_AFTER_APPROVE_REVIEW` — same contract
     * downstream listeners see for synced reviews, so a single set of
     * subscribers handles both paths.
     */
    public function save(Review $review): bool
    {
        $isNew = !$review->id;
        $wasApproved = $isNew ? false : (bool) $review->approved;

        if ($review->authorEmail) {
            $review->authorEmailHash = hash('sha256', strtolower(trim($review->authorEmail)));
            if (!$review->authorUserId) {
                $this->matchAuthorToUser($review);
            }
        } else {
            $review->authorEmailHash = null;
        }

        if (!Craft::$app->getElements()->saveElement($review)) {
            return false;
        }

        $source = $review->getSource();
        if ($source) {
            $this->trigger(self::EVENT_AFTER_SYNC_REVIEW, new ReviewSyncEvent(
                review: $review,
                source: $source,
                isNew: $isNew,
            ));

            if ($review->approved && !$wasApproved) {
                $this->trigger(self::EVENT_AFTER_APPROVE_REVIEW, new ReviewApprovalEvent(
                    review: $review,
                    source: $source,
                    auto: $isNew,
                ));
            }
        }

        return true;
    }

    public function delete(Review $review): bool
    {
        return (bool) Craft::$app->getElements()->deleteElement($review);
    }

    /**
     * Manually approve a review (the CP "Approve" button calls this). Fires
     * `EVENT_AFTER_APPROVE_REVIEW` with `auto=false` when the review wasn't
     * already approved.
     */
    public function approve(Review $review): bool
    {
        $wasApproved = (bool) $review->approved;
        $review->approved = true;

        if (!Craft::$app->getElements()->saveElement($review)) {
            return false;
        }

        if (!$wasApproved) {
            $source = $review->getSource();
            if ($source) {
                $this->trigger(self::EVENT_AFTER_APPROVE_REVIEW, new ReviewApprovalEvent(
                    review: $review,
                    source: $source,
                    auto: false,
                ));
            }
        }

        return true;
    }

    /**
     * Find an existing Craft user whose email matches the review author's,
     * if matching is enabled and the email is known. Never auto-creates
     * users — that's a deliberate user-decision boundary.
     */
    private function matchAuthorToUser(Review $review): void
    {
        $settings = Vouch::getInstance()->getSettings();
        if (!$settings->matchAuthorsToUsers || !$review->authorEmail) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($review->authorEmail);
        if ($user) {
            $review->authorUserId = $user->id;
        }
    }

    /**
     * Decide whether the review should land approved or pending. Only applies
     * on first ingest — re-syncing a review never silently flips an admin's
     * earlier approval decision.
     */
    private function applyModeration(Review $review, Source $source, bool $isNew): void
    {
        if (!$isNew) {
            return;
        }

        if (!$source->requiresApproval) {
            $review->approved = true;
            return;
        }

        // Approval queue is on. Reviews at or above the plugin-wide
        // threshold skip the queue; anything less holds for an admin.
        $threshold = Vouch::getInstance()->getSettings()->autoApproveThreshold;
        $review->approved = $review->rating >= $threshold;
    }
}
