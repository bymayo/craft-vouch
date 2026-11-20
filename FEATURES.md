# Features

<!-- slaite:rules v10 -->
> **For AI agents:** this file is the project's feature backlog. People edit it in the Slaite app and agents edit it directly, so keep to the format below or the app can't read it. Slaite maintains this section and replaces it automatically when the format changes, so don't edit it.

**Structure**

- Each `##` heading is a group. There are exactly four: `Now`, `Next`, `Later` and `Done`.
- **The group headings are fixed.** Never rename, reword, reorder, merge or delete a `##` heading, and never add a new one (e.g. `Bugs`, `Sprint 2` or `Now (urgent)`). The app relies on these exact names, and `Done` in particular drives each feature's status.
- To add work, put new `###` features inside one of these groups. Choose the closest match (`Later` if unsure) rather than creating a group.
- Each `###` heading is a feature. Directly under the heading comes its metadata comment, then an optional description, then its tasks.
- Tasks are one-line checkboxes: `- [ ]` is open, `- [x]` is done.

**Metadata comment** (the line under each feature heading)

`<!-- id: f-k3x9q2 · status: todo · priority: high · due: 2026-12-01 · tags: Feature, Bug -->`

- `id`: unique. Use `f-` followed by 6 random lowercase letters or digits. Never change an existing id.
- `status`: `todo`, `in-progress`, `review` or `done`. The app manages this, so leave it alone on existing features and use `todo` for new ones.
- `priority` (optional): `urgent`, `high`, `medium` or `low`. Leave it out if unsure. Only change an existing priority when asked.
- `due` (optional): a deadline as `YYYY-MM-DD`. Only set or change it when asked, or when the request names a date.
- `tags` (optional): comma-separated, chosen only from this project's tags: `Feature`, `Bug`, `Improvement`, `Server`, `Design`, `API`, `Integration`, `Domain`, `Marketing`. Never invent other tags. Only change tags on existing features when asked.
- Keep the fields in that order, separated by ` · `.

**Deadlines**

- Whenever you start work or are asked what to do next, scan every feature's `due` date first.
- Call out anything overdue or due within 7 days before anything else, soonest first, then by priority.
- If you're working on something else while a feature is overdue, mention the overdue feature in your summary.

**When you work on a feature**

- Tick each task (`- [ ]` to `- [x]`) as soon as it's complete.
- If you find more work the feature needs, add it as a new `- [ ]` task under that feature.
- Don't edit other features unless you're asked to.
- Don't move features between groups. People do that in the app.
- A description may include screenshots as markdown images, e.g. `![screenshot](.features/assets/…)` (paths are relative to the project root; the folder can differ per project). Open them with your file-reading tool, since they often show the bug or design. Keep these lines when you edit the description.

**When you add a feature** (when asked, or to record follow-up work)

Add it to the right group (`Later` for follow-ups unless told otherwise) and fill in the metadata from what you know:

- `priority`: set it when the request says how important it is (e.g. "ASAP" or "blocking" is `urgent`, "nice to have" is `low`). Otherwise leave it out.
- `due`: set it when the request names a date or deadline, converted to `YYYY-MM-DD`.
- `tags`: add the ones that fit from `Feature`, `Bug`, `Improvement`, `Server`, `Design`, `API`, `Integration`, `Domain`, `Marketing` (e.g. `Bug` for something broken).

Shape it like this:

```markdown
### Short, specific feature title
<!-- id: f-7mq2zc · status: todo · priority: medium · tags: Feature -->

One or two sentences on what it is and why it's needed.

- [ ] First task
- [ ] Second task
```
<!-- /slaite:rules -->

## Now

## Next

### Remove reviews deleted at source
<!-- id: f-m2jttv · status: todo · priority: high · tags: Bug -->

Sync only adds and updates, so reviews removed or hidden on the provider stay live on the site.

- [ ] Check what each connector returns for deleted or hidden reviews
- [ ] Disable or delete local reviews missing from a full sync
- [ ] Add a setting to choose disable vs delete

### Email notifications
<!-- id: f-s0pd94 · status: todo · priority: high · tags: Feature -->

- [ ] Notify admins when a review needs approval
- [ ] Confirm to the reviewer that their review was received
- [ ] Editable email templates and recipients in settings

### Schema.org rich snippets
<!-- id: f-vrd221 · status: todo · priority: high · tags: Feature, Marketing -->

A Twig tag (e.g. `{% vouchSummary %}`) that outputs AggregateRating JSON-LD so ratings show in Google results.

- [ ] AggregateRating for an element or source
- [ ] Optional individual Review items

### Star rating breakdown
<!-- id: f-dsi6ck · status: todo · priority: medium · tags: Feature, API -->

Count of reviews per star (5★ 120, 4★ 30…) for an element or source, alongside the existing per-source breakdown.

- [ ] Service method and `craft.vouch` Twig helper
- [ ] Show it in the element sidebar pane

### Front-end filtering & search
<!-- id: f-lbxqd6 · status: todo · priority: medium · tags: Feature, API -->

- [ ] Filter by rating, source and date
- [ ] Keyword search
- [ ] Sort options (newest, highest, lowest)
- [ ] Twig example in README

### Featured reviews
<!-- id: f-zcsage · status: todo · priority: medium · tags: Feature -->

A pinned/featured flag and `.featured()` query param for testimonial walls.

### Review picker field
<!-- id: f-kr47z1 · status: todo · priority: medium · tags: Feature -->

A field type so editors can hand-pick reviews to show on an entry.

### Multi-site support
<!-- id: f-4b6ycl · status: todo · priority: medium · tags: Improvement -->

- [ ] Per-site settings
- [ ] Per-site sources and reviews

## Later

### Craft Commerce integration
<!-- id: f-2upiut · status: todo · priority: medium · tags: Integration, Feature -->

- [ ] Post-purchase email with a tokenised review link
- [ ] Pre-fill the form and tie the review to the order
- [ ] Verified buyer badge when the reviewer's email matches a paid order

### JS submission API
<!-- id: f-3tp26v · status: todo · priority: medium · tags: API, Feature -->

Submit reviews via JS so forms work on statically cached pages.

### Review images
<!-- id: f-pm6wli · status: todo · priority: medium · tags: Feature -->

Let reviewers attach photos, stored as Craft assets.

### Helpful votes
<!-- id: f-ffsu35 · status: todo · priority: low · tags: Feature -->

- [ ] Thumbs up/down on each review
- [ ] Twig helper and rate limiting
- [ ] Sort by most helpful

### "I recommend this" flag
<!-- id: f-pn2i1q · status: todo · priority: low · tags: Feature -->

### Moderation tools
<!-- id: f-uuajb7 · status: todo · priority: medium · tags: Feature, Improvement -->

- [ ] Profanity filter with configurable wordlist (flag, don't reject)
- [ ] Admin notes and internal tags
- [ ] "Hide from front end" flag separate from approval

### Reporting & insights
<!-- id: f-b4yt9x · status: todo · priority: low · tags: Feature -->

- [ ] Reporting dashboard (volume, averages, trends)
- [ ] Common keywords across reviews
- [ ] Sentiment and language detection on sync
- [ ] `.minSentiment()` query param

### Webhook endpoints
<!-- id: f-ddwg6h · status: todo · priority: low · tags: Server, API, Integration -->

Receive pushed reviews from Reviews.io and Trustpilot for near-real-time sync instead of cron.

### More review providers
<!-- id: f-2u5j2i · status: todo · priority: low · tags: Integration -->

- [ ] Outscraper (more Google reviews)
- [ ] Facebook Page recommendations
- [ ] Yotpo
- [ ] Bazaarvoice
- [ ] Stamped.io
- [ ] Judge.me

### Push reviews to third parties
<!-- id: f-7kmrxl · status: todo · priority: low · tags: Integration -->

### Third-party notifications
<!-- id: f-5saraq · status: todo · priority: low · tags: Integration -->

- [ ] Send review requests through Knock (or similar)
- [ ] Email users when Points are awarded for a review

### Reviewer location
<!-- id: f-1z8p6a · status: todo · priority: low · tags: Feature -->

## Done

### Google Reviews source
<!-- id: f-6bddsh · status: done · tags: Integration -->

Google connector with Places and Business Profile APIs.

### Sync business replies
<!-- id: f-t43gqz · status: done · tags: Integration -->

Owner replies pulled into `businessReply` for Google, Trustpilot, Reviews.io and Feefo.
