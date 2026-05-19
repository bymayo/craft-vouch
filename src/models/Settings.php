<?php

namespace bymayo\vouch\models;

use craft\base\Model;

/**
 * Plugin-wide settings, stored in `{{%vouch_settings}}` (NOT Project Config) so
 * admins can change them on production without a deploy clobbering them. Per-
 * environment overrides live in `config/vouch.php`.
 */
class Settings extends Model
{
    /** Display name shown in the CP nav. */
    public string $pluginName = 'Vouch';

    /**
     * Days to keep `authorEmail` on a synced review before it's nulled out.
     * The `authorEmailHash` column survives, so user matching for Points still
     * works after the email is purged. Set to 0 to keep emails forever.
     */
    public int $emailRetentionDays = 365;

    /**
     * If true, match the reviewer's email to an existing Craft user and store
     * the `authorUserId` on the review. Required for the Points integration to
     * have something to award against.
     */
    public bool $matchAuthorsToUsers = true;

    /**
     * On a source's *first* sync, fetch reviews from up to this many days
     * ago. After that the `lastSyncedAt` cursor drives the window, so this
     * value only matters at first-ingest time. 0 = unlimited (all history).
     */
    public int $backfillDays = 90;

    /**
     * When a source has `requiresApproval` on, reviews at or above this
     * rating skip the queue and land approved. Everything below holds for
     * an admin to look at. 5.0 means "only perfect-score reviews are
     * auto-approved" — the strictest sensible default; turn `requiresApproval`
     * off entirely if you want everything to land approved.
     */
    public float $autoApproveThreshold = 5.0;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginName'], 'required'];
        $rules[] = [['emailRetentionDays', 'backfillDays'], 'integer', 'min' => 0];
        $rules[] = [['autoApproveThreshold'], 'number', 'min' => 0, 'max' => 5];
        $rules[] = [['matchAuthorsToUsers'], 'boolean'];
        return $rules;
    }
}
