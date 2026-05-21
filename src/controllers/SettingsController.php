<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\Vouch;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('vouch-manageSettings');
        return parent::beforeAction($action);
    }

    public function actionEdit(): Response
    {
        return $this->renderTemplate('vouch/settings/_edit', [
            'settings' => Vouch::getInstance()->getSettings(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Vouch::getInstance();
        $params = (array) Craft::$app->getRequest()->getBodyParam('settings', []);

        // Route through Craft's Plugins service so settings persist to
        // Project Config (and therefore project.yaml). `config/vouch.php`
        // still overlays per-environment via our `setSettings()` override.
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $params)) {
            Craft::$app->getSession()->setError(Craft::t('vouch', 'Couldn’t save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
