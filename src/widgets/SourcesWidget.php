<?php

namespace bymayo\vouch\widgets;

use bymayo\vouch\Vouch;
use bymayo\vouch\web\assets\vouch\VouchAsset;
use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;

/**
 * Dashboard widget listing configured sources with their last-synced timestamp
 * and a one-click Sync button. Manual sources are included too, but since they
 * have nothing to pull their Sync button and last-synced line are hidden.
 */
class SourcesWidget extends Widget
{
    public ?int $sourceId = null;

    public static function displayName(): string
    {
        $pluginName = Vouch::getInstance()->getSettings()->pluginName;
        return Craft::t('vouch', '{plugin} Sources', ['plugin' => $pluginName]);
    }

    public static function icon(): ?string
    {
        $path = Vouch::getInstance()->getBasePath() . '/icon-outline.svg';
        return file_exists($path) ? $path : null;
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission('vouch-viewWidgets');
    }

    public function getTitle(): ?string
    {
        $pluginName = Vouch::getInstance()->getSettings()->pluginName;
        return Craft::t('vouch', '{plugin} Sources', ['plugin' => $pluginName]);
    }

    public function getBodyHtml(): ?string
    {
        $vouch = Vouch::getInstance();
        $sources = $vouch->sources->getAllSources();

        if ($this->sourceId) {
            $sources = array_values(array_filter(
                $sources,
                fn($s) => $s->id === $this->sourceId,
            ));
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(VouchAsset::class);

        return $view->renderTemplate('vouch/_widgets/sources/body', [
            'sources' => $sources,
            'providers' => $vouch->providers->all(),
            'canSync' => Craft::$app->getUser()->checkPermission('vouch-syncSources'),
            'indexUrl' => UrlHelper::cpUrl('vouch/sources'),
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    public function getSettingsHtml(): ?string
    {
        $allSources = Vouch::getInstance()->sources->getAllSources();

        $options = [
            ['label' => Craft::t('vouch', 'All Sources'), 'value' => ''],
        ];

        foreach ($allSources as $source) {
            $options[] = ['label' => $source->name, 'value' => $source->id];
        }

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/sources/settings', [
            'widget' => $this,
            'sourceOptions' => $options,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }
}
