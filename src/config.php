<?php

/**
 * Vouch plugin — example config file.
 *
 * Copy this to `config/vouch.php` in your Craft project. Values here override
 * whatever is saved in the CP Settings page, so you can pin per-environment
 * behaviour (staging vs production) without depending on the DB row.
 *
 * Multi-environment config is supported via the standard Craft pattern — wrap
 * keys in `'*' => [...]`, `'dev' => [...]`, etc. See:
 * https://craftcms.com/docs/5.x/configure.html#multi-environment-configs
 */

return [
    // Display name shown in the CP nav.
    'pluginName' => 'Vouch',

    // Match the reviewer email to existing Craft users on save. Required for
    // a downstream Points integration to have a user to award against.
    'matchAuthorsToUsers' => true,

    // Days to keep `reviewerEmail` on a synced review before it's nulled out.
    // The `reviewerEmailHash` survives the purge, so user-matching by hash
    // still works after retention expires. Set to 0 to keep emails forever.
    'emailRetentionDays' => 365,

    // First-sync window. After the first sync of a source, `lastSyncedAt`
    // takes over and this value stops mattering. 0 = pull all history.
    'backfillDays' => 90,

    // When a source has "Require manual approval" on, reviews at or above
    // this rating skip the queue. 5.0 = only perfect-score reviews are
    // auto-approved (strictest). Lower the bar if you trust the source.
    'autoApproveThreshold' => 5.0,
];
