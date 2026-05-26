<?php

namespace bymayo\vouch\elements\conditions;

use bymayo\vouch\Vouch;
use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class SourceConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('vouch', 'Source');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['sourceId'];
    }

    protected function options(): array
    {
        $options = [];
        foreach (Vouch::getInstance()->sources->getAllSources() as $source) {
            $options[] = ['value' => (string) $source->id, 'label' => $source->name];
        }
        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = array_map('intval', (array) $this->getValues());
        if (!empty($values)) {
            /** @phpstan-ignore-next-line - `sourceId()` is defined on ReviewQuery */
            $query->sourceId($values);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $values = (array) $this->getValues();
        if (empty($values)) {
            return true;
        }
        /** @phpstan-ignore-next-line - `sourceId` is defined on Review */
        return in_array((string) $element->sourceId, $values, true);
    }
}
