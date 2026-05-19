<?php

namespace bymayo\vouch;

use bymayo\vouch\connectors\feefo\FeefoConnector;
use bymayo\vouch\connectors\google\GoogleConnector;
use bymayo\vouch\connectors\reviewsio\ReviewsioConnector;
use bymayo\vouch\connectors\trustpilot\TrustpilotConnector;
use bymayo\vouch\elements\Review;
use bymayo\vouch\events\RegisterProvidersEvent;
use bymayo\vouch\models\Settings;
use bymayo\vouch\services\ProviderRegistry;
use bymayo\vouch\services\Reviews;
use bymayo\vouch\services\Sources;
use bymayo\vouch\services\Sync;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\db\Query;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
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
        if ($user->checkPermission('vouch-manageSettings')) {
            $subnav['settings'] = ['label' => Craft::t('vouch', 'Settings'), 'url' => 'vouch/settings'];
        }

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

    /**
     * Settings live in `{{%vouch_settings}}`, not Project Config — admins can
     * change branding/operational values on a production environment without
     * a deploy clobbering them, and there's no cross-environment yaml drift.
     *
     * Devs can still override per-environment via `config/vouch.php`; values
     * there overlay the DB row on every read.
     */
    protected function createSettingsModel(): ?Model
    {
        $model = Craft::createObject(Settings::class);

        try {
            $row = (new Query())
                ->from('{{%vouch_settings}}')
                ->where(['id' => 1])
                ->one();
            if ($row && !empty($row['settings'])) {
                $data = Json::decodeIfJson($row['settings']);
                if (is_array($data)) {
                    $model->setAttributes($data, false);
                }
            }
        } catch (\Throwable) {
            // Pre-install / pre-migration — fall back to defaults.
        }

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('vouch');
        if (!empty($fileConfig)) {
            $model->setAttributes($fileConfig, false);
        }

        return $model;
    }

    /**
     * Intentional no-op — same reasoning as the Points plugin: opting out of
     * Project Config means the DB row is the single source of truth, so we
     * don't want Craft overlaying `plugins.settings` values on top of it.
     */
    public function setSettings(array $settings): void
    {
        // no-op
    }

    public function saveSettings(array $settings): bool
    {
        $model = $this->getSettings();
        $model->setAttributes($settings, false);
        if (!$model->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $payload = Json::encode($settings);

        $exists = (new Query())
            ->from('{{%vouch_settings}}')
            ->where(['id' => 1])
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%vouch_settings}}', [
                    'settings' => $payload,
                    'dateUpdated' => $now,
                ], ['id' => 1])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%vouch_settings}}', [
                    'id' => 1,
                    'settings' => $payload,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        }

        return true;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('vouch/settings')
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
                $event->rules['vouch/reviews/<reviewId:\d+>'] = 'vouch/reviews/edit';
                $event->rules['POST vouch/reviews/approve'] = 'vouch/reviews/approve';

                $event->rules['vouch/sources'] = 'vouch/sources/index';
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
