<?php

namespace bymayo\vouch;

use bymayo\vouch\elements\Review;
use bymayo\vouch\gql\types\ReviewType;
use bymayo\vouch\models\Settings;
use bymayo\vouch\services\ProviderRegistry;
use bymayo\vouch\services\Reviews;
use bymayo\vouch\services\Sources;
use bymayo\vouch\services\Sync;
use bymayo\vouch\variables\VouchVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\db\Query;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use GraphQL\Type\Definition\Type;
use yii\base\Event;

/**
 * Vouch plugin
 *
 * @method static Vouch getInstance()
 * @method Settings getSettings()
 * @property-read Sources $sources
 * @property-read Reviews $reviews
 * @property-read ProviderRegistry $providers
 * @property-read Sync $sync
 * @author ByMayo <jason@bymayo.co.uk>
 * @copyright ByMayo
 * @license https://craftcms.github.io/license/ Craft License
 */
class Vouch extends Plugin
{
    public string $schemaVersion = '1.0.1';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'sources' => Sources::class,
                'reviews' => Reviews::class,
                'providers' => ProviderRegistry::class,
                'sync' => Sync::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Route console traffic at our own console controllers so
        // `craft vouch/sync/*` works.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'bymayo\\vouch\\console\\controllers';
        }

        $this->attachEventHandlers();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->pluginName;

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission('vouch-viewReviews')) {
            $subnav['reviews'] = ['label' => Craft::t('vouch', 'Reviews'), 'url' => 'vouch/reviews'];
        }
        if ($user->checkPermission('vouch-viewSources')) {
            $subnav['sources'] = ['label' => Craft::t('vouch', 'Sources'), 'url' => 'vouch/sources'];
        }
        // Settings intentionally omitted from this subnav - managed via
        // Settings → Plugins → Vouch (or project.yaml / config/vouch.php).

        if (empty($subnav)) {
            return null;
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function cpNavIconPath(): ?string
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'icon-outline.svg';
        return file_exists($path) ? $path : parent::cpNavIconPath();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Settings live in Project Config (and therefore project.yaml). Craft's
     * default `setSettings()` writes the project-config values into the
     * model; we then overlay anything from `config/vouch.php` so per-env
     * overrides win.
     */
    public function setSettings(array $settings): void
    {
        parent::setSettings($settings);

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('vouch');
        if (!empty($fileConfig)) {
            $this->getSettings()?->setAttributes($fileConfig, false);
        }
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('vouch/settings')
        );
    }

    /**
     * Bolt a "Rating" column + sidebar summary onto Entries (and Commerce
     * Products if Commerce is installed). The data is per-element and pulls
     * from approved reviews whose `relatedElementId` matches the element.
     */
    private function attachElementColumns(): void
    {
        $this->wireRatingDisplay(Entry::class);

        // Commerce Products get the same treatment, gated on the plugin
        // being installed AND enabled. Class existence check protects against
        // an installed-but-bad Commerce package.
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            $productClass = \craft\commerce\elements\Product::class;
            if (class_exists($productClass)) {
                $this->wireRatingDisplay($productClass);
            }
        }

        $this->wireUserReviewCount();
    }

    /**
     * Opt-in "Reviews" column on the Users element index showing how many
     * approved reviews the user has authored (matched via `reviewerUserId`).
     * Links to the reviews index pre-filtered to that user.
     */
    private function wireUserReviewCount(): void
    {
        Event::on(
            \craft\elements\User::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['vouchReviewCount'] = [
                    'label' => Craft::t('vouch', 'Reviews'),
                ];
            },
        );

        Event::on(
            \craft\elements\User::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'vouchReviewCount') return;
                /** @var Element $element */
                $element = $event->sender;
                if (!$element->id) {
                    $event->html = '';
                    return;
                }
                $count = self::getInstance()->reviews->reviewCountForUser((int) $element->id);
                $event->html = $count > 0
                    ? (string) $count
                    : '<span class="light">—</span>';
            },
        );
    }

    /**
     * @param class-string<Element> $elementClass
     */
    private function wireRatingDisplay(string $elementClass): void
    {
        Event::on(
            $elementClass,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['vouchRating'] = [
                    'label' => Craft::t('vouch', 'Rating'),
                ];
            },
        );

        Event::on(
            $elementClass,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'vouchRating') return;
                /** @var Element $element */
                $element = $event->sender;
                if (!$element->id) {
                    $event->html = '';
                    return;
                }
                $summary = self::getInstance()->reviews->ratingSummaryForElement($element->id);
                if ($summary === null) {
                    $event->html = '<span class="light">—</span>';
                    return;
                }
                $event->html = sprintf(
                    '%s ★ <span class="light">(%d %s)</span>',
                    Html::encode(number_format($summary['avg'], 1)),
                    $summary['count'],
                    Html::encode(Craft::t('vouch', $summary['count'] === 1 ? 'review' : 'reviews')),
                );
            },
        );

        // Append after the standard sidebar content so the Rating block
        // sits beneath the Status / Notes panes as a peer panel.
        Event::on(
            $elementClass,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                if (!$element->id) return;

                $reviews = self::getInstance()->reviews;
                $avg = $reviews->averageRatingForElement($element->id);
                if ($avg === null) return;

                $breakdown = $reviews->ratingBreakdownForElement($element->id);
                $event->html .= self::buildRatingPaneHtml($avg, $breakdown, (int) $element->id, get_class($element));
            },
        );
    }

    /**
     * Sidebar pane using `<fieldset><legend class="h6">…</legend><div class="meta">…</div></fieldset>` -
     * Craft's own Status-pane structure - with `.field` rows so spacing is
     * driven entirely by Craft's CSS.
     *
     * @param array<int, array<string, mixed>> $breakdown
     */
    private static function buildRatingPaneHtml(float $avg, array $breakdown, int $elementId, string $elementType): string
    {
        $total = array_sum(array_map(fn($r) => (int) ($r['count'] ?? 0), $breakdown));

        // Rating row - stars + numeric value.
        $rows = sprintf(
            '<div class="field"><div class="heading"><label>%s</label></div>'
            . '<div class="input">%s</div></div>',
            Html::encode(Craft::t('vouch', 'Rating')),
            self::renderStarRating($avg),
        );

        // Reviews row - link to the reviews index with a pre-applied
        // "Related element" condition filter pointing at this element.
        $reviewsUrl = self::buildReviewsLinkForElement($elementId, $elementType);
        $rows .= sprintf(
            '<div class="field"><div class="heading"><label>%s</label></div>'
            . '<div class="input"><a href="%s">%d %s</a></div></div>',
            Html::encode(Craft::t('vouch', 'Reviews')),
            Html::encode($reviewsUrl),
            $total,
            Html::encode(Craft::t('vouch', $total === 1 ? 'review' : 'reviews')),
        );

        $heading = self::getInstance()->getSettings()->pluginName ?: Craft::t('vouch', 'Rating');

        return sprintf(
            '<fieldset>'
            . '<legend class="h6">%s</legend>'
            . '<div class="meta">%s</div>'
            . '</fieldset>',
            Html::encode($heading),
            $rows,
        );
    }

    /**
     * Builds a deep-link to the reviews index with a pre-applied
     * "Related element" condition pointing at `$elementId`.
     *
     * Craft serialises element-index filters into a `filters` query param
     * that is itself a URL-encoded query string (so the outer URL ends up
     * double-encoded). This mirrors the exact structure Craft generates
     * when a user applies the same filter via the UI.
     */
    private static function buildReviewsLinkForElement(int $elementId, string $elementType): string
    {
        $ruleClass = \bymayo\vouch\elements\conditions\RelatedElementConditionRule::class;
        $conditionClass = \bymayo\vouch\elements\conditions\ReviewCondition::class;
        $reviewClass = \bymayo\vouch\elements\Review::class;
        $ruleUid = \craft\helpers\StringHelper::UUID();

        $ruleType = json_encode([
            'class' => $ruleClass,
            'uid' => $ruleUid,
            'elementIds' => [],
            'elementType' => $elementType,
        ], JSON_UNESCAPED_SLASHES);

        $configJson = json_encode([
            'elementType' => $reviewClass,
            'fieldContext' => 'global',
        ], JSON_UNESCAPED_SLASHES);

        $filtersInner = http_build_query([
            'condition' => [
                'class' => $conditionClass,
                'config' => $configJson,
                'conditionRules' => [
                    1 => [
                        'uid' => $ruleUid,
                        'class' => $ruleClass,
                        'type' => $ruleType,
                        'operator' => '',
                        'elementType' => $elementType,
                        'elementIds' => (string) $elementId,
                    ],
                ],
                'new-rule-type' => '',
            ],
        ]);

        return \craft\helpers\UrlHelper::cpUrl('vouch/reviews', [
            'source' => '*',
            'filters' => $filtersInner,
        ]);
    }

    /**
     * Renders a rating as 5 inline SVG stars + the numeric value in parens.
     * Inline SVG avoids the font-glyph fallback issue that broke the half-
     * star Unicode character. Half-star uses `clip-path: inset(...)` so we
     * don't need unique `<defs>` ids per render.
     */
    private static function renderStarRating(float $rating): string
    {
        $full = max(0, (int) floor($rating));
        $half = ($rating - $full) >= 0.5 ? 1 : 0;
        $empty = max(0, 5 - $full - $half);

        $stars = str_repeat(self::starSvg('full'), $full)
            . str_repeat(self::starSvg('half'), $half)
            . str_repeat(self::starSvg('empty'), $empty);

        return sprintf(
            '%s <span class="light">(%s)</span>',
            $stars,
            Html::encode(number_format($rating, 1)),
        );
    }

    /**
     * @param 'full'|'half'|'empty' $variant
     */
    private static function starSvg(string $variant): string
    {
        $path = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';
        $open = '<svg viewBox="0 0 24 24" width="14" height="14" style="display:inline-block;vertical-align:-2px;">';

        return match ($variant) {
            'full' => $open . '<path d="' . $path . '" fill="currentColor"/></svg>',
            'half' => $open
                . '<path d="' . $path . '" fill="currentColor" opacity="0.25"/>'
                . '<path d="' . $path . '" fill="currentColor" style="clip-path: inset(0 50% 0 0);"/>'
                . '</svg>',
            'empty' => $open . '<path d="' . $path . '" fill="currentColor" opacity="0.25"/></svg>',
        };
    }

    private function registerGraphQl(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            function(RegisterGqlTypesEvent $event) {
                $event->types[] = ReviewType::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries['vouchReviews'] = [
                    'type' => Type::listOf(ReviewType::getType()),
                    'args' => [
                        'sourceId' => Type::int(),
                        'rating' => Type::float(),
                        'minRating' => Type::float(),
                        'approved' => Type::boolean(),
                        'reviewerUserId' => Type::int(),
                        'relatedElementId' => Type::int(),
                        'limit' => Type::int(),
                        'offset' => Type::int(),
                    ],
                    'description' => 'Reviews pulled in by Vouch.',
                    'resolve' => function($source, array $args) {
                        $query = \bymayo\vouch\elements\Review::find()
                            ->orderBy(['vouch_reviews.reviewedAt' => SORT_DESC]);

                        // Default approved=true on the public GQL surface so
                        // pending-moderation reviews don't leak into front-end
                        // queries unless the caller explicitly opts in.
                        $query->approved($args['approved'] ?? true);

                        if (isset($args['sourceId'])) {
                            $query->sourceId((int)$args['sourceId']);
                        }
                        if (isset($args['rating'])) {
                            $query->rating((float)$args['rating']);
                        }
                        if (isset($args['minRating'])) {
                            $query->rating('>= ' . (float)$args['minRating']);
                        }
                        if (isset($args['reviewerUserId'])) {
                            $query->reviewerUserId((int)$args['reviewerUserId']);
                        }
                        if (isset($args['relatedElementId'])) {
                            $query->relatedElementId((int)$args['relatedElementId']);
                        }
                        if (isset($args['limit'])) {
                            $query->limit((int)$args['limit']);
                        }
                        if (isset($args['offset'])) {
                            $query->offset((int)$args['offset']);
                        }
                        return $query->all();
                    },
                ];

                $event->queries['vouchReview'] = [
                    'type' => ReviewType::getType(),
                    'args' => ['id' => Type::nonNull(Type::int())],
                    'resolve' => fn($source, array $args) =>
                        \bymayo\vouch\elements\Review::find()
                            ->id((int)$args['id'])
                            ->status(null)
                            ->one(),
                    'description' => 'A single Vouch review by id.',
                ];
            }
        );
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Review::class;
            }
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = \bymayo\vouch\widgets\PendingApprovalWidget::class;
                $event->types[] = \bymayo\vouch\widgets\LatestReviewsWidget::class;
                $event->types[] = \bymayo\vouch\widgets\TopReviewedElementsWidget::class;
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['vouch'] = 'vouch/reviews/index';

                $event->rules['vouch/reviews'] = 'vouch/reviews/index';
                $event->rules['vouch/reviews/new'] = 'vouch/reviews/edit';
                $event->rules['vouch/reviews/<reviewId:\d+>'] = 'vouch/reviews/edit';
                $event->rules['POST vouch/reviews/save'] = 'vouch/reviews/save';
                $event->rules['POST vouch/reviews/delete'] = 'vouch/reviews/delete';
                $event->rules['POST vouch/reviews/approve'] = 'vouch/reviews/approve';

                $event->rules['vouch/sources'] = 'vouch/sources/index';
                $event->rules['vouch/sources/table-data'] = 'vouch/sources/table-data';
                $event->rules['vouch/sources/new'] = 'vouch/sources/edit';
                $event->rules['vouch/sources/new/<provider:[a-z0-9\-]+>'] = 'vouch/sources/edit';
                $event->rules['vouch/sources/<sourceId:\d+>'] = 'vouch/sources/edit';
                $event->rules['POST vouch/sources/save'] = 'vouch/sources/save';
                $event->rules['POST vouch/sources/delete'] = 'vouch/sources/delete';
                $event->rules['POST vouch/sources/test'] = 'vouch/sources/test';
                $event->rules['POST vouch/sources/sync'] = 'vouch/sources/sync';
                $event->rules['POST vouch/sources/find-google-place'] = 'vouch/sources/find-google-place';
                $event->rules['vouch/sources/connect-google'] = 'vouch/sources/connect-google';
                $event->rules['vouch/sources/google-oauth-callback'] = 'vouch/sources/google-oauth-callback';

                $event->rules['vouch/settings'] = 'vouch/settings/edit';
                $event->rules['POST vouch/settings'] = 'vouch/settings/save';

                // Vouch screen on the user edit page (and the user's own
                // /myaccount). The controller renders an embedded reviews
                // index filtered to the chosen user.
                $event->rules['users/<userId:\d+>/vouch-reviews'] = 'vouch/users/index';
                $event->rules['myaccount/vouch-reviews'] = 'vouch/users/index';
            }
        );

        // Register the "Reviews" screen on the user edit page (Commerce-style).
        // The label uses the configured plugin name so a rename in Settings
        // flows through here too.
        Event::on(
            \craft\controllers\UsersController::class,
            \craft\controllers\UsersController::EVENT_DEFINE_EDIT_SCREENS,
            function(\craft\events\DefineEditUserScreensEvent $event) {
                if (!Craft::$app->getUser()->checkPermission('vouch-viewReviews')) {
                    return;
                }
                $event->screens[\bymayo\vouch\controllers\UsersController::SCREEN_REVIEWS] = [
                    'label' => self::getInstance()->getSettings()->pluginName,
                ];
            },
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('vouch', VouchVariable::class);
            }
        );

        $this->registerGraphQl();
        $this->attachElementColumns();

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $pluginName = self::getInstance()->getSettings()->pluginName;
                $event->permissions[] = [
                    'heading' => $pluginName,
                    'permissions' => [
                        'vouch-viewReviews' => [
                            'label' => Craft::t('vouch', 'View reviews'),
                            'nested' => [
                                'vouch-createReviews' => ['label' => Craft::t('vouch', 'Create reviews')],
                                'vouch-editReviews' => ['label' => Craft::t('vouch', 'Edit reviews')],
                                'vouch-deleteReviews' => ['label' => Craft::t('vouch', 'Delete reviews')],
                            ],
                        ],
                        'vouch-approveReviews' => [
                            'label' => Craft::t('vouch', 'Approve pending reviews'),
                        ],
                        'vouch-viewSources' => [
                            'label' => Craft::t('vouch', 'View sources'),
                            'nested' => [
                                'vouch-createSources' => ['label' => Craft::t('vouch', 'Create sources')],
                                'vouch-editSources' => ['label' => Craft::t('vouch', 'Edit sources')],
                                'vouch-deleteSources' => ['label' => Craft::t('vouch', 'Delete sources')],
                                'vouch-syncSources' => ['label' => Craft::t('vouch', 'Trigger sync')],
                            ],
                        ],
                        'vouch-viewWidgets' => [
                            'label' => Craft::t('vouch', 'Use dashboard widgets'),
                        ],
                        'vouch-manageSettings' => [
                            'label' => Craft::t('vouch', 'Manage settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
