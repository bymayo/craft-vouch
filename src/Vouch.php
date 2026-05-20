<?php

namespace bymayo\vouch;

use bymayo\vouch\connectors\feefo\FeefoConnector;
use bymayo\vouch\connectors\google\GoogleConnector;
use bymayo\vouch\connectors\manual\ManualConnector;
use bymayo\vouch\connectors\reviewsio\ReviewsioConnector;
use bymayo\vouch\connectors\trustpilot\TrustpilotConnector;
use bymayo\vouch\elements\Review;
use bymayo\vouch\events\RegisterProvidersEvent;
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
    public string $schemaVersion = '1.0.0';
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
        // Settings intentionally omitted from this subnav — managed via
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
                $avg = self::getInstance()->reviews->averageRatingForElement($element->id);
                $event->html = $avg !== null
                    ? Html::encode(number_format($avg, 1)) . ' ★'
                    : '<span class="light">—</span>';
            },
        );

        Event::on(
            $elementClass,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                if (!$element->id) return;

                $reviews = self::getInstance()->reviews;
                $avg = $reviews->averageRatingForElement($element->id);
                if ($avg === null) return; // no reviews — nothing to show

                $breakdown = $reviews->ratingBreakdownForElement($element->id);
                $event->html .= self::buildRatingSidebarHtml($avg, $breakdown);
            },
        );
    }

    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    private static function buildRatingSidebarHtml(float $avg, array $breakdown): string
    {
        $total = array_sum(array_map(fn($r) => (int) ($r['count'] ?? 0), $breakdown));

        $rows = '';
        foreach ($breakdown as $row) {
            $rows .= sprintf(
                '<div style="display:flex;justify-content:space-between;font-size:.85em;padding:.2em 0;">'
                . '<span>%s</span><span><strong>%s</strong> <span class="light">(%d)</span></span>'
                . '</div>',
                Html::encode((string) ($row['sourceName'] ?? '?')),
                Html::encode(number_format((float) $row['average'], 1)),
                (int) $row['count'],
            );
        }

        return sprintf(
            '<div class="meta read-only" style="margin-top:1em;">'
            . '<div class="data" style="padding:.75em 1em;">'
            . '<h5 style="margin:0 0 .35em;">%s</h5>'
            . '<div style="display:flex;align-items:baseline;gap:.4em;margin-bottom:.5em;">'
            . '<span style="font-size:1.6em;font-weight:600;">%s</span>'
            . '<span style="font-size:1.1em;">★</span>'
            . '<span class="light" style="font-size:.85em;">(%d %s)</span>'
            . '</div>'
            . '%s'
            . '</div></div>',
            Html::encode(Craft::t('vouch', 'Rating')),
            Html::encode(number_format($avg, 1)),
            $total,
            Html::encode(Craft::t('vouch', $total === 1 ? 'review' : 'reviews')),
            $rows,
        );
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
                        'authorUserId' => Type::int(),
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
                        if (isset($args['authorUserId'])) {
                            $query->authorUserId((int)$args['authorUserId']);
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
        // Register Vouch's own built-in connectors. Third-party providers
        // attach to the same event from their own plugin's init().
        Event::on(
            ProviderRegistry::class,
            ProviderRegistry::EVENT_REGISTER_PROVIDERS,
            function(RegisterProvidersEvent $event) {
                $event->types[] = ManualConnector::class;
                $event->types[] = GoogleConnector::class;
                $event->types[] = TrustpilotConnector::class;
                $event->types[] = FeefoConnector::class;
                $event->types[] = ReviewsioConnector::class;
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Review::class;
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

                $event->rules['vouch/settings'] = 'vouch/settings/edit';
                $event->rules['POST vouch/settings'] = 'vouch/settings/save';
            }
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
                $event->permissions[] = [
                    'heading' => Craft::t('vouch', 'Vouch'),
                    'permissions' => [
                        'vouch-viewReviews' => [
                            'label' => Craft::t('vouch', 'View reviews'),
                            'nested' => [
                                'vouch-createReviews' => ['label' => Craft::t('vouch', 'Create reviews')],
                                'vouch-editReviews' => ['label' => Craft::t('vouch', 'Edit reviews')],
                                'vouch-deleteReviews' => ['label' => Craft::t('vouch', 'Delete reviews')],
                            ],
                        ],
                        'vouch-viewSources' => [
                            'label' => Craft::t('vouch', 'View sources'),
                            'nested' => [
                                'vouch-manageSources' => ['label' => Craft::t('vouch', 'Create, edit and delete sources')],
                                'vouch-syncSources' => ['label' => Craft::t('vouch', 'Trigger sync')],
                            ],
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
