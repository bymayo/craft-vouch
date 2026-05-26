<?php

namespace bymayo\vouch\widgets;

use bymayo\vouch\Vouch;
use Craft;
use craft\base\Widget;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Dashboard widget that ranks elements (entries, products, etc.) by how
 * many approved reviews they've received. The element type - and, for
 * entries, the section - are user-configurable so each widget instance
 * can pin a specific slice of the catalogue.
 */
class TopReviewedElementsWidget extends Widget
{
    public string $elementType = Entry::class;

    /** Entry section id; only used when `elementType` is Entry. Empty = any section. */
    public ?int $sectionId = null;

    /** 'count' = most reviews; 'average' = highest rating. */
    public string $sort = 'count';

    public int $limit = 5;

    public static function displayName(): string
    {
        $pluginName = Vouch::getInstance()->getSettings()->pluginName;
        return Craft::t('vouch', '{plugin} - Top Reviewed Elements', ['plugin' => $pluginName]);
    }

    public static function icon(): ?string
    {
        $path = Vouch::getInstance()->getBasePath() . '/icon-outline.svg';
        return file_exists($path) ? $path : null;
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('vouch-viewWidgets');
    }

    public function getTitle(): ?string
    {
        if ($this->elementType === Entry::class && $this->sectionId) {
            $section = Craft::$app->getEntries()->getSectionById($this->sectionId);
            if ($section) {
                return Craft::t('vouch', 'Top Reviewed {section}', ['section' => $section->name]);
            }
        }

        $label = self::elementTypeOptions()[$this->elementType] ?? null;
        if ($label) {
            return Craft::t('vouch', 'Top Reviewed {type}', ['type' => $label]);
        }

        return Craft::t('vouch', 'Top Reviewed Elements');
    }

    public function getBodyHtml(): ?string
    {
        $rows = Vouch::getInstance()->reviews->topReviewedElements(
            elementType: $this->elementType ?: null,
            sectionId: ($this->elementType === Entry::class && $this->sectionId) ? $this->sectionId : null,
            sort: $this->sort,
            limit: $this->limit,
        );

        // Hydrate one element per row so the template can render labels +
        // chip HTML. Single batched fetch beats N+1 element lookups.
        $ids = array_column($rows, 'elementId');
        $elementsById = [];
        if (!empty($ids) && $this->elementType) {
            /** @var class-string<\craft\base\Element> $cls */
            $cls = $this->elementType;
            foreach ($cls::find()->id($ids)->status(null)->all() as $el) {
                $elementsById[$el->id] = $el;
            }
        }

        // Singular label for the first column header ("Entry", "Product", ...).
        $columnLabel = ($this->elementType && class_exists($this->elementType))
            ? $this->elementType::displayName()
            : Craft::t('vouch', 'Element');

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/top-reviewed-elements/body', [
            'rows' => $rows,
            'elementsById' => $elementsById,
            'columnLabel' => $columnLabel,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    public function getSettingsHtml(): ?string
    {
        $elementTypeOptions = [];
        foreach (self::elementTypeOptions() as $value => $label) {
            $elementTypeOptions[] = ['value' => $value, 'label' => $label];
        }

        $sectionOptions = [['value' => '', 'label' => Craft::t('vouch', 'Any section')]];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sectionOptions[] = ['value' => (string) $section->id, 'label' => $section->name];
        }

        return Craft::$app->getView()->renderTemplate('vouch/_widgets/top-reviewed-elements/settings', [
            'widget' => $this,
            'elementTypeOptions' => $elementTypeOptions,
            'sectionOptions' => $sectionOptions,
            'sortOptions' => [
                ['value' => 'count', 'label' => Craft::t('vouch', 'Most reviews')],
                ['value' => 'average', 'label' => Craft::t('vouch', 'Highest rating')],
            ],
            'entryClass' => Entry::class,
        ], \craft\web\View::TEMPLATE_MODE_CP);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 50];
        $rules[] = [['sectionId'], 'integer'];
        $rules[] = [['elementType'], 'string'];
        $rules[] = [['sort'], 'in', 'range' => ['count', 'average']];
        return $rules;
    }

    /**
     * @return array<class-string, string>
     */
    private static function elementTypeOptions(): array
    {
        $options = [
            Entry::class => Craft::t('vouch', 'Entries'),
            Asset::class => Craft::t('vouch', 'Assets'),
            Category::class => Craft::t('vouch', 'Categories'),
            User::class => Craft::t('vouch', 'Users'),
        ];
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce')
            && class_exists(\craft\commerce\elements\Product::class)) {
            $options[\craft\commerce\elements\Product::class] = Craft::t('vouch', 'Products');
        }
        return $options;
    }
}
