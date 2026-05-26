<?php

namespace bymayo\vouch\widgets;

use bymayo\vouch\elements\Review;
use bymayo\vouch\Vouch;
use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;

/**
 * Dashboard widget surfacing reviews awaiting approval - same query the
 * "Pending Approval" source on the reviews index uses, so counts match.
 */
class PendingApprovalWidget extends Widget
{
    public int $limit = 5;

    public static function displayName(): string
    {
        $pluginName = Vouch::getInstance()->getSettings()->pluginName;
        return Craft::t('vouch', '{plugin} - Reviews Pending Approval', ['plugin' => $pluginName]);
    }

    public static function icon(): ?string
    {
        $path = Vouch::getInstance()->getBasePath() . '/icon-outline.svg';
        return file_exists($path) ? $path : null;
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('vouch-viewWidgets');
    }

    public function getTitle(): ?string
    {
        return Craft::t('vouch', 'Reviews Pending Approval');
    }

    public function getSubtitle(): ?string
    {
        $count = Review::find()->approved(false)->status(null)->count();
        return Craft::t('vouch', '{count, number} {count, plural, one{review} other{reviews}}', ['count' => $count]);
    }

    public function getBodyHtml(): ?string
    {
        $reviews = Review::find()
            ->approved(false)
            ->status(null)
            ->orderBy(['vouch_reviews.reviewedAt' => SORT_DESC])
            ->limit($this->limit)
            ->all();

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/pending-approval/body', [
            'reviews' => $reviews,
            'indexUrl' => UrlHelper::cpUrl('vouch/reviews', ['source' => 'pending']),
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('vouch/_widgets/pending-approval/settings', [
            'widget' => $this,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 50];
        return $rules;
    }
}
