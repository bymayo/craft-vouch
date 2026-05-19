# Vouch for Craft CMS

Pull and manage reviews from Google, Trustpilot, Feefo and Reviews.io directly inside Craft CMS.

> Status: pre-release. v5.0.0 ships the foundations — Source CRUD, Review element type, connector framework. Provider connectors land in the following phases.

## Requirements

- Craft CMS 5.6.0+
- PHP 8.2+

## Usage

### Twig

```twig
{# Latest 5 approved reviews #}
{% for review in craft.vouch.reviews().limit(5).all() %}
  <article>
    <h3>{{ review.title ?: review.authorName }}</h3>
    <p>{{ review.rating }}★ — {{ review.reviewedAt|date('M j, Y') }}</p>
    <blockquote>{{ review.body }}</blockquote>
  </article>
{% endfor %}

{# Filter by source + rating threshold #}
{% set google = craft.vouch.source('google-uk') %}
{% set positive = craft.vouch.reviews().sourceId(google.id).rating('>= 4').all() %}

{# Quick stats for a landing page #}
<p>Average rating: {{ craft.vouch.averageRating()|number_format(1) }}★</p>
```

### GraphQL

```graphql
{
  vouchReviews(minRating: 4, limit: 10) {
    id
    rating
    title
    body
    authorName
    reviewedAt
    providerHandle
    sourceName
  }
}
```

The GraphQL surface defaults to `approved: true` so pending-moderation reviews never leak into front-end queries unless an admin explicitly opts in via `approved: false`.

## Roadmap

- **Phase 1** (current) — Scaffold, data model, CP UI foundations.
- **Phase 2** — Google Reviews connector (Places API).
- **Phase 3** — Trustpilot, Feefo, Reviews.io connectors.
- **Phase 4** — Sync orchestration: queue jobs, console commands, scheduling, moderation.
- **Phase 5** — Events + Points trigger contract (integrates with [bymayo/craft-points](https://github.com/bymayo/craft-points)).
- **Phase 6** — Push: send review invites, post reviews back where APIs support it.
- **Phase 7** — Front-end: Twig element queries + GraphQL.
- **Phase 8** — Plugin Store release.
