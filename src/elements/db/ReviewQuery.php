<?php

namespace bymayo\vouch\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Query class for `Review` elements. Adds the joined `vouch_reviews` columns
 * and a handful of common filters so element queries from Twig / PHP feel
 * natural — `craft.vouch.reviews().rating(4).source('google-uk').all()`.
 */
class ReviewQuery extends ElementQuery
{
    public mixed $sourceId = null;
    public mixed $externalId = null;
    public mixed $rating = null;
    public mixed $approved = null;
    public mixed $reviewerUserId = null;
    public mixed $relatedElementId = null;

    public function sourceId(mixed $value): self
    {
        $this->sourceId = $value;
        return $this;
    }

    public function externalId(mixed $value): self
    {
        $this->externalId = $value;
        return $this;
    }

    public function rating(mixed $value): self
    {
        $this->rating = $value;
        return $this;
    }

    public function approved(mixed $value): self
    {
        $this->approved = $value;
        return $this;
    }

    public function reviewerUserId(mixed $value): self
    {
        $this->reviewerUserId = $value;
        return $this;
    }

    public function relatedElementId(mixed $value): self
    {
        $this->relatedElementId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('vouch_reviews');

        $this->query->select([
            'vouch_reviews.sourceId',
            'vouch_reviews.externalId',
            'vouch_reviews.rating',
            'vouch_reviews.headline',
            'vouch_reviews.review',
            'vouch_reviews.reviewerName',
            'vouch_reviews.reviewerEmail',
            'vouch_reviews.reviewerEmailHash',
            'vouch_reviews.reviewerUserId',
            'vouch_reviews.relatedElementId',
            'vouch_reviews.reviewedAt',
            'vouch_reviews.businessReply',
            'vouch_reviews.raw',
            'vouch_reviews.approved',
        ]);

        if ($this->sourceId !== null) {
            $this->subQuery->andWhere(Db::parseParam('vouch_reviews.sourceId', $this->sourceId));
        }
        if ($this->externalId !== null) {
            $this->subQuery->andWhere(Db::parseParam('vouch_reviews.externalId', $this->externalId));
        }
        if ($this->rating !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('vouch_reviews.rating', $this->rating));
        }
        if ($this->approved !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('vouch_reviews.approved', $this->approved));
        }
        if ($this->reviewerUserId !== null) {
            $this->subQuery->andWhere(Db::parseParam('vouch_reviews.reviewerUserId', $this->reviewerUserId));
        }
        if ($this->relatedElementId !== null) {
            $this->subQuery->andWhere(Db::parseParam('vouch_reviews.relatedElementId', $this->relatedElementId));
        }

        return parent::beforePrepare();
    }
}
