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

        $params = Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!Vouch::getInstance()->saveSettings((array) $params)) {
            Craft::$app->getSession()->setError(Craft::t('vouch', 'Couldn’t save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
