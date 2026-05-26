<?php

namespace bymayo\vouch\gql\types;

use bymayo\vouch\elements\Review;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class ReviewType
{
    private static ?ObjectType $type = null;

    public static function getType(): ObjectType
    {
        if (self::$type !== null) {
            return self::$type;
        }

        self::$type = new ObjectType([
            'name' => 'VouchReview',
            'description' => 'A review pulled in by Vouch from a third-party provider.',
            'fields' => [
                'id' => Type::int(),
                'sourceId' => Type::int(),
                'externalId' => [
                    'type' => Type::string(),
                    'description' => "The provider's stable id for this review.",
                ],
                'rating' => Type::float(),
                'headline' => Type::string(),
                'review' => Type::string(),
                'reviewerName' => Type::string(),
                'reviewerUserId' => [
                    'type' => Type::int(),
                    'description' => 'The Craft user id if the reviewer email matched an existing user.',
                ],
                'relatedElementId' => Type::int(),
                'reviewedAt' => [
                    'type' => Type::string(),
                    'description' => 'ISO 8601 timestamp of when the review was authored at the provider.',
                    'resolve' => fn(Review $r) => $r->reviewedAt?->format(\DateTimeInterface::ATOM),
                ],
                'businessReply' => [
                    'type' => Type::string(),
                    'description' => 'Optional business reply, when the provider returned one.',
                ],
                'approved' => Type::boolean(),
                'providerHandle' => [
                    'type' => Type::string(),
                    'description' => "Convenience: the source's provider handle (google, trustpilot, …).",
                    'resolve' => fn(Review $r) => $r->getSource()?->providerHandle,
                ],
                'sourceName' => [
                    'type' => Type::string(),
                    'resolve' => fn(Review $r) => $r->getSource()?->name,
                ],
                'dateCreated' => [
                    'type' => Type::string(),
                    'resolve' => fn(Review $r) => $r->dateCreated?->format(\DateTimeInterface::ATOM),
                ],
            ],
        ]);

        return self::$type;
    }
}
