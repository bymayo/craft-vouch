<?php

namespace bymayo\vouch\triggers;

use bymayo\points\triggers\BaseTrigger;
use bymayo\points\triggers\TriggerContext;
use bymayo\vouch\conditions\MinRatingConditionRule;
use bymayo\vouch\conditions\ReviewLengthConditionRule;
use bymayo\vouch\conditions\SourceConditionRule;
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\services\Reviews;
use yii\base\Event;

/**
 * Points trigger - fires when a Vouch review transitions to approved.
 *
 * Hooks into `Reviews::EVENT_AFTER_APPROVE_REVIEW`, which fires exactly
 * once per review (whether the approval was automatic on sync or manual
 * from the CP) so users can't be double-awarded for the same review.
 *
 * Points are only awarded when the review is attributed to a Craft user
 * (`reviewerUserId` set) - anonymous reviews are skipped.
 */
class ReviewApprovedTrigger extends BaseTrigger
{
    public function handle(): string
    {
        return 'vouch.review.approved';
    }

    public function label(): string
    {
        return 'Review approved';
    }

    public function group(): string
    {
        return 'Reviews';
    }

    public function subject(): string
    {
        return 'review';
    }

    public function subjectLabel(): string
    {
        return 'Review';
    }

    public function actionLabel(): string
    {
        return 'Approved';
    }

    public function events(): array
    {
        return [[Reviews::class, Reviews::EVENT_AFTER_APPROVE_REVIEW]];
    }

    public function handleEvent(Event $event): ?TriggerContext
    {
        /** @var ReviewApprovalEvent $event */
        $userId = $event->review->reviewerUserId ?? null;
        if (!$userId) {
            return null;
        }

        return new TriggerContext(userId: $userId);
    }

    public function conditions(): array
    {
        return [
            new SourceConditionRule(),
            new MinRatingConditionRule(),
            new ReviewLengthConditionRule(),
        ];
    }
}
