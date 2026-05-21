<?php

namespace bymayo\vouch\elements\conditions;

use craft\elements\conditions\ElementCondition;

/**
 * Condition builder for `Review` elements. Adds Vouch-specific rule types
 * (rating, approved, source, provider, related element, reviewer user) to
 * the standard set Craft provides (id, slug, date created/updated, etc.).
 */
class ReviewCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            ApprovedConditionRule::class,
            RatingConditionRule::class,
            SourceConditionRule::class,
            ProviderConditionRule::class,
            RelatedElementConditionRule::class,
            ReviewerUserConditionRule::class,
        ]);
    }
}
