<?php

namespace bymayo\vouch\services;

use bymayo\vouch\models\Source;
use bymayo\vouch\records\SourceRecord;
use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * CRUD for `Source` records — the configured connections to provider APIs.
 *
 * Credentials are encrypted with Craft's security key on save and decrypted on
 * load, so the raw blob in the DB is never plaintext API keys. Settings stay
 * unencrypted because they're meant to be inspectable in admin UI.
 */
class Sources extends Component
{
    /** @var array<int, Source>|null */
    private ?array $_allCache = null;

    /**
     * @return Source[]
     */
    public function getAllSources(): array
    {
        if ($this->_allCache !== null) {
            return array_values($this->_allCache);
        }

        $this->_allCache = [];
        $rows = SourceRecord::find()->orderBy(['name' => SORT_ASC])->all();
        foreach ($rows as $row) {
            $model = $this->hydrate($row);
            $this->_allCache[$model->id] = $model;
        }
        return array_values($this->_allCache);
    }

    public function getSourceById(int $id): ?Source
    {
        if ($this->_allCache !== null && isset($this->_allCache[$id])) {
            return $this->_allCache[$id];
        }
        $record = SourceRecord::findOne(['id' => $id]);
        return $record ? $this->hydrate($record) : null;
    }

    public function getSourceByHandle(string $handle): ?Source
    {
        foreach ($this->getAllSources() as $source) {
            if ($source->handle === $handle) {
                return $source;
            }
        }
        return null;
    }

    /**
     * @return Source[]
     */
    public function getSourcesByProvider(string $providerHandle): array
    {
        return array_values(array_filter(
            $this->getAllSources(),
            fn(Source $s) => $s->providerHandle === $providerHandle,
        ));
    }

    public function saveSource(Source $source, bool $runValidation = true): bool
    {
        if ($runValidation && !$source->validate()) {
            return false;
        }

        $record = $source->id
            ? SourceRecord::findOne(['id' => $source->id])
            : new SourceRecord();

        if (!$record) {
            return false;
        }

        $isNew = $record->getIsNewRecord();

        $record->providerHandle = $source->providerHandle;
        $record->name = $source->name;
        $record->handle = $source->handle;
        $record->enabled = $source->enabled;
        $record->settings = Json::encode($source->settings);
        $record->credentials = $source->credentials
            ? $this->encrypt(Json::encode($source->credentials))
            : null;
        $record->targetElementType = $source->targetElementType;
        $record->targetElementId = $source->targetElementId;
        $record->requiresApproval = $source->requiresApproval;
        $record->minRating = $source->minRating;
        $record->backfillDays = $source->backfillDays;
        $record->maxRequestsPerSync = $source->maxRequestsPerSync;
        $record->syncInterval = $source->syncInterval;
        $record->lastSyncedAt = $source->lastSyncedAt ? Db::prepareDateForDb($source->lastSyncedAt) : null;
        $record->lastSyncStatus = $source->lastSyncStatus;
        $record->lastSyncError = $source->lastSyncError;

        if ($isNew) {
            $record->uid = $source->uid ?: StringHelper::UUID();
        }

        if (!$record->save()) {
            $source->addErrors($record->getErrors());
            return false;
        }

        $source->id = $record->id;
        $source->uid = $record->uid;
        // ActiveRecord returns date columns as strings, but the Source
        // model declares these as `?\DateTime` for type safety in callers.
        // Coerce here so the model's contract stays honest.
        $source->dateCreated = $record->dateCreated
            ? ($record->dateCreated instanceof \DateTime ? $record->dateCreated : new \DateTime((string) $record->dateCreated))
            : null;
        $source->dateUpdated = $record->dateUpdated
            ? ($record->dateUpdated instanceof \DateTime ? $record->dateUpdated : new \DateTime((string) $record->dateUpdated))
            : null;

        $this->_allCache = null;
        return true;
    }

    public function deleteSourceById(int $id): bool
    {
        $record = SourceRecord::findOne(['id' => $id]);
        if (!$record) {
            return false;
        }
        $record->delete();
        $this->_allCache = null;
        return true;
    }

    private function hydrate(SourceRecord $record): Source
    {
        $source = new Source();
        $source->id = (int) $record->id;
        $source->providerHandle = (string) $record->providerHandle;
        $source->name = (string) $record->name;
        $source->handle = (string) $record->handle;
        $source->enabled = (bool) $record->enabled;
        $source->settings = $record->settings ? (Json::decodeIfJson($record->settings) ?: []) : [];

        if ($record->credentials) {
            $plaintext = $this->decrypt((string) $record->credentials);
            $source->credentials = $plaintext ? (Json::decodeIfJson($plaintext) ?: []) : [];
        }

        $source->targetElementType = $record->targetElementType;
        $source->targetElementId = $record->targetElementId !== null ? (int) $record->targetElementId : null;
        $source->requiresApproval = (bool) $record->requiresApproval;
        $source->minRating = $record->minRating !== null ? (float) $record->minRating : null;
        $source->backfillDays = (int) ($record->backfillDays ?? 90);
        $source->maxRequestsPerSync = $record->maxRequestsPerSync !== null
            ? (int) $record->maxRequestsPerSync
            : null;
        $source->syncInterval = $record->syncInterval;
        $source->lastSyncedAt = $record->lastSyncedAt
            ? new \DateTime((string) $record->lastSyncedAt)
            : null;
        $source->lastSyncStatus = $record->lastSyncStatus;
        $source->lastSyncError = $record->lastSyncError;
        $source->dateCreated = $record->dateCreated ? new \DateTime((string) $record->dateCreated) : null;
        $source->dateUpdated = $record->dateUpdated ? new \DateTime((string) $record->dateUpdated) : null;
        $source->uid = $record->uid;
        return $source;
    }

    /**
     * Yii's `encryptByKey()` returns raw binary (HMAC + IV + ciphertext),
     * which MySQL's utf8mb4 TEXT columns reject. Base64-wrapping the value
     * keeps the column type stable and means the encrypted blob is also
     * safe in JSON-encoded project-config exports etc.
     */
    private function encrypt(string $plaintext): string
    {
        return base64_encode(
            Craft::$app->getSecurity()->encryptByKey($plaintext),
        );
    }

    private function decrypt(string $ciphertext): ?string
    {
        try {
            $binary = base64_decode($ciphertext, true);
            if ($binary === false) {
                return null;
            }
            $value = Craft::$app->getSecurity()->decryptByKey($binary);
            return $value !== false ? $value : null;
        } catch (\Throwable) {
            // App security key changed or value isn't actually encrypted —
            // return null rather than corrupting the source UI with an error.
            return null;
        }
    }
}
