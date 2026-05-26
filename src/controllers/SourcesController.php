<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use Craft;
use craft\helpers\UrlHelper;
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

    /**
     * JSON endpoint backing the Vue admin table on the sources index.
     * Same shape as Craft's built-in admin tables - `pagination` block +
     * `data` array - so the front-end `Craft.VueAdminTable` widget can
     * render, sort, search, and delete rows without any custom JS here.
     */
    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('vouch-viewSources');

        $request = Craft::$app->getRequest();
        $page = (int) $request->getParam('page', 1);
        $limit = (int) $request->getParam('per_page', 50);
        $search = $request->getParam('search');

        $vouch = Vouch::getInstance();
        $providers = $vouch->providers->all();
        $all = $vouch->sources->getAllSources();

        if (is_string($search) && trim($search) !== '') {
            $needle = strtolower(trim($search));
            $all = array_values(array_filter($all, fn($s) =>
                str_contains(strtolower($s->name), $needle) ||
                str_contains(strtolower($s->handle), $needle) ||
                str_contains(strtolower($s->providerHandle), $needle),
            ));
        }

        $total = count($all);
        $offset = ($page - 1) * $limit;
        $rows = array_slice($all, $offset, $limit);

        $data = array_map(function($source) use ($providers) {
            $connector = $providers[$source->providerHandle] ?? null;
            $isManual = $source->providerHandle === 'manual';
            $isPullable = $connector && !$isManual;

            // Live connection chip - JS picks these up after the table
            // renders and replaces "Checking…" with the actual state.
            // Em-dash for non-pullable / disabled sources so the column
            // stays readable.
            if (!$isPullable || !$source->enabled) {
                $connectionHtml = '<span class="light">-</span>';
            } else {
                $connectionHtml = sprintf(
                    '<span class="vouch-connection-status" data-source-id="%d" data-pending="1">' .
                    '<span class="status" style="background:#9CA3AF;border-color:#9CA3AF;"></span>' .
                    '<span class="vouch-connection-text" style="font-weight:600;color:#6B7280;">%s</span>' .
                    '</span>',
                    $source->id,
                    Craft::t('vouch', 'Checking…'),
                );
            }

            $lastSyncedHtml = $source->lastSyncedAt
                ? Craft::$app->getFormatter()->asDatetime($source->lastSyncedAt, 'short')
                : '<span class="light">' . Craft::t('vouch', 'Never') . '</span>';

            // Sync action lives as a tiny inline form in the cell so the
            // admin-table widget doesn't need a custom-button slot. Manual
            // and disabled sources show an em-dash placeholder so the
            // column width stays stable across rows.
            if (!$isPullable || !$source->enabled) {
                $syncHtml = '<span class="light">-</span>';
            } else {
                $syncHtml = sprintf(
                    '<form method="post" style="margin:0; display:inline-block;">' .
                    '<input type="hidden" name="%s" value="%s">' .
                    '<input type="hidden" name="action" value="vouch/sources/sync">' .
                    '<input type="hidden" name="sourceId" value="%d">' .
                    '<button type="submit" class="btn">%s</button>' .
                    '</form>',
                    Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                    Craft::$app->getRequest()->getCsrfToken(),
                    $source->id,
                    Craft::t('vouch', 'Sync'),
                );
            }

            return [
                'id' => $source->id,
                'title' => $source->name,
                'url' => UrlHelper::cpUrl('vouch/sources/' . $source->id),
                'handle' => $source->handle,
                // VueAdminTable reads `status` as a BOOLEAN (not a colour
                // string) - truthy = green "enabled" dot, falsy = hollow.
                // Same convention as Craft's own settings indexes.
                'status' => $source->enabled,
                'provider' => $connector ? $connector::displayName() : $source->providerHandle,
                'connection' => $connectionHtml,
                'lastSynced' => $lastSyncedHtml,
                'sync' => $syncHtml,
            ];
        }, $rows);

        return $this->asSuccess(data: [
            'pagination' => \craft\helpers\AdminTable::paginationLinks($request->getParam('page', 1), $total, $limit),
            'data' => $data,
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

        $providers = $vouch->providers->all();
        if (empty($providers)) {
            return $this->redirect('vouch/sources');
        }

        // New source landing on /sources/new without a provider param -
        // default to the first available so the form has something to
        // render. The user can switch via the dropdown.
        if (!$source->id && !$source->providerHandle) {
            $source->providerHandle = array_key_first($providers);
        }

        $connector = $providers[$source->providerHandle] ?? null;
        if (!$connector) {
            throw new NotFoundHttpException("Unknown provider: {$source->providerHandle}");
        }

        // Pre-compute every provider's schema so the template can render
        // a block per provider - JS shows/hides based on the dropdown.
        $schemas = [];
        foreach ($providers as $handle => $p) {
            $schemas[$handle] = $p::settingsSchema();
        }

        return $this->renderTemplate('vouch/sources/_edit', [
            'source' => $source,
            'connector' => $connector,
            'providers' => $providers,
            'schemas' => $schemas,
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
        // Credentials are replaced wholesale. With autosuggest fields, the
        // current value (or env template) is always rendered, so an empty
        // value is intentional rather than a "leave it alone" signal.
        $source->credentials = (array) $request->getBodyParam('credentials', []);
        $source->requiresApproval = (bool) $request->getBodyParam('requiresApproval', false);

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

        $request = Craft::$app->getRequest();
        // VueAdminTable posts `id`; legacy form HTML posts `sourceId`.
        $sourceId = (int) ($request->getBodyParam('id')
            ?? $request->getRequiredBodyParam('sourceId'));

        Vouch::getInstance()->sources->deleteSourceById($sourceId);

        if ($request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('vouch', 'Source deleted.'));
        }

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
