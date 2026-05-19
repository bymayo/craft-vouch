<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SourcesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('vouch-viewSources');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('vouch/sources/index', [
            'sources' => Vouch::getInstance()->sources->getAllSources(),
            'providers' => Vouch::getInstance()->providers->all(),
        ]);
    }

    public function actionEdit(?int $sourceId = null, ?string $provider = null, ?Source $source = null): Response
    {
        $vouch = Vouch::getInstance();

        if (!$source) {
            if ($sourceId) {
                $source = $vouch->sources->getSourceById($sourceId);
                if (!$source) {
                    throw new NotFoundHttpException("Source not found.");
                }
            } else {
                $source = new Source();
                if ($provider) {
                    $source->providerHandle = $provider;
                }
            }
        }

        // For "new source" with no provider chosen yet, send the user to the
        // provider picker so the edit form has a schema to render against.
        if (!$source->id && !$source->providerHandle) {
            return $this->renderTemplate('vouch/sources/_pick', [
                'providers' => $vouch->providers->all(),
            ]);
        }

        $connector = $vouch->providers->get($source->providerHandle);
        if (!$connector) {
            throw new NotFoundHttpException("Unknown provider: {$source->providerHandle}");
        }

        return $this->renderTemplate('vouch/sources/_edit', [
            'source' => $source,
            'connector' => $connector,
            'schema' => $connector::settingsSchema(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-manageSources');

        $vouch = Vouch::getInstance();
        $request = Craft::$app->getRequest();
        $sourceId = $request->getBodyParam('sourceId');

        $source = $sourceId
            ? $vouch->sources->getSourceById((int) $sourceId)
            : new Source();

        if (!$source) {
            throw new NotFoundHttpException("Source not found.");
        }

        $source->providerHandle = (string) $request->getBodyParam('providerHandle', $source->providerHandle);
        $source->name = (string) $request->getBodyParam('name', $source->name);
        $source->handle = (string) $request->getBodyParam('handle', $source->handle);
        $source->enabled = (bool) $request->getBodyParam('enabled', $source->enabled);
        $source->settings = (array) $request->getBodyParam('settings', []);
        $credentials = (array) $request->getBodyParam('credentials', []);
        // Empty credential values mean "keep the existing one" — never wipe.
        foreach ($credentials as $key => $value) {
            if ($value !== '' && $value !== null) {
                $source->credentials[$key] = $value;
            }
        }
        $source->requiresApproval = (bool) $request->getBodyParam('requiresApproval', false);
        $minRating = $request->getBodyParam('minRating');
        $source->minRating = $minRating !== '' && $minRating !== null ? (float) $minRating : null;
        $source->backfillDays = (int) $request->getBodyParam('backfillDays', $source->backfillDays ?: 90);

        if (!$vouch->sources->saveSource($source)) {
            Craft::$app->getSession()->setError(Craft::t('vouch', 'Couldn’t save source.'));
            Craft::$app->getUrlManager()->setRouteParams(['source' => $source]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Source saved.'));
        return $this->redirectToPostedUrl($source);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-manageSources');

        $sourceId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        Vouch::getInstance()->sources->deleteSourceById($sourceId);

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Source deleted.'));
        return $this->redirect('vouch/sources');
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('vouch-syncSources');

        $vouch = Vouch::getInstance();
        $sourceId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        $source = $vouch->sources->getSourceById($sourceId);
        if (!$source) {
            throw new NotFoundHttpException();
        }

        $connector = $vouch->providers->get($source->providerHandle);
        if (!$connector) {
            return $this->asJson([
                'ok' => false,
                'message' => "Unknown provider: {$source->providerHandle}",
            ]);
        }

        return $this->asJson($connector->testConnection($source));
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-syncSources');

        $vouch = Vouch::getInstance();
        $sourceId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        $source = $vouch->sources->getSourceById($sourceId);
        if (!$source) {
            throw new NotFoundHttpException();
        }

        $result = $vouch->sync->run($source);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson([
                'ok' => $result->ok,
                'message' => $result->message,
                'created' => $result->created,
                'updated' => $result->updated,
            ]);
        }

        $result->ok
            ? Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Synced: {message}', ['message' => $result->message]))
            : Craft::$app->getSession()->setError(Craft::t('vouch', 'Sync failed: {message}', ['message' => $result->message]));

        return $this->redirectToPostedUrl($source);
    }
}
