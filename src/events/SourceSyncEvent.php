<?php

namespace bymayo\vouch\events;

use bymayo\vouch\models\Source;
use bymayo\vouch\services\SyncResult;
use yii\base\Event;

/**
 * Fired by `Sync::run()` either side of a source sync.
 *
 *  - For `EVENT_BEFORE_SOURCE_SYNC`, `$result` is null and `$cancelled` may be
 *    flipped to `true` to skip the sync entirely. Useful for "freeze window"
 *    listeners (e.g. block syncs during a deploy).
 *  - For `EVENT_AFTER_SOURCE_SYNC`, `$result` is the populated `SyncResult`.
 */
class SourceSyncEvent extends Event
{
    public bool $cancelled = false;

    public function __construct(
        public Source $source,
        public ?SyncResult $result = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
