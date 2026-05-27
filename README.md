<img src="https://raw.githubusercontent.com/bymayo/craft-vouch/main/src/icon.svg" width="70">

# Vouch for Craft CMS 5

Pull customer reviews from Google, Trustpilot, Feefo and Reviews.io (with more on the way) straight into Craft - or collect your own through the CP and a front-end form. Relate ratings to any element (entries, products, users, categories - you name it), block the spam, and render everything through Twig or GraphQL. You can also view and approve reviews straight from your dashboard with built-in widgets.

<img src="https://raw.githubusercontent.com/bymayo/craft-vouch/main/resources/screenshot.png" width="850">

## Features

- **Multiple providers** - Google, Trustpilot, Feefo, Reviews.io (with more on the way), plus a Manual source for CP and front-end submissions
- **Renameable** - call it "Reviews", "Testimonials", "VIP Feedback"… whatever fits the site
- **Per-source moderation** - manual approval, minimum ratings, auto-approve thresholds
- **Front-end submission form** - CSRF, validation, length caps and attribution-forgery defences baked in
- **User matching** - reviews automatically link back to existing Craft users when the email matches an account
- **Element-index integration** - Rating columns on Entries, Commerce Products and Users, plus a "Reviews" tab on the user edit page
- **Dashboard widgets** - Reviews Pending Approval, Latest Reviews, Top Reviewed Elements, and a Sources widget with one-click sync
- **Bulk approve** - tidy up the pending queue in one go
- **Granular permissions** - View / Create / Edit / Delete / Approve / Sync, each on its own switch
- **Developer APIs** - chainable Twig query, GraphQL types and queries, and events for your own integrations
- **Sync orchestration** - cron-driven, queue-aware, with per-source sync buttons and CLI commands

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
- [Front-end review submissions](#front-end-review-submissions)
- [Twig](#twig)
- [Sync](#sync)
- [Attribution & spam controls](#attribution--spam-controls)
- [Element integration](#element-integration)
- [Dashboard widgets](#dashboard-widgets)
- [Settings](#settings)
- [GraphQL](#graphql)
- [Events](#events)
- [Nice to know](#nice-to-know)
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

All credential fields accept `$ENV_VAR` references via `App::parseEnv()`. Keep your secrets in `.env` and reference them like `$GOOGLE_PLACES_API_KEY` so production credentials don't end up in `project.yaml`.

## Setting up a source

A source is a single feed of reviews tied to a provider. You might set up a Google Reviews source to pull in your overall company reviews, a Reviews.io source for product reviews, or a Manual source for testimonials you collect yourself - you can have as many as you like, mixing and matching providers as you go.

Head to **Vouch → Sources → New source**, choose your provider, fill in the credentials and Save. A "Test connection" check runs automatically on the edit page so you'll know straight away if something's off.

### Google Reviews

Google sources have a **Mode** dropdown:

- **Places API** - works for any place, but Google caps you at 5 reviews per call
- **Business Profile API** - only for businesses you (or the OAuth-connecting account) own or manage. Full review history with pagination, but needs Google partner approval - more on that below

#### Places API mode

1. Open the [Google Cloud Console](https://console.cloud.google.com) and create (or select) a project.
2. Enable the **Places API (New)** under **APIs & Services → Library**.
3. Create an API key under **APIs & Services → Credentials → Create credentials → API key**.
4. **Worth doing:** restrict the key. In its settings, set "Application restrictions" to your server IP(s) and "API restrictions" to Places API (New) only - if the key ever leaks, it can't be re-used for billed Maps calls.
5. In Vouch, leave **Mode** on "Places API", paste your API key, then use the built-in **"Find a Place ID"** search box. Type a company name, address or postcode and hit **Search** - Vouch proxies Google's Text Search endpoint server-side and shows the matches. Click one and the Place ID fills in for you.

   Already got a Place ID from Google's [Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id)? Paste it in manually instead.

> ⚠ Heads up: Google only ever returns **the 5 most recent reviews** on the Places API, and there's no way around it - that's just how their API works. Don't worry though, re-syncing won't create duplicates, you'll always just get the latest 5. **Set `backfillDays` to `0` for Places-mode sources** so you grab everything available.

#### Business Profile API mode

> ⚠ **Heads-up: Google gatekeeps this endpoint.** Back in 2023 Google clamped down on programmatic access to reviews via the Business Profile API. To use this mode in production:
>
> 1. Apply via [Google's Business Profile API form](https://support.google.com/business/contact/api_default).
> 2. Get an OAuth 2.0 consent screen verified by Google (its own review process if your scope is "sensitive").
> 3. Wait for approval - usually weeks, and first-time applicants are often turned down.
>
> Without approval the OAuth flow completes fine, but the reviews endpoint comes back with `403 PERMISSION_DENIED`.

Once you're approved:

1. Enable the **My Business Account Management API**, **My Business Business Information API**, and the **Business Profile API** in Google Cloud Console.
2. Create an **OAuth 2.0 Client ID** of type "Web application". Add `https://<your-site>/admin/actions/vouch/sources/google-oauth-callback` to "Authorized redirect URIs", then jot down the Client ID and Client Secret.
3. In Vouch: **Add source → Google Reviews**, switch **Mode** to "Business Profile API", paste in the Client ID + Client Secret and Save.
4. Reload, click **"Connect Google account"** and approve on Google's consent screen. The OAuth callback stores an encrypted refresh token on the source.
5. Fill in the **Location resource name** (`accounts/{accountId}/locations/{locationId}`). You can list your accounts/locations via the [API explorer](https://developers.google.com/my-business/reference/businessinformation/rest/v1/accounts.locations/list) or a quick `curl` against `https://mybusinessaccountmanagement.googleapis.com/v1/accounts`. Save again.
6. Hit **"Test connection"** to make sure everything's talking.

### Trustpilot

1. Sign in to [Trustpilot Business](https://business.trustpilot.com).
2. Generate an API key under your account's API / Integrations settings. The public tier is plenty for pulling reviews of your own business unit.
3. In Vouch, paste the API key, then use the built-in **"Find a Business Unit"** search box. Type your company name or domain, hit **Search**, and click a match to fill in the Business Unit ID for you.

   Already got the Business Unit ID? Just paste it in manually.

### Feefo

1. Your **Merchant identifier** is the slug in your Feefo dashboard URL.
2. An **API key** is optional. Public review data flows without one; private fields (e.g. customer email) need a paid plan and a bearer key.
3. Paste the merchant identifier and (optionally) the API key into the Feefo source edit page.

Without an API key, reviewer emails come through as `null` and user-matching won't fire.

### Reviews.io

1. Sign in to [your Reviews.io merchant dashboard](https://dash.reviews.io).
2. **Integrations → API**: copy your **Store ID** (merchant code) and **API key**.
3. Paste both into the Reviews.io source edit page.

Reviewer email is passed through when present, so user-matching works out of the box.

### Manual

No external credentials needed. Add a Manual source to:

- Author reviews directly in the CP via the **+ New review** button.
- Collect reviews through a front-end form (see [Front-end review submissions](#front-end-review-submissions) below).

Manual sources can still have `Require manual approval` toggled on - moderation respects the same `autoApproveThreshold` setting as the API-backed sources.

## Front-end review submissions

Drop a form on your front-end so customers can leave reviews directly through your site. Submissions are accepted against Manual sources only.

On a failed submission, Vouch repopulates a `review` variable with the user's input and errors.

```twig
{% set vouchSettings = craft.vouch.settings %}

<form method="post">
  {{ csrfInput() }}
  <input type="hidden" name="action" value="vouch/reviews/submit">
  <input type="hidden" name="sourceHandle" value="customer-reviews">

  {# Optional: tie the review to a specific entry / product #}
  <input type="hidden" name="relatedElementId" value="{{ entry.id ?? '' }}">

  {# Honeypot - real users won't fill this, but bots will #}
  <input type="text" name="vouchHoneypot" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;">


  {# Show any error returned after submission #}
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

| Field | Required | Notes |
|---|---|---|
| `sourceHandle` | Yes | The handle of the Manual source to submit against |
| `rating` | Yes | Numeric rating from 1-5 |
| `headline` | Yes | Short title for the review |
| `review` | Yes | The review body |
| `reviewerName` | Yes | Display name shown alongside the review |
| `reviewerEmail` | Yes | Used for moderation and user matching |
| `relatedElementId` | No | Tie the review to a specific entry, product, user, etc. |
| `vouchHoneypot` | No | Hidden honeypot - leave empty. Any value silently discards the submission |

## Twig

Because Vouch pulls reviews from every provider into a single store, you can mix and slice them however you like from one Twig API. Here are some real-life examples you can drop straight into a template.

### Overall rating across all sources

Drop this in a footer, homepage hero, or trust banner.

```twig
{% set average = craft.vouch.averageRating() %}
{% set count = craft.vouch.reviews().count() %}

{% if count %}
  <p>Rating: {{ average|number_format(1) }} ★ ({{ count }} {{ count == 1 ? 'review' : 'reviews' }})</p>
{% endif %}
```

### Rating for a single entry or product

Use on a product or entry detail page to show that element's own rating.

```twig
{% set rating = craft.vouch.ratingForElement(entry.id) %}
{% set count = craft.vouch.reviews().relatedElementId(entry.id).count() %}

{% if rating %}
  <p>{{ rating|number_format(1) }} ★ ({{ count }} {{ count == 1 ? 'review' : 'reviews' }})</p>
{% endif %}
```

### Listing the latest reviews

```twig
{% for review in craft.vouch.reviews().limit(5).all() %}
  <article>
    <h3>{{ review.headline ?: review.reviewerName }}</h3>
    <p>{{ review.rating }} ★ - {{ review.reviewedAt|date('M j, Y') }}</p>
    <blockquote>{{ review.review }}</blockquote>
  </article>
{% endfor %}
```

### A user's own reviews on their profile page

Pulls reviews where the reviewer's email matched a Craft user account.

```twig
{% set userReviews = craft.vouch.reviews().reviewerUserId(currentUser.id).all() %}

<h2>Your reviews ({{ userReviews|length }})</h2>

{% for review in userReviews %}
  <article>
    <h3>{{ review.headline }}</h3>
    <p>{{ review.rating }} ★ - {{ review.sourceName }} - {{ review.reviewedAt|date('M j, Y') }}</p>
    <blockquote>{{ review.review }}</blockquote>
  </article>
{% else %}
  <p>You haven't left any reviews yet.</p>
{% endfor %}
```

### Per-source breakdown for an element

Useful when you're pulling reviews from more than one provider and want to show each one's average side-by-side.

```twig
{% for row in craft.vouch.ratingBreakdownForElement(entry.id) %}
  <li>{{ row.sourceName }}: {{ row.average|number_format(1) }} ★ ({{ row.count }})</li>
{% endfor %}
```

### Filtering by source and rating

Grab the high-rated reviews from one source.

```twig
{% set google = craft.vouch.source('google-uk') %}
{% set positive = craft.vouch.reviews().sourceId(google.id).rating('>= 4').all() %}
```

### Review properties

Each review element returned by `craft.vouch.reviews()` exposes:

| Call | Returns |
|---|---|
| `review.headline` | The review's short title |
| `review.review` | The review body text |
| `review.rating` | Numeric rating from 1-5 |
| `review.reviewerName` | Display name of the reviewer |
| `review.reviewerEmail` | Reviewer's email (when available) |
| `review.reviewedAt` | Date the review was left |
| `review.sourceName` | The source's display name (e.g. "Google UK") |
| `review.sourceHandle` | The source's machine handle |
| `review.providerHandle` | The connector handle (`google`, `trustpilot`, `feefo`, `reviewsio`, `manual`) |
| `review.getReviewerUser()` | The Craft `User` element when the reviewer's email matched an account, otherwise `null` |

### Full Twig API

| Call | Returns |
|---|---|
| `craft.vouch.reviews()` | Review query. Chainable filters: `.sourceId(id)`, `.externalId(str)`, `.rating(value)`, `.approved(bool\|null)`, `.reviewerUserId(id)`, `.relatedElementId(id)`. Defaults to approved-only - pass `.approved(false)` for pending only or `.approved(null)` for both. |
| `craft.vouch.sources()` | All `Source` models |
| `craft.vouch.source(handle)` | A single `Source` by handle |
| `craft.vouch.sourceById(id)` | A single `Source` by id |
| `craft.vouch.providers()` | All registered connectors |
| `craft.vouch.averageRating(sourceId?)` | Site-wide or per-source average rating |
| `craft.vouch.ratingForElement(elementId)` | Average rating across approved reviews for one element |
| `craft.vouch.ratingBreakdownForElement(elementId)` | Per-source rows of `{sourceId, sourceName, providerHandle, average, count}` |
| `craft.vouch.pluginName()` | The configured plugin name |
| `craft.vouch.settings` | The plugin's settings model (e.g. `craft.vouch.settings.headlineMaxLength`) |

## Sync

Set up a cron job to pull in new reviews on a schedule. You decide how often it runs.

```bash
# Every enabled source, hourly
0 * * * *  php craft vouch/sync/all

# Different cadence per source - the last bit is the source's handle
0 * * * *  php craft vouch/sync/source google-uk
0 4 * * *  php craft vouch/sync/source trustpilot-main
```

The argument on `vouch/sync/source` is the **handle** you set when creating the source (visible in the Sources index). You can also hit the **Sync** button on the Sources index, the source edit page, or the dashboard widget for an ad-hoc pull.

## Attribution & spam controls

Vouch has a few protections built in to stop fake or forged reviews coming through your front-end form.

### How emails are handled

The reviewer's email is stored for moderation and contact only. A review is only linked to a Craft user when the submitter is logged in and uses their own account email - so no one can claim to be someone else.

### Blocking impersonators

`requireLoginForKnownEmails` (on by default) rejects any submission whose email already belongs to a Craft user, unless they're logged in as that user.

### Locking it down further

Vouch comes with a honeypot field and per-IP rate limiting baked in - just include the `vouchHoneypot` input in your form (see the example above) and tweak `submissionRateLimit` / `submissionRateWindow` in Settings to taste. For extra peace of mind:

- Turn on **"Require manual approval"** on the source so reviews stay Pending until an admin approves them.
- Restrict the form to logged-in users with `{% requireLogin %}`.
- Add a CAPTCHA (hCaptcha / reCAPTCHA) if you're getting a lot of traffic from anonymous users.

Reviews that aren't from Manual sources (e.g. Google, Trustpilot, etc.) don't go through any of this - those emails come straight from the provider.

## Element integration

Vouch wires ratings into Craft's own element indexes and edit pages so reviews surface where you'd expect them. Each column is opt-in - turn it on via the column settings cog on the element index.

| Element | What you get | How to enable |
|---|---|---|
| **Entries** | Rating column on the index + sidebar block on the edit page with the average and per-source breakdown. | Index → cog → tick **Rating** |
| **Commerce Products** | Same as Entries. | Index → cog → tick **Rating** |
| **Users** | Reviews count column on the index + a **Reviews** tab on the edit page listing reviews they've authored. | Index → cog → tick **Reviews** |

## Dashboard widgets

Add via the Craft dashboard → **+ New widget**.

| Widget | Shows | Settings |
|---|---|---|
| **Reviews Pending Approval** | Reviews waiting to be approved. | `limit` |
| **Latest Reviews** | The most recent approved reviews. | `limit`, `sourceId` |
| **Top Reviewed Elements** | Elements ranked by review count or average rating. | `elementType`, `sectionId`, `sort`, `limit` |
| **Sources** | Each source with its last-synced timestamp and a one-click Sync button. Manual sources are excluded. | `sourceId` |

## Settings

Settings live at **Settings → Plugins → Vouch** in the CP, and can be overridden per environment via `config/vouch.php`.

| Setting | Default | What it does |
|---|---|---|
| `pluginName` | `Vouch` | Plugin name shown in the CP. |
| `matchAuthorsToUsers` | `true` | Link reviewer emails to Craft users. |
| `emailRetentionDays` | `365` | Days to keep reviewer emails. `0` = forever. |
| `backfillDays` | `90` | History to pull on first sync. `0` = everything. |
| `autoApproveThreshold` | `5.0` | Auto-approve reviews at or above this rating. |
| `requireLoginForKnownEmails` | `true` | Block submissions using an email already tied to a Craft user. |
| `headlineMaxLength` | `120` | Max headline length. `0` = no limit. |
| `reviewMaxLength` | `2000` | Max review body length. `0` = no limit. |
| `submissionRateLimit` | `5` | Max submissions per IP per window. `0` = no limit. |
| `submissionRateWindow` | `60` | Rate-limit window in seconds. |

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

Public GraphQL queries default to `approved: true` so pending-moderation reviews never leak. Admins can override with `approved: false` when they need to.

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

## Nice to know

### Permissions

Vouch adds its own permissions group to each user group's permissions page - View / Create / Edit / Delete for reviews and sources, plus Approve, Trigger sync, Use widgets and Manage settings.

### Bulk approving reviews

On the Reviews element index, select any pending reviews and use the **Bulk Approve** action to approve them all in one go.

### Renaming the plugin

Vouch picks up whatever you set as `pluginName` in Settings - so you can call it whatever suits your site, e.g. Reviews, Testimonials, Feedback.

## Support

If you have any issues (surely not!) I'll aim to reply as soon as possible. If it's a site-breaking-oh-no-what-has-happened moment, hit me up on the Craft CMS Discord - `@bymayo`.
