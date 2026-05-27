<?php

namespace bymayo\vouch\widgets;

use bymayo\vouch\Vouch;
use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;

/**
 * Dashboard widget listing configured pull-based sources with their last-synced
 * timestamp and a one-click Sync button. Manual sources are excluded since
 * there's nothing to pull.
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
        $allSources = $vouch->sources->getAllSources();

        // Manual sources have nothing to pull, so they don't belong in a
        // sync-focused widget.
        $sources = array_values(array_filter(
            $allSources,
            fn($s) => $s->providerHandle !== 'manual',
        ));

        if ($this->sourceId) {
            $sources = array_values(array_filter(
                $sources,
                fn($s) => $s->id === $this->sourceId,
            ));
        }

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/sources/body', [
            'sources' => $sources,
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
            if ($source->providerHandle === 'manual') {
                continue;
            }
            $options[] = ['label' => $source->name, 'value' => $source->id];
        }

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/sources/settings', [
            'widget' => $this,
            'sourceOptions' => $options,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }
}
