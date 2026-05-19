<?php

namespace bymayo\vouch\variables;

use bymayo\vouch\connectors\ConnectorInterface;
use bymayo\vouch\elements\db\ReviewQuery;
use bymayo\vouch\elements\Review;
use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;

/**
 * Twig API for Vouch — exposed as `craft.vouch.*`.
 *
 *  {% set best = craft.vouch.reviews().approved(true).rating('>= 4').all() %}
 */
class VouchVariable
{
    /** Returns a chainable Review query — same shape as `craft.entries()` etc. */
    public function reviews(): ReviewQuery
    {
        return Review::find();
    }

    public function review(?int $id): ?Review
    {
        if (!$id) return null;
        return Review::find()->id($id)->status(null)->one();
    }

    /**
     * @return Source[]
     */
    public function sources(): array
    {
        return Vouch::getInstance()->sources->getAllSources();
    }

    public function source(?string $handle): ?Source
    {
        if (!$handle) return null;
        return Vouch::getInstance()->sources->getSourceByHandle($handle);
    }

    public function sourceById(?int $id): ?Source
    {
        if (!$id) return null;
        return Vouch::getInstance()->sources->getSourceById($id);
    }

    /**
     * @return array<string, ConnectorInterface>
     */
    public function providers(): array
    {
        return Vouch::getInstance()->providers->all();
    }

    public function pluginName(): string
    {
        return Vouch::getInstance()->getSettings()->pluginName;
    }

    /**
     * Convenience for landing pages: the running average across all approved
     * reviews from all enabled sources. Returns null when there are none.
     */
    public function averageRating(?int $sourceId = null): ?float
    {
        $query = Review::find()->approved(true);
        if ($sourceId) {
            $query->sourceId($sourceId);
        }
        $reviews = $query->all();
        if (!$reviews) return null;
        $sum = array_sum(array_map(fn(Review $r) => $r->rating, $reviews));
        return $sum / count($reviews);
    }
}
