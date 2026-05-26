<?php

namespace bymayo\vouch\elements\conditions;

use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * Filter reviews by the element they're related to (entry, product, etc.).
 * The element-type dropdown lets the user pick any element type Craft has
 * a relational field for - same UX as Craft's built-in "Related To" rule.
 */
class RelatedElementConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    /** @var class-string<ElementInterface> */
    public string $elementType = Entry::class;

    public function getLabel(): string
    {
        return Craft::t('vouch', 'Related element');
    }

    protected function elementType(): string
    {
        return $this->elementType;
    }

    protected function allowMultiple(): bool
    {
        return false;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['relatedElementId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $ids = $this->getElementIds();
        if (!empty($ids)) {
            /** @phpstan-ignore-next-line - `relatedElementId()` is defined on ReviewQuery */
            $query->relatedElementId($ids[0]);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $ids = $this->getElementIds();
        if (empty($ids)) {
            return true;
        }
        /** @phpstan-ignore-next-line - `relatedElementId` is defined on Review */
        return (int) $element->relatedElementId === (int) $ids[0];
    }

    protected function inputHtml(): string
    {
        $id = 'element-type';
        return Html::hiddenLabel($this->getLabel(), $id) .
            Html::tag('div',
                Cp::selectHtml([
                    'id' => $id,
                    'name' => 'elementType',
                    'options' => $this->elementTypeOptions(),
                    'value' => $this->elementType,
                    'inputAttributes' => [
                        'hx' => [
                            'post' => UrlHelper::actionUrl('conditions/render'),
                        ],
                    ],
                ]) .
                parent::inputHtml(),
                ['class' => ['flex', 'flex-start']],
            );
    }

    private function elementTypeOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getFields()->getRelationalFieldTypes() as $field) {
            /** @var class-string<BaseRelationField> $field */
            $elementType = $field::elementType();
            $options[] = [
                'value' => $elementType,
                'label' => $elementType::displayName(),
            ];
        }
        return $options;
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['elementType'], 'safe'],
        ]);
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'elementType' => $this->elementType,
        ]);
    }
}
