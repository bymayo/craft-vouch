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
 * The two patterns this is designed for:
 *
 *  1. **External cron + queue runner** (recommended for production):
 *     `* /5 * * * * php craft vouch/sync/due`     (queues due jobs)
 *     `* /1 * * * * php craft queue/run`           (runs the queue)
 *
 *  2. **Cron only, no queue worker** (small installs):
 *     `0 * * * * php craft vouch/sync/all`        (sync every enabled source, in-process)
 */
class SyncController extends Controller
{
    /**
     * Force synchronous execution instead of queueing. Default true for
     * `actionAll` (cron-friendly: nothing else needs to be running), false
     * for `actionDue` (queue is the whole point).
     */
    public bool $sync = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['all', 'due', 'source'], true)) {
            $options[] = 'sync';
        }
        return $options;
    }

    /**
     * Sync every enabled source. Synchronous by default — cron-friendly.
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
            $ok = $this->runOne($source, true) && $ok;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Queue (or run, with `--sync`) every source whose schedule says it's due
     * now. The intended cron target — pair with `craft queue/run` workers.
     */
    public function actionDue(): int
    {
        $vouch = Vouch::getInstance();
        $sources = array_filter(
            $vouch->sources->getAllSources(),
            static fn(Source $s) => $s->enabled && Vouch::getInstance()->sync->isDue($s),
        );

        if (!$sources) {
            $this->stdout("Nothing due.\n");
            return ExitCode::OK;
        }

        $count = 0;
        foreach ($sources as $source) {
            if ($this->sync) {
                $this->runOne($source, true);
            } else {
                $vouch->sync->queue($source);
                $this->stdout("Queued: {$source->name}\n");
            }
            $count++;
        }

        $this->stdout(sprintf("%s %d source(s).\n", $this->sync ? 'Synced' : 'Queued', $count), Console::FG_GREEN);
        return ExitCode::OK;
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
            return $this->runOne($source, true) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $jobId = $vouch->sync->queue($source);
        if ($jobId === null) {
            $this->stderr("Source is disabled.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout("Queued job {$jobId}: {$source->name}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function runOne(Source $source, bool $verbose): bool
    {
        $this->stdout("→ {$source->name} ({$source->providerHandle})\n");
        $result = Vouch::getInstance()->sync->run($source);
        $colour = $result->ok ? Console::FG_GREEN : Console::FG_RED;
        $this->stdout("  {$result->message}\n", $colour);
        return $result->ok;
    }
}
