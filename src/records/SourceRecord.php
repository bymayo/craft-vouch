<?php

namespace bymayo\vouch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $providerHandle
 * @property string $name
 * @property string $handle
 * @property bool $enabled
 * @property string|null $settings
 * @property string|null $credentials
 * @property string|null $targetElementType
 * @property int|null $targetElementId
 * @property bool $requiresApproval
 * @property float|null $minRating
 * @property int $backfillDays
 * @property int|null $maxRequestsPerSync
 * @property string|null $syncInterval
 * @property \DateTime|null $lastSyncedAt
 * @property string|null $lastSyncStatus
 * @property string|null $lastSyncError
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class SourceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%vouch_sources}}';
    }
}
