<?php

namespace bymayo\vouch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $sourceId
 * @property string $externalId
 * @property float $rating
 * @property string|null $title
 * @property string|null $body
 * @property string|null $authorName
 * @property string|null $authorEmail
 * @property string|null $authorEmailHash
 * @property int|null $authorUserId
 * @property int|null $relatedElementId
 * @property \DateTime|null $reviewedAt
 * @property string|null $response
 * @property string|null $raw
 * @property bool $approved
 */
class ReviewRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%vouch_reviews}}';
    }
}
