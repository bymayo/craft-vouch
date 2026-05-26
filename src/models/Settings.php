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
     * Days to keep `reviewerEmail` on a synced review before it's nulled out.
     * The `reviewerEmailHash` column survives, so user matching for Points still
     * works after the email is purged. Set to 0 to keep emails forever.
     */
    public int $emailRetentionDays = 365;

    /**
     * If true, match the reviewer's email to an existing Craft user and store
     * the `reviewerUserId` on the review. Required for the Points integration to
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
     * auto-approved" - the strictest sensible default; turn `requiresApproval`
     * off entirely if you want everything to land approved.
     */
    public float $autoApproveThreshold = 5.0;

    /**
     * If true, the front-end submit action rejects any submission whose
     * `reviewerEmail` matches an existing Craft user, unless the submitter is
     * currently logged in as that user. Stops anonymous attackers from
     * submitting spam reviews using a real customer's email address.
     * The controller returns a 403-style error with a `requiresLogin` flag so
     * the form can prompt the user to log in instead.
     */
    public bool $requireLoginForKnownEmails = true;

    /**
     * Maximum length of the review headline. Enforced as a validation rule on
     * the Review element so it applies to every save path (front-end submit,
     * CP authoring, sync). Set to 0 to disable the limit.
     */
    public int $headlineMaxLength = 120;

    /**
     * Maximum length of the review body. Set to 0 to disable.
     */
    public int $reviewMaxLength = 2000;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginName'], 'required'];
        $rules[] = [['emailRetentionDays', 'backfillDays', 'headlineMaxLength', 'reviewMaxLength'], 'integer', 'min' => 0];
        $rules[] = [['autoApproveThreshold'], 'number', 'min' => 0, 'max' => 5];
        $rules[] = [['matchAuthorsToUsers', 'requireLoginForKnownEmails'], 'boolean'];
        return $rules;
    }
}
