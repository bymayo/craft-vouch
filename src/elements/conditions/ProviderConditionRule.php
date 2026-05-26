<?php

namespace bymayo\vouch\elements\conditions;

use bymayo\vouch\Vouch;
use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Filter reviews by their source's provider handle (google, trustpilot etc.).
 * Useful when you want "all Google reviews" across multiple Google sources
 * (e.g. one per location), without picking individual sources.
 */
class ProviderConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('vouch', 'Provider');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function options(): array
    {
        $options = [];
        foreach (Vouch::getInstance()->providers->all() as $handle => $connector) {
            $options[] = ['value' => $handle, 'label' => $connector::displayName()];
        }
        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $providers = (array) $this->getValues();
        if (empty($providers)) {
            return;
        }
        // Resolve source ids for the selected providers and AND-in a sourceId
        // criterion. Empty array short-circuits with `[0]` so nothing matches.
        $sourceIds = (new Query())
            ->select('id')
            ->from('{{%vouch_sources}}')
            ->where(['providerHandle' => $providers])
            ->column();
        /** @phpstan-ignore-next-line - `sourceId()` is defined on ReviewQuery */
        $query->sourceId($sourceIds ?: [0]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $providers = (array) $this->getValues();
        if (empty($providers)) {
            return true;
        }
        /** @phpstan-ignore-next-line - `getSource()` is defined on Review */
        $source = $element->getSource();
        return $source !== null && in_array($source->providerHandle, $providers, true);
    }
}
