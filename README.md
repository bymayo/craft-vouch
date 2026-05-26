<img src="https://raw.githubusercontent.com/bymayo/craft-vouch/main/src/icon.svg" width="70">

# Vouch for Craft CMS

Pull and manage customer reviews from Google, Trustpilot, Feefo, and Reviews.io directly inside Craft - or collect your own through the CP and front-end forms. Surface ratings on entries and products, gate submissions with built-in spam controls, and roll the data up across the site through Twig, GraphQL, and dashboard widgets.

<img src="https://raw.githubusercontent.com/bymayo/craft-vouch/main/resources/screenshot.png" width="850">

## Features

- **Multi-provider connectors**: Google (Places API + Business Profile OAuth), Trustpilot, Feefo, Reviews.io, plus a Manual source for CP-authored and front-end-submitted reviews
- **Renameable**: rebrand the plugin sidebar, widgets, permissions heading - "Reviews", "Testimonials", "VIP Feedback", whatever fits the site
- **Per-source moderation**: require manual approval, set a minimum rating, configure auto-approve thresholds
- **Front-end submission form**: anonymous-friendly with CSRF, validation errors, configurable length caps, login-gated email validation, and an attribution-forgery defence
- **Element-index integration**: Rating column on Entries / Commerce Products, Reviews count column on Users, and a "Reviews" tab on the user edit page (just like Commerce's "Commerce" tab)
- **Dashboard widgets**: Reviews Pending Approval, Latest Reviews, Top Reviewed Elements
- **Bulk approve** action on the reviews element index for moderation workflows
- **Granular permissions**: View / Create / Edit / Delete / Approve / Sync each surfaced separately
- **Developer APIs**: chainable Twig query, GraphQL types and queries, public events for downstream integrations (Points, notifications, spam scoring)
- **Sync orchestration**: cron-driven, queue-aware, with a per-source sync button and CLI commands
- **Find Place ID**: built-in Google Places Text Search helper - paste an API key, type a name, click a result to fill in the Place ID
- **Encrypted credentials**: API keys and OAuth refresh tokens stored encrypted via Craft's security key
- **Extension events**: register your own provider connector to plug in any third-party review platform

## Contents

- [Install](#install)
- [Requirements](#requirements)
- [Providers](#providers)
- [Setting up a source](#setting-up-a-source)
  - [Google Reviews](#google-reviews)
  - [Trustpilot](#trustpilot)
  - [Feefo](#feefo)
  - [Reviews.io](#reviewsio)
  - [Manual](#manual)
- [Sync](#sync)
- [Front-end review submissions](#front-end-review-submissions)
- [Attribution & spam controls](#attribution--spam-controls)
- [Element-index integration](#element-index-integration)
- [Users integration](#users-integration)
- [Dashboard widgets](#dashboard-widgets)
- [Reviews index actions](#reviews-index-actions)
- [Settings](#settings)
- [Permissions](#permissions)
- [Twig](#twig)
- [GraphQL](#graphql)
- [Events](#events)
- [Adding your own provider](#adding-your-own-provider)
- [Support](#support)

## Install

- Install with Composer via `composer require bymayo/craft-vouch` from your project directory
- Enable / Install the plugin in the Craft Control Panel under `Settings > Plugins`

You can also install the plugin via the Plugin Store in the Craft Admin CP by searching for `Vouch`.

## Requirements

- Craft CMS 5.6+
- PHP 8.2+

## Providers

| Provider | Backed by | Auth |
|---|---|---|
| Google Reviews | Places API (New) or Business Profile API | API key, or OAuth 2.0 |
| Trustpilot | Public Business Units API | API key + Business Unit ID |
| Feefo | Reviews API v20 | Merchant identifier + optional API key |
| Reviews.io | Merchant Reviews API | Store ID + API key |
| Manual | Authored in the CP or via front-end form | n/a |

All credential fields support `$ENV_VAR` references resolved via `App::parseEnv()`. Keep secrets in `.env` and reference them like `$GOOGLE_PLACES_API_KEY` so production credentials never end up in `project.yaml`.

## Setting up a source

Each source maps one credential set to one provider. Source records live in their own DB table (not Project Config), so admins can rotate API keys on production without a deploy overwriting them.

Add a source via **Vouch → Sources → New source**, pick the provider, fill in the credentials, and Save. A "Test connection" check runs automatically on the edit page so credentials are validated immediately.

### Google Reviews

Google sources have a **Mode** dropdown:

- **Places API** - works for any place; capped at 5 reviews per call by Google
- **Business Profile API** - works only for businesses you (or the OAuth-connecting account) own/manage. Returns full review history with pagination. Requires Google partner approval - see below.

#### Places API mode

1. Open the [Google Cloud Console](https://console.cloud.google.com) and create (or select) a project.
2. Enable the **Places API (New)** under **APIs & Services → Library**.
3. Create an API key under **APIs & Services → Credentials → Create credentials → API key**.
4. **Strongly recommended:** restrict the key. Under the key's settings, set "Application restrictions" to your server IP(s) and "API restrictions" to Places API (New) only - a leaked key then can't be re-used for billed Maps calls.
5. In Vouch, leave **Mode** on "Places API", paste the API key, then use the built-in **"Find a Place ID"** search box - type a company name, address, or zip code and click **Search**. Vouch proxies Google's Text Search endpoint server-side and lists matching candidates. Clicking one auto-fills the Place ID field.

   You can also paste a Place ID manually if you already have one from Google's [Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id).

> Google's Places API caps every request at **the 5 most recent reviews**. There's no way to page past that - it's an upstream constraint. Sync runs idempotently (dedup by `(sourceId, externalId)`), so the same 5 reviews are upserted (not duplicated) on each pull. **Set `backfillDays` to `0` for Places-mode sources** (unlimited history) since the upstream cap already keeps the cost bounded.

#### Business Profile API mode

> ⚠ **Google gatekeeps this endpoint.** Around 2023 Google restricted programmatic access to reviews on the Business Profile API. To use this mode in production:
>
> 1. Apply via [Google's Business Profile API form](https://support.google.com/business/contact/api_default).
> 2. Get an OAuth 2.0 consent screen verified by Google (its own review process if your scope is "sensitive").
> 3. Wait for approval - typically weeks; first-time applicants are often rejected.
>
> Without approval the OAuth flow completes but the reviews endpoint returns `403 PERMISSION_DENIED`. Vouch surfaces that error verbatim in the "Test connection" status.

Once approved:

1. Enable the **My Business Account Management API**, **My Business Business Information API**, and the **Business Profile API** in Google Cloud Console.
2. Create an **OAuth 2.0 Client ID** of type "Web application". Add `https://<your-site>/admin/actions/vouch/sources/google-oauth-callback` to "Authorized redirect URIs". Note the Client ID and Client Secret.
3. In Vouch: **Add source → Google Reviews**, switch **Mode** to "Business Profile API", paste the Client ID + Client Secret. Save.
4. Reload, click **"Connect Google account"**, approve on Google's consent screen. The OAuth callback stores an encrypted refresh token on the source.
5. Fill in the **Location resource name** (`accounts/{accountId}/locations/{locationId}`). List your accounts/locations via the [API explorer](https://developers.google.com/my-business/reference/businessinformation/rest/v1/accounts.locations/list) or `curl` against `https://mybusinessaccountmanagement.googleapis.com/v1/accounts`. Save again.
6. Hit **"Test connection"** to confirm.

The refresh token is stored encrypted (same mechanism as every other API credential). Access tokens are minted fresh on each sync - never persisted beyond a single request.

### Trustpilot

1. Sign in to [Trustpilot Business](https://business.trustpilot.com).
2. Generate an API key under your account's API / Integrations settings. The public tier is enough for pulling reviews of your own business unit.
3. Find your **Business Unit ID** via a one-off curl with your new key:
   ```bash
   curl "https://api.trustpilot.com/v1/business-units/find?name=yourdomain.com" \
     -H "apikey: YOUR_API_KEY"
   ```
   The `id` field in the response is the Business Unit ID.
4. Paste both into the Trustpilot source edit page.

Trustpilot returns reviews newest-first; Vouch paginates with cursor early-exit so subsequent syncs only walk new pages.

### Feefo

1. Your **Merchant identifier** is the slug visible in your Feefo dashboard URL.
2. An **API key** is optional. Public review data flows without one; private fields (e.g. customer email) require a paid plan and a bearer key.
3. Paste the merchant identifier and (optionally) the API key into the Feefo source edit page.

If the API key is omitted, reviewer emails come through as `null` and user-matching won't fire.

### Reviews.io

1. Sign in to [your Reviews.io merchant dashboard](https://dash.reviews.io).
2. **Integrations → API**: copy your **Store ID** (merchant code) and **API key**.
3. Paste both into the Reviews.io source edit page.

Reviewer email is passed through when present, so user-matching works out of the box.

### Manual

No external credentials. Add a Manual source to:

- Author reviews directly in the CP via the **+ New review** button.
- Collect reviews through a front-end form (see [Front-end review submissions](#front-end-review-submissions) below).

Manual sources can still have `Require manual approval` toggled on - moderation respects the same `autoApproveThreshold` setting as API-backed sources.

## Sync

Sync is driven by cron. There's no per-source schedule field in the CP - the cron entry *is* the schedule.

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

The per-row **Sync** button on the Sources index runs synchronously for ad-hoc pulls.

## Front-end review submissions

For Manual sources only. The controller endpoint is `vouch/reviews/submit` (anonymous-allowed) - it hard-rejects non-Manual sources so customer submissions can't bypass Trustpilot/Feefo moderation.

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

  {# Flash error banner - covers controller-level failures #}
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
**Optional:** `relatedElementId`.

## Attribution & spam controls

`reviewerEmail` is captured for moderation / contact, but Vouch will **only attribute** a review to a Craft user (`reviewerUserId`) when the submitter is logged in AND the email they submit matches the email on their own account. This blocks the forge-by-email attribution attack.

Even with attribution locked down, an anonymous attacker could still *plant* a review under a real user's email. To stop that, the `requireLoginForKnownEmails` setting (on by default) makes the controller reject any submission whose email belongs to an existing Craft user unless the submitter is logged in as them. The response is JSON when `Accept: application/json` is set:

```json
{
  "ok": false,
  "requiresLogin": true,
  "loginUrl": "https://example.com/login",
  "message": "That email belongs to a registered account. Please log in to leave a review."
}
```

For HTML submits, the controller flashes an error message and sets a `requiresLogin` route param the template can read.

If you want every front-end review verified before it goes live, layer on the usual defences:

- Turn on **"Require manual approval"** on the source so reviews land Pending until an admin approves.
- Restrict the submit form to logged-in users (`{% requireLogin %}` at the top of the template, or check `currentUser` before rendering).
- For anonymous-allowed forms, add the standard public-form defences: a honeypot, hCaptcha / reCAPTCHA, rate limiting ([`putyourlightson/craft-rate-limit`](https://github.com/putyourlightson/craft-rate-limit) or a CDN rule), and a server-side email format check.
- Use the `EVENT_AFTER_SYNC_REVIEW` event to run your own spam scoring (Akismet, OOPSpam, etc.) and flip `$review->approved = false` for anything suspicious.

The sync path (Google / Trustpilot / Feefo / Reviews.io) still auto-matches emails to Craft users - those emails come from the provider, not anonymous user input, so the same trust concern doesn't apply.

## Element-index integration

Entries and Commerce Products gain an opt-in **"Rating"** column showing the average across all approved reviews related to that element. Enable it via the column settings on the element index. The entry/product edit page also gains a sidebar summary with the overall average + per-source breakdown.

The "Rating" column links a deep-filter back to the reviews index pre-filtered by that element.

## Users integration

- **"Reviews" column** on the Users element index showing how many approved reviews each user has authored (matched via `reviewerUserId`).
- **"Reviews" screen** on the user edit page - the same place Commerce adds its "Commerce" tab. Embeds the reviews element index pre-filtered to that user. The sidebar label uses the configured `pluginName`. Visible to users with `vouch-viewReviews`.

## Dashboard widgets

Add via the Craft dashboard → **+ New widget**. All three respect your `pluginName` rename:

| Widget | Shows | Settings |
|---|---|---|
| **Reviews Pending Approval** | Reviews awaiting moderation with headline, rating, reviewer + date. Footer links to the pending source on the reviews index. | `limit` |
| **Latest Reviews** | The most recent approved reviews. Reviewer name links to the matched Craft user when available. | `limit`, `sourceId` (filter to one source, or any) |
| **Top Reviewed Elements** | Ranks elements by review count or average rating. Column header reflects the chosen element type. | `elementType`, `sectionId` (Entries only), `sort`, `limit` |

All three require the `vouch-viewWidgets` permission.

## Reviews index actions

The reviews element index ships with a built-in **Bulk Approve** action - select any pending reviews and approve them in one go. Skips already-approved rows and fires `EVENT_AFTER_APPROVE_REVIEW` exactly the same way single-row approvals do, so downstream listeners (Points, notifications, etc.) work consistently across paths. Visible to users with `vouch-approveReviews`.

## Settings

Settings can be edited at **Settings → Plugins → Vouch** in the CP, and overridden per environment by `config/vouch.php`.

| Setting | Default | What it does |
|---|---|---|
| `pluginName` | `Vouch` | Display name in the CP sidebar, widget picker, permissions heading. |
| `matchAuthorsToUsers` | `true` | Match reviewer emails to existing Craft users on sync. |
| `emailRetentionDays` | `365` | Days to keep reviewer emails before purging. `0` = never. |
| `backfillDays` | `90` | Days of history to pull on a source's first sync. `0` = all. |
| `autoApproveThreshold` | `5.0` | When a source requires manual approval, reviews at or above this rating skip the queue. |
| `requireLoginForKnownEmails` | `true` | Reject front-end submissions whose email matches an existing Craft user, unless they're logged in as that user. |
| `headlineMaxLength` | `120` | Max characters allowed in a manual review's headline. `0` disables. |
| `reviewMaxLength` | `2000` | Max characters allowed in a manual review body. `0` disables. |

## Permissions

Granular permission set under the **Vouch** heading on each user group's permissions page (heading respects `pluginName`):

```
Vouch
  ▸ View reviews          ↳ Create / Edit / Delete reviews
  ☐ Approve pending reviews
  ▸ View sources          ↳ Create / Edit / Delete sources, Trigger sync
  ☐ Use dashboard widgets
  ☐ Manage settings
```

A few role recipes:

- **Pure moderator** - `View reviews` + `Approve pending reviews`. Can see and approve, can't edit content or delete.
- **Content author** - `View reviews` + `Create reviews` + `Edit reviews`. Can author and edit, can't approve their own work.
- **Sync operator** - `View sources` + `Trigger sync`. Can re-run failed pulls without being able to rotate API keys or delete sources.
- **Source manager** - `View sources` + `Create sources` + `Edit sources` (no delete). Sets up new providers + rotates credentials, but can't accidentally remove a source.

Each permission is independent. Front-end submission endpoints don't use these permissions - they check the source's manual flag + login state per the [Attribution & spam controls](#attribution--spam-controls) above.

## Twig

`craft.vouch.reviews()` returns a chainable query that **defaults to approved-only** - pending-moderation reviews never leak onto the front-end. Pass `.approved(false)` for pending only, or `.approved(null)` for both.

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

{# Site-wide average #}
<p>Average rating: {{ craft.vouch.averageRating()|number_format(1) }} ★</p>

{# Average for a specific element (entry / Commerce product) #}
{% set rating = craft.vouch.ratingForElement(entry.id) %}
{% if rating %}
  <p>{{ rating|number_format(1) }} ★ across all sources</p>
{% endif %}

{# Per-source breakdown for an element #}
{% for row in craft.vouch.ratingBreakdownForElement(entry.id) %}
  <li>{{ row.sourceName }}: {{ row.average|number_format(1) }} ★ ({{ row.count }})</li>
{% endfor %}
```

Convenience getters on the `Review` element:

| Call | Returns |
|---|---|
| `review.sourceName` | The source's display name (e.g. "Google UK") |
| `review.sourceHandle` | The source's machine handle |
| `review.providerHandle` | The connector handle (`google`, `trustpilot`, `feefo`, `reviewsio`, `manual`) |
| `review.getReviewerUser()` | The Craft `User` element when the reviewer's email matched an account, otherwise `null` |

Twig API surface:

| Call | Returns |
|---|---|
| `craft.vouch.reviews()` | Chainable `ReviewQuery` (defaults to `approved(true)`) |
| `craft.vouch.sources()` | All `Source` models |
| `craft.vouch.source(handle)` | A single `Source` by handle |
| `craft.vouch.sourceById(id)` | A single `Source` by id |
| `craft.vouch.providers()` | All registered connectors |
| `craft.vouch.averageRating(sourceId?)` | Site-wide or per-source average rating |
| `craft.vouch.ratingForElement(elementId)` | Average rating across approved reviews for one element |
| `craft.vouch.ratingBreakdownForElement(elementId)` | Per-source rows of `{sourceId, sourceName, providerHandle, average, count}` |
| `craft.vouch.pluginName()` | The configured plugin name |

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

| Query | Args | Returns |
|---|---|---|
| `vouchReviews` | `sourceId`, `rating`, `minRating`, `approved`, `reviewerUserId`, `relatedElementId`, `limit`, `offset` | `[VouchReview]` |
| `vouchReview` | `id: Int!` | `VouchReview` |

## Events

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

| Event | When |
|---|---|
| `Reviews::EVENT_AFTER_SYNC_REVIEW` | Every successful upsert (with `isNew` flag) |
| `Reviews::EVENT_AFTER_APPROVE_REVIEW` | Exactly once per review when it becomes approved |
| `Sync::EVENT_BEFORE_SOURCE_SYNC` | Cancellable - set `$event->cancelled = true` to skip the run |
| `Sync::EVENT_AFTER_SOURCE_SYNC` | Carries the populated `SyncResult` |

## Adding your own provider

Implement `bymayo\vouch\connectors\ConnectorInterface` (or extend `BaseConnector` for sensible defaults) and register it via the `EVENT_REGISTER_PROVIDERS` event:

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

The connector contract:

| Method | Purpose |
|---|---|
| `handle()` | Stable, unique provider handle (e.g. `google`) |
| `displayName()` | Human-readable name |
| `icon()` | SVG markup for the source picker (or `null`) |
| `capabilities()` | Capability flags - `['pull' => true, 'push' => false]` etc. |
| `settingsSchema()` | Field schema for the source edit form |
| `testConnection(Source $source)` | Live credential probe - returns `['ok' => bool, 'message' => string]` |
| `fetchReviews(Source $source, ?\DateTimeInterface $since)` | Yield `FetchedReview` DTOs - the sync service handles dedup, moderation, and persistence |

Implementations yield `FetchedReview` DTOs through `fetchReviews()` so the sync service can stream-write them without holding the full result set in memory.

## Support

If you have any issues (surely not!) I'll aim to reply as soon as possible. If it's a site-breaking-oh-no-what-has-happened moment, hit me up on the Craft CMS Discord - `@bymayo`.
