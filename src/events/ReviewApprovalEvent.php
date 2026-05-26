<?php

namespace bymayo\vouch\events;

use bymayo\vouch\elements\Review;
use bymayo\vouch\models\Source;
use yii\base\Event;

/**
 * Fired when a review transitions to the approved state - either because it
 * was auto-approved on first sync (no moderation required, or `minRating`
 * threshold met) or because an admin clicked the approve button.
 *
 * This is the canonical "review is now public" signal. It fires exactly once
 * per review: re-syncing an already-approved review does NOT re-fire it.
 * That's the right semantics for triggers like loyalty points (you don't
 * want to award a user twice for the same review just because the cron job
 * picked it up again).
 *
 *  - `$auto` is true when approval happened automatically as part of a sync,
 *    false when it came from a manual CP action. Useful for triggers that
 *    care to distinguish (e.g. "only notify the team about manual approvals").
 */
class ReviewApprovalEvent extends Event
{
    public function __construct(
        public Review $review,
        public Source $source,
        public bool $auto,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
