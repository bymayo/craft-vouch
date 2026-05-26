<?php

namespace bymayo\vouch\models;

use bymayo\vouch\records\SourceRecord;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;

/**
 * A configured connection to a third-party review provider. One row per
 * connection - a Craft install can have many sources, including multiple of
 * the same provider (e.g. two Google locations).
 *
 * `settings` and `credentials` are free-form arrays whose shape is dictated by
 * the connector's `settingsSchema()`. They're persisted as JSON; credentials
 * pass through Craft's encrypted-config column on save (see Sources service).
 */
class Source extends Model
{
    public ?int $id = null;
    public string $providerHandle = '';
    public string $name = '';
    public string $handle = '';
    public bool $enabled = true;

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, mixed> */
    public array $credentials = [];

    public ?string $targetElementType = null;
    public ?int $targetElementId = null;

    public bool $requiresApproval = false;
    public ?float $minRating = null;
    public int $backfillDays = 90;
    public ?int $maxRequestsPerSync = null;

    /**
     * Sync cadence - `manual`, `hourly`, `daily`, or a cron expression.
     * Null means inherit the plugin default (manual until Phase 4).
     */
    public ?string $syncInterval = null;

    public ?\DateTime $lastSyncedAt = null;
    public ?string $lastSyncStatus = null;
    public ?string $lastSyncError = null;

    public ?\DateTime $dateCreated = null;
    public ?\DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['providerHandle', 'name', 'handle'], 'required'];
        $rules[] = [['handle'], HandleValidator::class];
        $rules[] = [
            ['handle'],
            UniqueValidator::class,
            'targetClass' => SourceRecord::class,
            // Filter ensures editing an existing source doesn't trigger the
            // "in use" error against itself.
            'filter' => function($query) {
                if ($this->id) {
                    $query->andWhere(['not', ['id' => $this->id]]);
                }
            },
        ];
        $rules[] = [['backfillDays', 'maxRequestsPerSync', 'targetElementId'], 'integer', 'min' => 0];
        $rules[] = [['minRating'], 'number', 'min' => 0, 'max' => 5];
        $rules[] = [['enabled', 'requiresApproval'], 'boolean'];
        return $rules;
    }

    public function init(): void
    {
        parent::init();
        if (!$this->uid) {
            $this->uid = StringHelper::UUID();
        }
    }
}
