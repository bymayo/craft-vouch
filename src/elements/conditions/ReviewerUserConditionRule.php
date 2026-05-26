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
        return ['reviewerUserId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $ids = $this->getElementIds();
        if (!empty($ids)) {
            /** @phpstan-ignore-next-line - `reviewerUserId()` is defined on ReviewQuery */
            $query->reviewerUserId($ids[0]);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $ids = $this->getElementIds();
        if (empty($ids)) {
            return true;
        }
        /** @phpstan-ignore-next-line - `reviewerUserId` is defined on Review */
        return (int) $element->reviewerUserId === (int) $ids[0];
    }
}
