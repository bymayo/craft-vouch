<?php

namespace bymayo\vouch\variables;

use bymayo\vouch\connectors\ConnectorInterface;
use bymayo\vouch\elements\db\ReviewQuery;
use bymayo\vouch\elements\Review;
use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;

/**
 * Twig API for Vouch - exposed as `craft.vouch.*`.
 *
 *  {% set best = craft.vouch.reviews().approved(true).rating('>= 4').all() %}
 */
class VouchVariable
{
    /**
     * Returns a chainable Review query - same shape as `craft.entries()` etc.
     * Defaults to approved-only so pending-moderation reviews never leak onto
     * the front-end. Pass `.approved(false)` (or `.approved(null)` for both)
     * to opt out.
     */
    public function reviews(): ReviewQuery
    {
        return Review::find()->approved(true);
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

    /**
     * Average approved rating across all sources for a specific element
     * (entry, product, etc.). Use on a PDP / detail page:
     *
     *   {% set rating = craft.vouch.ratingForElement(entry.id) %}
     */
    public function ratingForElement(?int $elementId): ?float
    {
        if (!$elementId) return null;
        return Vouch::getInstance()->reviews->averageRatingForElement($elementId);
    }

    /**
     * Per-source rating + count for an element. Returns an array of rows:
     *
     *   [{ sourceId, sourceName, providerHandle, average, count }, ...]
     *
     * Useful for rendering a "Google: 4.5 (8), Trustpilot: 3.8 (7)" line on
     * the front-end alongside the overall average.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ratingBreakdownForElement(?int $elementId): array
    {
        if (!$elementId) return [];
        return Vouch::getInstance()->reviews->ratingBreakdownForElement($elementId);
    }
}
