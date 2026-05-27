<?php

namespace bymayo\vouch\conditions;

use bymayo\points\conditions\BaseConditionRule;
use bymayo\points\conditions\RuleEvaluationContext;
use bymayo\vouch\events\ReviewApprovalEvent;

/**
 * Points condition - only fire the rule when the review body is long
 * enough. Useful for incentivising thoughtful feedback over one-line
 * "great!" reviews.
 */
class ReviewLengthConditionRule extends BaseConditionRule
{
    public function handle(): string
    {
        return 'vouch.reviewLength';
    }

    public function label(): string
    {
        return 'Review length is at least';
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
        $min = (int) ($config['value'] ?? 0);
        if ($min <= 0) {
            return true;
        }

        $event = $ctx->triggerEvent;
        if (!$event instanceof ReviewApprovalEvent) {
            return false;
        }

        $body = (string) ($event->review->review ?? '');
        return mb_strlen(trim($body)) >= $min;
    }

    public function renderConfigUi(int $index, array $config): string
    {
        return $this->renderFormTemplate('_includes/forms/text.twig', [
            'name' => "conditions[{$index}][value]",
            'type' => 'number',
            'placeholder' => '100',
            'value' => $config['value'] ?? '',
            'min' => 1,
        ]) . '<span class="cnd-suffix">' . \Craft::t('vouch', 'characters') . '</span>';
    }
}
