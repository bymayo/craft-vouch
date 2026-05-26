<?php

namespace bymayo\vouch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $sourceId
 * @property string $externalId
 * @property float $rating
 * @property string|null $headline
 * @property string|null $review
 * @property string|null $reviewerName
 * @property string|null $reviewerEmail
 * @property string|null $reviewerEmailHash
 * @property int|null $reviewerUserId
 * @property int|null $relatedElementId
 * @property \DateTime|null $reviewedAt
 * @property string|null $businessReply
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
