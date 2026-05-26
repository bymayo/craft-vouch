<?php

namespace bymayo\vouch\migrations;

use craft\db\Migration;

/**
 * Rename review columns so the DB handles match the user-facing labels
 * ("Headline", "Review", "Reviewer name", etc.). Indices and the FK to
 * the users table need to be dropped and re-created since they reference
 * the old column names.
 */
class m260526_120000_rename_review_fields extends Migration
{
    private const RENAMES = [
        'title' => 'headline',
        'body' => 'review',
        'authorName' => 'reviewerName',
        'authorEmail' => 'reviewerEmail',
        'authorEmailHash' => 'reviewerEmailHash',
        'authorUserId' => 'reviewerUserId',
        'response' => 'businessReply',
    ];

    public function safeUp(): bool
    {
        $table = '{{%vouch_reviews}}';

        // Drop the FK + indices that reference the columns we're about to
        // rename - Postgres and MySQL both refuse renames while indexed.
        \craft\helpers\MigrationHelper::dropForeignKeyIfExists($table, ['authorUserId'], $this);
        \craft\helpers\MigrationHelper::dropIndexIfExists($table, ['authorEmailHash'], false, $this);
        \craft\helpers\MigrationHelper::dropIndexIfExists($table, ['authorUserId'], false, $this);

        foreach (self::RENAMES as $from => $to) {
            $this->renameColumn($table, $from, $to);
        }

        $this->createIndex(null, $table, ['reviewerEmailHash']);
        $this->createIndex(null, $table, ['reviewerUserId']);
        $this->addForeignKey(null, $table, ['reviewerUserId'], '{{%users}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%vouch_reviews}}';

        \craft\helpers\MigrationHelper::dropForeignKeyIfExists($table, ['reviewerUserId'], $this);
        \craft\helpers\MigrationHelper::dropIndexIfExists($table, ['reviewerEmailHash'], false, $this);
        \craft\helpers\MigrationHelper::dropIndexIfExists($table, ['reviewerUserId'], false, $this);

        foreach (self::RENAMES as $from => $to) {
            $this->renameColumn($table, $to, $from);
        }

        $this->createIndex(null, $table, ['authorEmailHash']);
        $this->createIndex(null, $table, ['authorUserId']);
        $this->addForeignKey(null, $table, ['authorUserId'], '{{%users}}', ['id'], 'SET NULL');

        return true;
    }
}
