<?php

namespace bymayo\vouch\jobs;

use bymayo\vouch\Vouch;
use Craft;
use craft\queue\BaseJob;

/**
 * Queue-backed wrapper around `Sync::run()`. Used by:
 *
 *  - Scheduled sync (cron → `vouch/sync/due`) — one job per due source so a
 *    slow Trustpilot account can't block a fast Google sync.
 *  - The CP "Sync now" button when the source is large enough that running
 *    it inline would tie up the request.
 *
 * Failures throw, which lets Craft's queue retry policy take over (default
 * 3 attempts with exponential backoff). `Sync` itself still writes the error
 * onto `lastSyncError`, so a permanently-broken source surfaces in the CP
 * source list — not just in the queue manager.
 */
class SyncSourceJob extends BaseJob
{
    public int $sourceId;

    public function execute($queue): void
    {
        $vouch = Vouch::getInstance();
        $source = $vouch->sources->getSourceById($this->sourceId);

        if (!$source) {
            // Source was deleted between queue and execution — drop quietly.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('vouch', 'Syncing {name}', ['name' => $source->name]));
        $result = $vouch->sync->run($source);
        $this->setProgress($queue, 1, $result->message);

        if (!$result->ok) {
            // Re-throw so Craft retries the job. The detailed error has
            // already been written to the source row by Sync::finish().
            throw new \RuntimeException($result->message);
        }
    }

    protected function defaultDescription(): ?string
    {
        $source = Vouch::getInstance()->sources->getSourceById($this->sourceId);
        return $source
            ? Craft::t('vouch', 'Syncing reviews: {name}', ['name' => $source->name])
            : Craft::t('vouch', 'Syncing Vouch source');
    }
}
