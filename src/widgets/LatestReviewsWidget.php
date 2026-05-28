<?php

namespace bymayo\vouch\widgets;

use bymayo\vouch\elements\Review;
use bymayo\vouch\Vouch;
use bymayo\vouch\web\assets\vouch\VouchAsset;
use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;

/**
 * Dashboard widget showing the most recently received approved reviews.
 * Optionally scoped to a single source so a sub-team can pin "their"
 * provider's feed on the dashboard.
 */
class LatestReviewsWidget extends Widget
{
    public int $limit = 5;

    /** Source id to filter by; null means "any source". */
    public ?int $sourceId = null;

    public static function displayName(): string
    {
        $pluginName = Vouch::getInstance()->getSettings()->pluginName;
        return Craft::t('vouch', '{plugin} - Latest Reviews', ['plugin' => $pluginName]);
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
        if ($this->sourceId) {
            $source = Vouch::getInstance()->sources->getSourceById($this->sourceId);
            if ($source) {
                return Craft::t('vouch', 'Latest Reviews - {source}', ['source' => $source->name]);
            }
        }
        return Craft::t('vouch', 'Latest Reviews');
    }

    public function getBodyHtml(): ?string
    {
        $query = Review::find()
            ->approved(true)
            ->orderBy(['vouch_reviews.reviewedAt' => SORT_DESC])
            ->limit($this->limit);

        if ($this->sourceId) {
            $query->sourceId($this->sourceId);
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(VouchAsset::class);

        return $view->renderTemplate('vouch/_widgets/latest-reviews/body', [
            'reviews' => $query->all(),
            'indexUrl' => UrlHelper::cpUrl('vouch/reviews'),
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    public function getSettingsHtml(): ?string
    {
        $sources = Vouch::getInstance()->sources->getAllSources();
        $sourceOptions = [['value' => '', 'label' => Craft::t('vouch', 'Any source')]];
        foreach ($sources as $source) {
            $sourceOptions[] = ['value' => (string) $source->id, 'label' => $source->name];
        }

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/latest-reviews/settings', [
            'widget' => $this,
            'sourceOptions' => $sourceOptions,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 50];
        $rules[] = [['sourceId'], 'integer'];
        return $rules;
    }
}
