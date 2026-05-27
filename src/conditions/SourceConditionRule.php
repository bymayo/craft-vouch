<?php

namespace bymayo\vouch\conditions;

use bymayo\points\conditions\BaseConditionRule;
use bymayo\points\conditions\RuleEvaluationContext;
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\Vouch;

/**
 * Points condition - gate a Review-approved rule on which Vouch source the
 * review came from. Useful when you want different point awards per provider
 * (e.g. 10 points for a Google review, 5 for a Trustpilot one).
 */
class SourceConditionRule extends BaseConditionRule
{
    public function handle(): string
    {
        return 'vouch.source';
    }

    public function label(): string
    {
        return 'Is from source';
    }

    public function group(): string
    {
        return 'Reviews';
    }

    public function appliesToSubjects(): ?array
    {
        return ['review'];
    }

    public function evaluate(array $config, RuleEvaluationContext $ctx): bool
    {
        $ids = array_map('intval', $config['ids'] ?? []);
        if (empty($ids)) {
            return true;
        }

        $event = $ctx->triggerEvent;
        if (!$event instanceof ReviewApprovalEvent) {
            return false;
        }

        return in_array((int) $event->source->id, $ids, true);
    }

    public function renderConfigUi(int $index, array $config): string
    {
        $options = [];
        foreach (Vouch::getInstance()->sources->getAllSources() as $source) {
            $options[] = ['label' => $source->name, 'value' => (string) $source->id];
        }

        return $this->renderFormTemplate('_includes/forms/checkboxSelect.twig', [
            'name' => "conditions[{$index}][ids]",
            'options' => $options,
            'values' => $config['ids'] ?? [],
            'showAllOption' => false,
        ]);
    }
}
