<?php

namespace bymayo\vouch\events;

use bymayo\vouch\elements\Review;
use bymayo\vouch\models\Source;
use yii\base\Event;

/**
 * Fired by `Reviews::upsertFromFetched()` once a review has been persisted
 * (whether newly created or re-synced). Listeners can use this to mirror
 * reviews into other systems, build audit trails, or chain notifications.
 *
 * Note: this fires for **every** synced review, including ones held for
 * moderation. If you only care about the moment a review becomes publicly
 * visible, subscribe to `EVENT_AFTER_APPROVE_REVIEW` instead.
 */
class ReviewSyncEvent extends Event
{
    public function __construct(
        public Review $review,
        public Source $source,
        public bool $isNew,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
