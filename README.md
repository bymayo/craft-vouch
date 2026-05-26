# Vouch for Craft CMS

Pull and manage reviews from Google, Trustpilot, Feefo and Reviews.io directly inside Craft. Also supports manually-authored reviews via the CP or front-end forms.

> Status: pre-release. v5.0.0 ships the foundations - connectors, sync orchestration, manual reviews, element-index rating roll-up, Twig/GraphQL.

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

All credential fields support `$ENV_VAR` references and are resolved at use-time via `App::parseEnv()`. Recommended: keep secrets in `.env` and reference them like `$GOOGLE_PLACES_API_KEY`, so production credentials never end up in `project.yaml`.

## Source setup

Each source in Vouch maps to one credential set against a specific provider. Source records live in the `{{%vouch_sources}}` table, **not** in Project Config - this is deliberate, so an admin can rotate API keys on production without a deploy clobbering them.

Add a source via **Vouch → Sources → New source**, pick the provider tile, fill in the credentials, and hit Save. A "Test connection" check runs automatically on the source edit page so you'll know straight away if the credentials are wrong.

### Google Reviews

Backed by the [Places API (New)](https://developers.google.com/maps/documentation/places/web-service/overview).

1. Open the [Google Cloud Console](https://console.cloud.google.com) and create (or select) a project.
2. Enable the **Places API (New)** under **APIs & Services → Library**.
3. Create an API key: **APIs & Services → Credentials → Create credentials → API key**.
4. **Strongly recommended:** restrict the key. Under the key's settings, set "Application restrictions" to your server IP(s) and "API restrictions" to Places API (New) only - that way a leaked key can't be re-used for billed Maps calls.
5. In Vouch, on the new-source page paste the API key into the "API key" field.
6. Use the built-in **"Find a Place ID"** search box on the same page - type the business name (e.g. *"Spicy Vegetarian Food in Sydney"*) and click **Search**. Vouch proxies the [Places Text Search](https://developers.google.com/maps/documentation/places/web-service/text-search) endpoint server-side and lists matching candidates with their name, address, and Place ID. Clicking a result auto-fills the Place ID field above. The API key never round-trips to Google directly from the browser - the request is signed by Vouch's controller.

   You can also paste a Place ID manually if you already have one from Google's [Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id).

> Google's Places API caps every request at **the 5 most recent reviews**. There's no way to page past that - it's an upstream constraint, not a Vouch limitation. Sync still runs idempotently (dedup by `(sourceId, externalId)`), so the same 5 reviews are upserted (not duplicated) on each pull. The global `backfillDays` setting still applies on first sync: if a place's most recent reviews are all older than the backfill window, you'll see "0 new, 0 updated". **Set `backfillDays` to `0` for Google sources** (unlimited history) since the upstream 5-review cap already keeps the cost bounded.

### Trustpilot

Backed by the public [Business Units API](https://developers.trustpilot.com/business-units-api).

1. Sign in to [Trustpilot Business](https://business.trustpilot.com).
2. Generate an API key under your account's API / Integrations settings. The public tier is sufficient for pulling reviews of your own business unit.
3. Find your **Business Unit ID**. Easiest is a one-off curl using your new key:
   ```bash
   curl "https://api.trustpilot.com/v1/business-units/find?name=yourdomain.com" \
     -H "apikey: YOUR_API_KEY"
   ```
   The `id` field in the response is the Business Unit ID.
4. In Vouch, paste both into the Trustpilot source edit page.

Trustpilot returns reviews in newest-first order; Vouch paginates with cursor early-exit, so on subsequent syncs only the new pages are walked.

### Feefo

Backed by the [Reviews API v20](https://api.feefo.com/api/docs).

1. Your **Merchant identifier** is the slug you log into Feefo with (visible in your merchant dashboard URL).
2. An **API key** is optional. Public review data flows without one; private/PII fields (e.g. customer email) require a paid plan and a bearer key. Request the key from your Feefo account manager if you need it.
3. In Vouch, paste the merchant identifier and (optionally) the API key into the Feefo source edit page.

If the API key is omitted, Vouch falls back to the unauthenticated endpoint, so reviewer emails come through as `null` and user-matching won't fire.

### Reviews.io

Backed by the [Merchant Reviews API](https://developer.reviews.co.uk/).

1. Sign in to [your Reviews.io merchant dashboard](https://dash.reviews.io).
2. Go to **Integrations → API**. Copy your **Store ID** (your merchant code) and your **API key**.
3. In Vouch, paste both into the Reviews.io source edit page.

Reviews.io passes the reviewer email through when present, so user-matching works out of the box (subject to the `matchAuthorsToUsers` setting).

### Manual

No external credentials. Add a Manual source if you want to:

- Author reviews directly in the CP (the **+ New review** button on the reviews index).
- Collect reviews via a front-end form (see [Front-end review submissions](#front-end-review-submissions-manual-sources-only) below).

Manual sources can have `Require manual approval` toggled on, just like API-backed sources - moderation still respects the global `autoApproveThreshold`.

## Settings

Settings live in Project Config (so they sync via `project.yaml`) and can be overridden per environment by `config/vouch.php`. Edit them at **Settings → Plugins → Vouch** in the CP.

| Setting | Default | What it does |
|---|---|---|
| `pluginName` | `Vouch` | Display name in the CP nav. |
| `matchAuthorsToUsers` | `true` | Match reviewer emails to existing Craft users. |
| `emailRetentionDays` | `365` | Days to keep reviewer emails before purging. `0` = never. |
| `backfillDays` | `90` | Days of history to pull on a source's first sync. `0` = all. |
| `autoApproveThreshold` | `5.0` | When a source requires manual approval, reviews at or above this rating skip the queue. |
| `requireLoginForKnownEmails` | `true` | Reject front-end submissions whose email matches an existing Craft user, unless the submitter is logged in as that user. Blocks email-spoofed spam reviews. |
| `headlineMaxLength` | `120` | Max characters allowed in a manual review's headline. `0` disables. Synced provider data is exempt. |
| `reviewMaxLength` | `2000` | Max characters allowed in a manual review body. `0` disables. Synced provider data is exempt. |

## Permissions

Vouch registers a permission set per user group (Settings → Users → Groups, then edit a group). The Vouch heading respects the configured `pluginName` so a CP rename flows through.

| Permission | What it grants |
|---|---|
| **View reviews** | Read-only access to the reviews element index, edit page, and the "Reviews" tab on user edit pages. Required for everything nested under it. |
| ↳ Create reviews | The "+ New review" button + the `vouch/reviews/save` action on new reviews. |
| ↳ Edit reviews | Editing existing reviews (the Approved lightswitch and any other writable fields). Does **not** allow approving via the dedicated action. |
| ↳ Delete reviews | The Delete element-action and the `vouch/reviews/delete` controller action. |
| **Approve pending reviews** | Top-level on purpose so you can grant it to a moderator role without giving them edit/delete on review content. Gates the single-row Approve button **and** the bulk Approve element-index action. |
| **View sources** | Read-only access to the Sources index + edit page. Required for everything nested under it. |
| ↳ Create sources | The "+ New source" tiles on the sources index and saving a new source via `vouch/sources/save`. Also gates the "Find a Place ID" helper. |
| ↳ Edit sources | Saving changes to an existing source. Also gates the "Find a Place ID" helper. |
| ↳ Delete sources | Deleting a source from the Sources index. |
| ↳ Trigger sync | The per-row "Sync" button on the sources index, the "Test connection" check on the source edit page, and the `vouch/sync/source` / `vouch/sync/all` console commands. |
| **Use dashboard widgets** | Whether Vouch's dashboard widgets (Pending Approval, Latest Reviews, Top Reviewed Elements) appear in the widget picker. Independent of view-reviews so you can hide widgets from a role that still has full review access (or vice versa). |
| **Manage settings** | Access to Settings → Plugins → Vouch. |

Each is independent. Common combinations:

- **Pure moderator** - `View reviews` + `Approve pending reviews`. Can see everything and approve, can't edit content or delete.
- **Content author** - `View reviews` + `Create reviews` + `Edit reviews`. Can author and edit, can't approve their own work.
- **Sync operator** - `View sources` + `Trigger sync`. Can re-run failed pulls without being able to rotate API keys or delete sources.
- **Source manager** - `View sources` + `Create sources` + `Edit sources` (no delete). Sets up new providers + rotates credentials, but can't accidentally remove a source.

## Sync

Sync is driven by cron - there's no per-source schedule setting. Common setups:

```bash
# Recommended: cron drives cadence, queue runner handles execution
0 * * * *  php craft vouch/sync/all       # enqueue every enabled source hourly
* * * * *  php craft queue/run            # process the queue continuously

# No queue worker, runs inline (slow sources block the cron):
0 4 * * *  php craft vouch/sync/all --sync

# Per-source cadence - multiple cron entries instead of in-app schedule:
0 * * * *  php craft vouch/sync/source google-uk
0 4 * * *  php craft vouch/sync/source trustpilot-main
```

The "Sync" button on each row of the Sources index runs synchronously for ad-hoc pulls.

## Twig

`craft.vouch.reviews()` returns a chainable query that **defaults to approved-only** - pending-moderation reviews never leak onto the front-end. Pass `.approved(false)` to query pending instead, or `.approved(null)` to include both.

```twig
{# Latest 5 approved reviews from any source #}
{% for review in craft.vouch.reviews().limit(5).all() %}
  <article>
    <h3>{{ review.headline ?: review.reviewerName }}</h3>
    <p>{{ review.rating }} ★ - {{ review.reviewedAt|date('M j, Y') }}</p>
    <blockquote>{{ review.review }}</blockquote>
  </article>
{% endfor %}

{# Filter by source + rating threshold #}
{% set google = craft.vouch.source('google-uk') %}
{% set positive = craft.vouch.reviews().sourceId(google.id).rating('>= 4').all() %}

{# Include pending reviews too (e.g. an admin moderation dashboard) #}
{% set everything = craft.vouch.reviews().approved(null).all() %}

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

Each `Review` element exposes convenience getters so you don't need to chain through `review.source`:

- `review.sourceName` - the source's display name (e.g. "Google UK")
- `review.sourceHandle` - the source's machine handle
- `review.providerHandle` - the connector handle (`google`, `trustpilot`, `feefo`, `reviewsio`, `manual`)
- `review.getReviewerUser()` - the Craft `User` element when the reviewer's email matched an account, otherwise `null`

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
{# `review` and `requiresLogin` are populated by the controller when validation
   fails so the form re-renders with the user's input + per-field errors. #}
{% set vouchSettings = craft.app.plugins.getPlugin('vouch').getSettings() %}

<form method="post">
  {{ csrfInput() }}
  <input type="hidden" name="action" value="vouch/reviews/submit">
  <input type="hidden" name="sourceHandle" value="customer-reviews">

  {# Optional: tie the review to a specific entry / product #}
  <input type="hidden" name="relatedElementId" value="{{ entry.id ?? '' }}">

  {# Flash error banner - covers controller-level failures (e.g. unknown source). #}
  {% set errorMsg = craft.app.session.getFlash('error') %}
  {% if errorMsg %}
    <p class="error" role="alert">
      {{ errorMsg }}
      {% if requiresLogin ?? false %}
        <a href="{{ loginUrl ?? siteUrl('login') }}">{{ 'Log in'|t }}</a>
      {% endif %}
    </p>
  {% endif %}

  <label for="vouch-rating">Rating *</label>
  <select id="vouch-rating" name="rating" required>
    <option value="">Choose a rating…</option>
    <option value="5">5 ★★★★★</option>
    <option value="4">4 ★★★★</option>
    <option value="3">3 ★★★</option>
    <option value="2">2 ★★</option>
    <option value="1">1 ★</option>
  </select>
  {% for err in (review.getErrors('rating') ?? []) %}<p class="error">{{ err }}</p>{% endfor %}

  <label for="vouch-headline">Headline *</label>
  <input id="vouch-headline" name="headline" value="{{ review.headline ?? '' }}"
         {% if vouchSettings.headlineMaxLength > 0 %}maxlength="{{ vouchSettings.headlineMaxLength }}"{% endif %} required>
  {% for err in (review.getErrors('headline') ?? []) %}<p class="error">{{ err }}</p>{% endfor %}

  <label for="vouch-review">Review *</label>
  <textarea id="vouch-review" name="review"
            {% if vouchSettings.reviewMaxLength > 0 %}maxlength="{{ vouchSettings.reviewMaxLength }}"{% endif %} required>{{ review.review ?? '' }}</textarea>
  {% for err in (review.getErrors('review') ?? []) %}<p class="error">{{ err }}</p>{% endfor %}

  <label for="vouch-reviewer-name">Reviewer name *</label>
  <input id="vouch-reviewer-name" name="reviewerName" value="{{ review.reviewerName ?? '' }}" required>
  {% for err in (review.getErrors('reviewerName') ?? []) %}<p class="error">{{ err }}</p>{% endfor %}

  <label for="vouch-reviewer-email">Reviewer email *</label>
  <input id="vouch-reviewer-email" name="reviewerEmail" type="email" value="{{ review.reviewerEmail ?? '' }}" required>
  {% for err in (review.getErrors('reviewerEmail') ?? []) %}<p class="error">{{ err }}</p>{% endfor %}

  <button type="submit">Submit review</button>
</form>
```

**Required:** `sourceHandle`, `rating`, `headline`, `review`, `reviewerName`, `reviewerEmail`.
**Optional:** `relatedElementId` (ties the review to a specific entry / product).

Submissions are only accepted against Manual sources - front-end forms can't write into API-backed sources (which would bypass the provider's own moderation).

### Attribution & spam controls

`reviewerEmail` is captured for moderation / contact, but Vouch will **only attribute** a review to a Craft user (`reviewerUserId`) when the submitter is logged in AND the email they submit matches the email on their own account. This blocks the forge-by-email attack where an anonymous user submits with someone else's email to attribute the review to them.

Even with attribution locked down, an anonymous attacker can still *plant* a review under a real user's email - the row exists, the email is visible to admins, and depending on your retention policy it lives on the record. To stop that, turn on **`requireLoginForKnownEmails`**: when the submitted email belongs to an existing Craft user and the submitter isn't logged in as them, the controller returns `403` and your form can redirect to login.

The response is JSON when `Accept: application/json` is set:

```json
{
  "ok": false,
  "requiresLogin": true,
  "loginUrl": "https://example.com/login",
  "message": "That email belongs to a registered account. Please log in to leave a review."
}
```

For a non-JSON submit, the controller flashes an error message and sets a `requiresLogin` route param the template can read.

If you want every front-end review verified before it goes live, layer on the usual defences:

- Turn on **"Require manual approval"** on the source so reviews land Pending until an admin approves them.
- Restrict the submit form to logged-in users (`{% requireLogin %}` at the top of the Twig template, or check `currentUser` before rendering the form).
- For anonymous-allowed forms, add the usual public-form defences: a honeypot field, hCaptcha / reCAPTCHA, rate limiting (Craft's `RateLimitTrait`, a CDN rule, or the [`putyourlightson/craft-rate-limit`](https://github.com/putyourlightson/craft-rate-limit) plugin), and a server-side email format check before the form even submits.
- Use the `EVENT_AFTER_SYNC_REVIEW` hook to run your own spam scoring (Akismet, OOPSpam, etc.) and flip `$review->approved = false` for anything suspicious.

The sync path (Google / Trustpilot / Feefo / Reviews.io) does still auto-match emails to Craft users - those emails come from the provider, not anonymous user input, so the same trust concern doesn't apply.

## Element-index rating column

Entries and Commerce Products both gain an opt-in "Rating" column showing the average across all approved reviews related to that element. Enable it via the column settings on the element index. The entry/product edit page also gains a sidebar summary with the overall average + per-source breakdown.

## Users integration

- **"Reviews" column** on the Users element index showing how many approved reviews each user has authored (matched via `reviewerUserId`).
- **Reviews screen** on the user edit page (the same place Commerce adds its "Commerce" tab). Embeds the reviews element index pre-filtered to that user. The sidebar label uses the configured `pluginName`. Visible to users with `vouch-viewReviews`.

## Reviews index actions

The reviews element index ships with a built-in **Bulk Approve** action - select any number of pending reviews and approve them in one go. The action skips already-approved rows and fires `EVENT_AFTER_APPROVE_REVIEW` exactly the same way single-row approvals do, so downstream listeners (Points, notifications, etc.) work consistently across paths. Visible to users with the `vouch-editReviews` permission.

## Dashboard widgets

Three dashboard widgets ship with the plugin - add them from **Dashboard → "+ New widget"**. Widget display names use the configured `pluginName` so they show "{YourPluginName} - Reviews Pending Approval", etc.

| Widget | What it shows | Settings |
|---|---|---|
| **Reviews Pending Approval** | Reviews awaiting moderation with title, rating, reviewer + date. Footer "View all pending reviews" links to the pending source on the reviews index. | `limit` |
| **Latest Reviews** | The most recent approved reviews. Reviewer name links to the matched Craft user when available. | `limit`, `sourceId` (filter to one source, or "Any source") |
| **Top Reviewed Elements** | Ranks elements by review count or average rating. First column header reads the element type's display name ("Entry", "Product", etc.). | `elementType` (Entries / Assets / Categories / Users / Commerce Products), `sectionId` (only shown when Entries selected), `sort` (Most reviews / Highest rating), `limit` |

All three require the `vouch-viewReviews` permission.

## Events (for downstream integrations)

```php
use bymayo\vouch\events\ReviewApprovalEvent;
use bymayo\vouch\services\Reviews;
use yii\base\Event;

Event::on(
    Reviews::class,
    Reviews::EVENT_AFTER_APPROVE_REVIEW,
    function (ReviewApprovalEvent $event) {
        // $event->review  - the Review element
        // $event->source  - the Source the review came from
        // $event->auto    - true if approved on sync, false if manually approved
    }
);
```

Available events:

- `Reviews::EVENT_AFTER_SYNC_REVIEW` - every successful upsert (with `isNew` flag).
- `Reviews::EVENT_AFTER_APPROVE_REVIEW` - exactly once per review when it becomes approved.
- `Sync::EVENT_BEFORE_SOURCE_SYNC` - cancellable; `$event->cancelled = true` skips the run.
- `Sync::EVENT_AFTER_SOURCE_SYNC` - carries the `SyncResult`.

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
