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
 * Cadence is set by your cron entry, not by per-source config — there's no
 * separate "schedule" on a Source. Examples:
 *
 *  - Hourly, queue-driven (recommended):
 *      `0 * * * *  php craft vouch/sync/all`
 *      `* * * * *  php craft queue/run`
 *
 *  - Daily, no queue worker (simpler, but a slow source blocks the run):
 *      `0 4 * * *  php craft vouch/sync/all --sync`
 *
 *  - Different cadences per source:
 *      `0 * * * *  php craft vouch/sync/source google-uk`
 *      `0 4 * * *  php craft vouch/sync/source trustpilot-main`
 */
class SyncController extends Controller
{
    /**
     * Force synchronous execution instead of queueing. Useful for installs
     * without a running queue worker, and for one-off debugging.
     */
    public bool $sync = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['all', 'source'], true)) {
            $options[] = 'sync';
        }
        return $options;
    }

    /**
     * Sync every enabled source. Queues by default; pass `--sync` to run
     * inline. Cron-friendly.
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
            if ($this->sync) {
                $ok = $this->runOne($source) && $ok;
            } else {
                Vouch::getInstance()->sync->queue($source);
                $this->stdout("Queued: {$source->name}\n");
            }
        }

        if (!$this->sync) {
            $this->stdout(sprintf("Queued %d source(s).\n", count($sources)), Console::FG_GREEN);
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync a single source by numeric id or handle. With `--sync` runs
     * inline; otherwise pushes onto the queue.
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

        if ($this->sync) {
            return $this->runOne($source) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $jobId = $vouch->sync->queue($source);
        if ($jobId === null) {
            $this->stderr("Source is disabled.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout("Queued job {$jobId}: {$source->name}\n", Console::FG_GREEN);
        return ExitCode::OK;
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
