# Vouch for Craft CMS

Pull and manage reviews from Google, Trustpilot, Feefo and Reviews.io directly inside Craft. Also supports manually-authored reviews via the CP or front-end forms.

> Status: pre-release. v5.0.0 ships the foundations — connectors, sync orchestration, manual reviews, element-index rating roll-up, Twig/GraphQL.

## Requirements

- Craft CMS 5.6.0+
- PHP 8.2+

## Providers in v1

| Provider | Read | Write | Auth |
|---|---|---|---|
| Google Reviews | Yes (capped at 5 most recent by the Places API) | No | API key |
| Trustpilot | Yes (public Business Units API) | No (push is Phase 6) | API key + Business Unit ID |
| Feefo | Yes (Reviews API v20) | No | Merchant identifier + optional API key |
| Reviews.io | Yes (Merchant Reviews API) | No | Store ID + API key |
| Manual | Authored in the CP or via front-end form submission | n/a | n/a |

All credential fields support `$ENV_VAR` references and are resolved at use-time via `App::parseEnv()`.

## Settings

Settings live in Project Config (so they sync via `project.yaml`) and can be overridden per environment by `config/vouch.php`. Edit them at **Settings → Plugins → Vouch** in the CP.

| Setting | Default | What it does |
|---|---|---|
| `pluginName` | `Vouch` | Display name in the CP nav. |
| `matchAuthorsToUsers` | `true` | Match reviewer emails to existing Craft users. |
| `emailRetentionDays` | `365` | Days to keep reviewer emails before purging. `0` = never. |
| `backfillDays` | `90` | Days of history to pull on a source's first sync. `0` = all. |
| `autoApproveThreshold` | `5.0` | When a source requires manual approval, reviews at or above this rating skip the queue. |

## Sync

Sync is driven by cron — there's no per-source schedule setting. Common setups:

```bash
# Recommended: cron drives cadence, queue runner handles execution
0 * * * *  php craft vouch/sync/all       # enqueue every enabled source hourly
* * * * *  php craft queue/run            # process the queue continuously

# No queue worker, runs inline (slow sources block the cron):
0 4 * * *  php craft vouch/sync/all --sync

# Per-source cadence — multiple cron entries instead of in-app schedule:
0 * * * *  php craft vouch/sync/source google-uk
0 4 * * *  php craft vouch/sync/source trustpilot-main
```

The "Sync" button on each row of the Sources index runs synchronously for ad-hoc pulls.

## Twig

```twig
{# Latest 5 approved reviews from any source #}
{% for review in craft.vouch.reviews().limit(5).all() %}
  <article>
    <h3>{{ review.headline ?: review.reviewerName }}</h3>
    <p>{{ review.rating }}★ — {{ review.reviewedAt|date('M j, Y') }}</p>
    <blockquote>{{ review.review }}</blockquote>
  </article>
{% endfor %}

{# Filter by source + rating threshold #}
{% set google = craft.vouch.source('google-uk') %}
{% set positive = craft.vouch.reviews().sourceId(google.id).rating('>= 4').all() %}

{# Site-wide average #}
<p>Average rating: {{ craft.vouch.averageRating()|number_format(1) }}★</p>

{# Average for a specific element (entry / Commerce product) #}
{% set rating = craft.vouch.ratingForElement(entry.id) %}
{% if rating %}
  <p>{{ rating|number_format(1) }}★ across all sources</p>
{% endif %}

{# Per-source breakdown for an element #}
{% for row in craft.vouch.ratingBreakdownForElement(entry.id) %}
  <li>{{ row.sourceName }}: {{ row.average|number_format(1) }}★ ({{ row.count }})</li>
{% endfor %}
```

## GraphQL

```graphql
{
  vouchReviews(minRating: 4, limit: 10) {
    id
    rating
    headline
    review
    reviewerName
    reviewedAt
    providerHandle
    sourceName
  }
}
```

Public GraphQL queries default to `approved: true` so pending-moderation reviews never leak. Admins can override with `approved: false` when needed.

## Front-end review submissions (Manual sources only)

```twig
<form method="post">
  {{ csrfInput() }}
  <input type="hidden" name="action" value="vouch/reviews/submit">
  <input type="hidden" name="sourceHandle" value="customer-reviews">

  {# Optional: tie the review to a specific entry / product #}
  <input type="hidden" name="relatedElementId" value="{{ entry.id ?? '' }}">

  <label for="vouch-rating">Rating *</label>
  <select id="vouch-rating" name="rating" required>
    <option value="">Choose a rating…</option>
    <option value="5">5 ★★★★★</option>
    <option value="4">4 ★★★★</option>
    <option value="3">3 ★★★</option>
    <option value="2">2 ★★</option>
    <option value="1">1 ★</option>
  </select>

  <label for="vouch-headline">Headline *</label>
  <input id="vouch-headline" name="headline" required>

  <label for="vouch-review">Review *</label>
  <textarea id="vouch-review" name="review" required></textarea>

  <label for="vouch-reviewer-name">Reviewer name *</label>
  <input id="vouch-reviewer-name" name="reviewerName" required>

  <label for="vouch-reviewer-email">Reviewer email *</label>
  <input id="vouch-reviewer-email" name="reviewerEmail" type="email" required>

  <button type="submit">Submit review</button>
</form>
```

**Required:** `sourceHandle`, `rating`, `headline`, `review`, `reviewerName`, `reviewerEmail`.
**Optional:** `relatedElementId` (ties the review to a specific entry / product).

Submissions are only accepted against Manual sources — front-end forms can't write into API-backed sources (which would bypass the provider's own moderation).

## Element-index rating column

Entries and Commerce Products both gain an opt-in "Rating" column showing the average across all approved reviews related to that element. Enable it via the column settings on the element index. The entry/product edit page also gains a sidebar summary with the overall average + per-source breakdown.

## Events (for downstream integrations)

```php
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\services\Reviews;
use yii\base\Event;

Event::on(
    Reviews::class,
    Reviews::EVENT_AFTER_APPROVE_REVIEW,
    function (ReviewApprovalEvent $event) {
        // $event->review  — the Review element
        // $event->source  — the Source the review came from
        // $event->auto    — true if approved on sync, false if manually approved
    }
);
```

Available events:

- `Reviews::EVENT_AFTER_SYNC_REVIEW` — every successful upsert (with `isNew` flag).
- `Reviews::EVENT_AFTER_APPROVE_REVIEW` — exactly once per review when it becomes approved.
- `Sync::EVENT_BEFORE_SOURCE_SYNC` — cancellable; `$event->cancelled = true` skips the run.
- `Sync::EVENT_AFTER_SOURCE_SYNC` — carries the `SyncResult`.

## Adding your own provider

Implement `bymayo\vouch\connectors\ConnectorInterface` (or extend `BaseConnector` for sensible defaults) and register it via:

```php
use bymayo\vouch\events\RegisterProvidersEvent;
use bymayo\vouch\services\ProviderRegistry;

Event::on(
    ProviderRegistry::class,
    ProviderRegistry::EVENT_REGISTER_PROVIDERS,
    function (RegisterProvidersEvent $event) {
        $event->types[] = MyConnector::class;
    },
);
```

Drop a brand SVG into your plugin and return its markup from `icon()` (or copy `BaseConnector::loadIcon()`'s pattern with your own resource path).
