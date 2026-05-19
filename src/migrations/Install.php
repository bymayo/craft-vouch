<?php

namespace bymayo\vouch\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%vouch_sources}}', [
            'id' => $this->primaryKey(),
            'providerHandle' => $this->string()->notNull(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            // Provider-specific settings (non-secret), JSON-encoded.
            'settings' => $this->text(),
            // Credentials column — encrypted at the application layer before
            // insert/update (see Sources service). Never read directly outside
            // the service.
            'credentials' => $this->text(),
            'targetElementType' => $this->string(),
            'targetElementId' => $this->integer(),
            'requiresApproval' => $this->boolean()->notNull()->defaultValue(false),
            'minRating' => $this->decimal(3, 2),
            'backfillDays' => $this->integer()->notNull()->defaultValue(90),
            'maxRequestsPerSync' => $this->integer(),
            'syncInterval' => $this->string(),
            'lastSyncedAt' => $this->dateTime(),
            'lastSyncStatus' => $this->string(),
            'lastSyncError' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%vouch_sources}}', ['handle'], true);
        $this->createIndex(null, '{{%vouch_sources}}', ['providerHandle']);
        $this->createIndex(null, '{{%vouch_sources}}', ['enabled']);

        $this->createTable('{{%vouch_reviews}}', [
            'id' => $this->integer()->notNull(),
            'sourceId' => $this->integer()->notNull(),
            'externalId' => $this->string()->notNull(),
            'rating' => $this->decimal(3, 2)->notNull()->defaultValue(0),
            'title' => $this->string(),
            'body' => $this->text(),
            'authorName' => $this->string(),
            'authorEmail' => $this->string(),
            // SHA-256 of lower-cased email. Survives PII purge so the Points
            // integration can still match reviews to users after retention
            // has expired and `authorEmail` has been nulled out.
            'authorEmailHash' => $this->string(64),
            'authorUserId' => $this->integer(),
            'relatedElementId' => $this->integer(),
            'reviewedAt' => $this->dateTime(),
            'response' => $this->text(),
            // Provider's raw JSON for forensics / future schema evolution.
            'raw' => $this->longText(),
            'approved' => $this->boolean()->notNull()->defaultValue(true),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%vouch_reviews}}', ['sourceId', 'externalId'], true);
        $this->createIndex(null, '{{%vouch_reviews}}', ['authorEmailHash']);
        $this->createIndex(null, '{{%vouch_reviews}}', ['authorUserId']);
        $this->createIndex(null, '{{%vouch_reviews}}', ['relatedElementId']);
        $this->createIndex(null, '{{%vouch_reviews}}', ['rating']);
        $this->createIndex(null, '{{%vouch_reviews}}', ['approved']);

        $this->addForeignKey(null, '{{%vouch_reviews}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%vouch_reviews}}', ['sourceId'], '{{%vouch_sources}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%vouch_reviews}}', ['authorUserId'], '{{%users}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%vouch_reviews}}', ['relatedElementId'], '{{%elements}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%vouch_reviews}}');
        $this->dropTableIfExists('{{%vouch_sources}}');
        return true;
    }
}
