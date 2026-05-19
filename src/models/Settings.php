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

    /** Default backfill window (days) for newly-created sources. */
    public int $defaultBackfillDays = 90;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginName'], 'required'];
        $rules[] = [['emailRetentionDays', 'defaultBackfillDays'], 'integer', 'min' => 0];
        $rules[] = [['matchAuthorsToUsers'], 'boolean'];
        return $rules;
    }
}
