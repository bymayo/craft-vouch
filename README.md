# Vouch for Craft CMS

Pull and manage reviews from Google, Trustpilot, Feefo and Reviews.io directly inside Craft CMS.

> Status: pre-release. v5.0.0 ships the foundations — Source CRUD, Review element type, connector framework. Provider connectors land in the following phases.

## Requirements

- Craft CMS 5.6.0+
- PHP 8.2+

## Roadmap

- **Phase 1** (current) — Scaffold, data model, CP UI foundations.
- **Phase 2** — Google Reviews connector (Places API).
- **Phase 3** — Trustpilot, Feefo, Reviews.io connectors.
- **Phase 4** — Sync orchestration: queue jobs, console commands, scheduling, moderation.
- **Phase 5** — Events + Points trigger contract (integrates with [bymayo/craft-points](https://github.com/bymayo/craft-points)).
- **Phase 6** — Push: send review invites, post reviews back where APIs support it.
- **Phase 7** — Front-end: Twig element queries + GraphQL.
- **Phase 8** — Plugin Store release.
