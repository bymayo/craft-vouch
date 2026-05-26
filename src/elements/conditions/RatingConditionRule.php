<?php

namespace bymayo\vouch\elements\conditions;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class RatingConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('vouch', 'Rating');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['rating'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @phpstan-ignore-next-line - `rating()` is defined on ReviewQuery */
        $query->rating($this->paramValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @phpstan-ignore-next-line - `rating` is defined on Review */
        return $this->matchValue($element->rating);
    }
}
