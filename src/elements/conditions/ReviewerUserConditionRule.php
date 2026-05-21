<?php

namespace bymayo\vouch\elements\conditions;

use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;

class ReviewerUserConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('vouch', 'Reviewer user');
    }

    protected function elementType(): string
    {
        return User::class;
    }

    protected function allowMultiple(): bool
    {
        return false;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['authorUserId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $ids = $this->getElementIds();
        if (!empty($ids)) {
            /** @phpstan-ignore-next-line — `authorUserId()` is defined on ReviewQuery */
            $query->authorUserId($ids[0]);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $ids = $this->getElementIds();
        if (empty($ids)) {
            return true;
        }
        /** @phpstan-ignore-next-line — `authorUserId` is defined on Review */
        return (int) $element->authorUserId === (int) $ids[0];
    }
}
