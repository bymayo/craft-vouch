<?php

namespace bymayo\vouch\services;

use bymayo\vouch\models\Source;
use bymayo\vouch\records\SourceRecord;
use bymayo\vouch\Vouch;
use Craft;
use craft\helpers\Db;
use yii\base\Component;

/**
 * Drive a single sync run against one source.
 *
 * Phase 2 is intentionally synchronous — useful for "Sync now" buttons and
 * for `vouch/sync/source` console runs against small Google data sets. Phase
 * 4 swaps this for a queue-backed `SyncSourceJob` that calls into the same
 * `run()` method, so all of the upsert/cursor/bookkeeping logic stays here.
 */
class Sync extends Component
{
    /**
     * Run a full sync for `$source`. Returns a result summary the caller can
     * surface in the CP / console output.
     *
     * @return SyncResult
     */
    public function run(Source $source): SyncResult
    {
        $vouch = Vouch::getInstance();
        $connector = $vouch->providers->get($source->providerHandle);

        if (!$connector) {
            return $this->finish($source, SyncResult::error("Unknown provider: {$source->providerHandle}"));
        }

        if (!$source->enabled) {
            return $this->finish($source, SyncResult::skipped('Source is disabled.'));
        }

        // Backfill cursor: first sync of a source honours the user's chosen
        // `backfillDays` window so we don't hammer providers' quotas pulling
        // years of history. Subsequent syncs use the previous run timestamp
        // as the cursor where the provider supports it.
        $since = $this->resolveCursor($source);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        try {
            foreach ($connector->fetchReviews($source, $since) as $fetched) {
                $existing = $vouch->reviews->findBySourceAndExternalId($source->id, $fetched->externalId);
                $review = $vouch->reviews->upsertFromFetched($source, $fetched);

                if (!$review) {
                    $errors[] = "Failed to save review {$fetched->externalId}";
                    continue;
                }

                $existing ? $updated++ : $created++;
            }
        } catch (\Throwable $e) {
            return $this->finish($source, SyncResult::error($e->getMessage()));
        }

        $message = sprintf('%d new, %d updated.', $created, $updated);
        if ($errors) {
            $message .= ' ' . count($errors) . ' error(s).';
        }

        return $this->finish($source, new SyncResult(
            ok: empty($errors),
            message: $message,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            errors: $errors,
        ));
    }

    /**
     * The point in time before which we don't bother re-fetching. Concretely:
     *
     *  - If we've synced before: 1 hour before `lastSyncedAt` (small overlap
     *    in case the provider reports a review with a slightly-earlier
     *    timestamp than when it was first visible to the API).
     *  - If this is the first sync: `now - backfillDays` (or null for
     *    all-time when `backfillDays` is 0).
     */
    private function resolveCursor(Source $source): ?\DateTimeInterface
    {
        if ($source->lastSyncedAt) {
            return (clone $source->lastSyncedAt)->modify('-1 hour');
        }

        if ($source->backfillDays <= 0) {
            return null;
        }

        return (new \DateTime())->modify('-' . $source->backfillDays . ' days');
    }

    private function finish(Source $source, SyncResult $result): SyncResult
    {
        $record = SourceRecord::findOne(['id' => $source->id]);
        if ($record) {
            $record->lastSyncedAt = Db::prepareDateForDb(new \DateTime());
            $record->lastSyncStatus = $result->ok ? 'ok' : 'error';
            $record->lastSyncError = $result->ok ? null : $result->message;
            $record->save(false);
        }

        Craft::info(
            sprintf('Vouch sync (%s): %s', $source->handle, $result->message),
            'vouch',
        );

        return $result;
    }
}
