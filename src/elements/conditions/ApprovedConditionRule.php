<?php

namespace bymayo\vouch\elements\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ApprovedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('vouch', 'Approved');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['approved'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @phpstan-ignore-next-line — `approved()` is defined on ReviewQuery */
        $query->approved($this->value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @phpstan-ignore-next-line — `approved` is defined on Review */
        return (bool) $element->approved === $this->value;
    }
}
