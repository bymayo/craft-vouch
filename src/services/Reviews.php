<?php

namespace bymayo\vouch\services;

use bymayo\vouch\connectors\FetchedReview;
use bymayo\vouch\elements\Review;
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\events\ReviewSyncEvent;
use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use Craft;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Domain logic for `Review` elements - upserting from a `FetchedReview` DTO,
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
     * Fired the first time a review becomes approved - once per review,
     * never re-fires on re-sync.
     */
    public const EVENT_AFTER_APPROVE_REVIEW = 'afterApproveReview';

    /**
     * Average approved rating across every review related to the given
     * element id. Used by the entry/product index "Rating" column. Runs
     * as a single AVG aggregate so it stays cheap when the column is on.
     */
    public function averageRatingForElement(int $elementId): ?float
    {
        $avg = (new Query())
            ->from('{{%vouch_reviews}}')
            ->where(['relatedElementId' => $elementId, 'approved' => true])
            ->average('rating');

        return $avg !== null ? (float) $avg : null;
    }

    /**
     * Combined avg + count for a single element. Single SQL query rather
     * than two separate aggregates. Returns null when the element has no
     * approved reviews.
     *
     * @return array{avg:float,count:int}|null
     */
    public function ratingSummaryForElement(int $elementId): ?array
    {
        $row = (new Query())
            ->from('{{%vouch_reviews}}')
            ->select(['avg' => 'AVG(rating)', 'count' => 'COUNT(*)'])
            ->where(['relatedElementId' => $elementId, 'approved' => true])
            ->one();

        if (!$row || $row['count'] == 0) {
            return null;
        }

        return [
            'avg' => (float) $row['avg'],
            'count' => (int) $row['count'],
        ];
    }

    /**
     * Per-source rating + review count for the given element. Used by the
     * sidebar summary so admins can see "Google: 4.5 (8), Trustpilot: 3.8 (7)"
     * at a glance.
     *
     * @return array<int, array{sourceId:int, sourceName:string, providerHandle:string, average:float, count:int}>
     */
    public function ratingBreakdownForElement(int $elementId): array
    {
        $rows = (new Query())
            ->from(['r' => '{{%vouch_reviews}}'])
            ->leftJoin(['s' => '{{%vouch_sources}}'], '[[r.sourceId]] = [[s.id]]')
            ->select([
                'sourceId' => 's.id',
                'sourceName' => 's.name',
                'providerHandle' => 's.providerHandle',
                'average' => 'AVG([[r.rating]])',
                'count' => 'COUNT(*)',
            ])
            ->where(['r.relatedElementId' => $elementId, 'r.approved' => true])
            ->groupBy(['s.id', 's.name', 's.providerHandle'])
            ->orderBy(['average' => SORT_DESC])
            ->all();

        return array_map(fn($row) => [
            'sourceId' => (int) $row['sourceId'],
            'sourceName' => (string) $row['sourceName'],
            'providerHandle' => (string) $row['providerHandle'],
            'average' => (float) $row['average'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Aggregate approved reviews grouped by `relatedElementId`, joined to
     * `{{%elements}}` so we can filter by element type and optionally by
     * an entry section. Used by the "Top reviewed elements" dashboard widget.
     *
     * @param string|null $elementType Fully-qualified Element class
     * @param int|null $sectionId Restrict to entries in this section (only meaningful when $elementType = Entry)
     * @param 'count'|'average' $sort
     * @return array<int, array{elementId:int, count:int, average:float}>
     */
    public function topReviewedElements(
        ?string $elementType = null,
        ?int $sectionId = null,
        string $sort = 'count',
        int $limit = 10,
    ): array {
        $query = (new Query())
            ->from(['r' => '{{%vouch_reviews}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[r.relatedElementId]] = [[e.id]]')
            ->select([
                'elementId' => 'r.relatedElementId',
                'count' => 'COUNT(*)',
                'average' => 'AVG([[r.rating]])',
            ])
            ->where(['r.approved' => true])
            ->andWhere(['not', ['r.relatedElementId' => null]])
            ->andWhere(['e.dateDeleted' => null])
            ->groupBy(['r.relatedElementId'])
            ->limit($limit);

        if ($elementType !== null) {
            $query->andWhere(['e.type' => $elementType]);
        }

        // Entries live in sections via the `{{%entries}}` table - join only
        // when filtering by section so we don't pay for it otherwise.
        if ($sectionId !== null) {
            $query->innerJoin(['en' => '{{%entries}}'], '[[en.id]] = [[r.relatedElementId]]');
            $query->andWhere(['en.sectionId' => $sectionId]);
        }

        $query->orderBy([
            $sort === 'average' ? 'average' : 'count' => SORT_DESC,
        ]);

        return array_map(fn($row) => [
            'elementId' => (int) $row['elementId'],
            'count' => (int) $row['count'],
            'average' => (float) $row['average'],
        ], $query->all());
    }

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
        $review->headline = $fetched->headline;
        $review->review = $fetched->review;
        $review->reviewerName = $fetched->reviewerName;
        $review->reviewerEmail = $fetched->reviewerEmail;
        $review->reviewerEmailHash = $fetched->reviewerEmail
            ? hash('sha256', strtolower(trim($fetched->reviewerEmail)))
            : null;
        $review->reviewedAt = $fetched->reviewedAt instanceof \DateTime
            ? $fetched->reviewedAt
            : ($fetched->reviewedAt ? new \DateTime($fetched->reviewedAt->format('c')) : null);
        $review->businessReply = $fetched->businessReply;
        $review->raw = $fetched->raw ? Json::encode($fetched->raw) : null;

        // Target element relation - if the source is wired to a specific
        // entry/product, every review it brings in points at that element.
        // Later phases may resolve a more granular relation per-review (e.g.
        // Trustpilot product reviews carry their own product reference).
        $review->relatedElementId = $source->targetElementId;

        $this->matchReviewerToUser($review);
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
     * Use this from controllers - it sets up the externalId, applies the
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
     * and (if newly approved) `EVENT_AFTER_APPROVE_REVIEW` - same contract
     * downstream listeners see for synced reviews, so a single set of
     * subscribers handles both paths.
     */
    public function save(Review $review): bool
    {
        $isNew = !$review->id;
        $wasApproved = $isNew ? false : (bool) $review->approved;

        if ($review->reviewerEmail) {
            $review->reviewerEmailHash = hash('sha256', strtolower(trim($review->reviewerEmail)));
        } else {
            $review->reviewerEmailHash = null;
        }

        // Email-based user matching is deliberately NOT auto-applied here.
        // For manual submissions, an anonymous attacker could otherwise forge
        // attribution by submitting a victim's email. The sync path
        // (`upsertFromFetched`) still calls `matchReviewerToUser` because
        // provider emails are trusted; the front-end controller links
        // `reviewerUserId` only when the submitter is logged in AND the
        // submitted email matches their own Craft account.

        // Apply the source's moderation policy on new reviews so the
        // auto-approve threshold is respected on every path - front-end
        // submission, CP authoring, and API sync alike.
        if ($isNew) {
            $source = $review->getSource();
            if ($source) {
                $this->applyModeration($review, $source, true);
            }
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
     * Find an existing Craft user whose email matches the reviewer's,
     * if matching is enabled and the email is known. Never auto-creates
     * users - that's a deliberate user-decision boundary.
     */
    private function matchReviewerToUser(Review $review): void
    {
        $settings = Vouch::getInstance()->getSettings();
        if (!$settings->matchAuthorsToUsers || !$review->reviewerEmail) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($review->reviewerEmail);
        if ($user) {
            $review->reviewerUserId = $user->id;
        }
    }

    /**
     * Decide whether the review should land approved or pending. Only applies
     * on first ingest - re-syncing a review never silently flips an admin's
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
