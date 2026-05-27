<?php

namespace bymayo\vouch\conditions;

use bymayo\points\conditions\BaseConditionRule;
use bymayo\points\conditions\RuleEvaluationContext;
use bymayo\vouch\events\ReviewApprovalEvent;

/**
 * Points condition - only fire the rule when the review's rating meets a
 * minimum. Useful for rewarding positive feedback only (e.g. award points
 * for 4★+ reviews, nothing for lower).
 */
class MinRatingConditionRule extends BaseConditionRule
{
    public function handle(): string
    {
        return 'vouch.minRating';
    }

    public function label(): string
    {
        return 'Rating is at least';
    }

    public function group(): string
    {
        return 'Reviews';
    }

    public function appliesToSubjects(): ?array
    {
        return ['review'];
    }

    public function isInline(): bool
    {
        return true;
    }

    public function evaluate(array $config, RuleEvaluationContext $ctx): bool
    {
        $min = (float) ($config['value'] ?? 0);
        if ($min <= 0) {
            return true;
        }

        $event = $ctx->triggerEvent;
        if (!$event instanceof ReviewApprovalEvent) {
            return false;
        }

        return (float) $event->review->rating >= $min;
    }

    public function renderConfigUi(int $index, array $config): string
    {
        return $this->renderFormTemplate('_includes/forms/text.twig', [
            'name' => "conditions[{$index}][value]",
            'type' => 'number',
            'placeholder' => '4',
            'value' => $config['value'] ?? '',
            'min' => 1,
            'max' => 5,
            'step' => 0.5,
        ]) . '<span class="cnd-suffix">★</span>';
    }
}
