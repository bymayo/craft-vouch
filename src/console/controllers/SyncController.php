<?php

namespace bymayo\vouch\console\controllers;

use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * `craft vouch/sync/*` console commands.
 *
 * Runs synchronously. Cadence is set by your cron entry - there's no
 * per-source schedule. Examples:
 *
 *  - Hourly, every source:
 *      `0 * * * *  php craft vouch/sync/all`
 *
 *  - Different cadences per source:
 *      `0 * * * *  php craft vouch/sync/source google-uk`
 *      `0 4 * * *  php craft vouch/sync/source trustpilot-main`
 */
class SyncController extends Controller
{
    /**
     * Sync every enabled source. Cron-friendly.
     */
    public function actionAll(): int
    {
        $sources = array_filter(
            Vouch::getInstance()->sources->getAllSources(),
            static fn(Source $s) => $s->enabled,
        );

        if (!$sources) {
            $this->stdout("No enabled sources.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $ok = true;
        foreach ($sources as $source) {
            $ok = $this->runOne($source) && $ok;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync a single source by numeric id or handle.
     */
    public function actionSource(string $idOrHandle): int
    {
        $vouch = Vouch::getInstance();
        $source = ctype_digit($idOrHandle)
            ? $vouch->sources->getSourceById((int) $idOrHandle)
            : $vouch->sources->getSourceByHandle($idOrHandle);

        if (!$source) {
            $this->stderr("Source not found: {$idOrHandle}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!$source->enabled) {
            $this->stderr("Source is disabled.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        return $this->runOne($source) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function runOne(Source $source): bool
    {
        $this->stdout("→ {$source->name} ({$source->providerHandle})\n");
        $result = Vouch::getInstance()->sync->run($source);
        $colour = $result->ok ? Console::FG_GREEN : Console::FG_RED;
        $this->stdout("  {$result->message}\n", $colour);
        return $result->ok;
    }
}
