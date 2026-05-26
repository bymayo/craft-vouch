<?php

namespace bymayo\vouch\elements\actions;

use bymayo\vouch\elements\Review;
use bymayo\vouch\Vouch;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Bulk-approve selected reviews from the reviews element index. Calls
 * `Reviews::approve()` per element so the `EVENT_AFTER_APPROVE_REVIEW`
 * event fires exactly the same way it would for a single-row approval.
 */
class ApproveReviews extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('vouch', 'Approve');
    }

    public function getTriggerLabel(): string
    {
        return Craft::t('vouch', 'Approve');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $reviewsService = Vouch::getInstance()->reviews;
        $count = 0;
        $failed = 0;

        /** @var Review[] $reviews */
        $reviews = $query->status(null)->all();
        foreach ($reviews as $review) {
            if ($review->approved) {
                continue;
            }
            if ($reviewsService->approve($review)) {
                $count++;
            } else {
                $failed++;
            }
        }

        if ($failed > 0 && $count === 0) {
            $this->setMessage(Craft::t('vouch', 'Couldn’t approve the selected reviews.'));
            return false;
        }

        $this->setMessage(Craft::t(
            'vouch',
            '{count, number} {count, plural, one{review} other{reviews}} approved.',
            ['count' => $count],
        ));
        return true;
    }
}
