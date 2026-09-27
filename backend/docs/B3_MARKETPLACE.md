# B3 — Marketplace (spec + handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B4 Inquiry**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Data access | The marketplace reads **only the four controlled public views** (`public_organization_profiles`, `_services`, `public_event_types`, `public_reviews`), never base tables. Views are `security_invoker` and guests have no base-table grants, so reads run in the logged system context. This resolves the B0 open item without adding public-read RLS policies or anon grants. | pack ("controlled views") |
| Migration 0029 (applied) | Appends `slug, latitude, longitude` to `public_organization_profiles` (existing columns/filter/security_invoker unchanged) → friendly URLs `/organizers/{slug}` and "near me" search like the prototype. | user approved |
| Public listing rule | Visible only if **published by the owner AND approved AND not suspended AND not deleted** (enforced by the view). Unlisted slugs are a plain 404. | schema |
| Contact details | Profile contact email/phone are public (they are in the view): client–organizer communication happens outside the platform (paper). | paper + schema |
| "Public events" marketplace | The prototype's `/marketplace` listed ticketed events. That is B9 (ticketing); B3 is the organizer directory. | roadmap |

## Endpoints

Public (guests OK, `throttle:public` 60/min/IP):

| Method | Path | Notes |
|---|---|---|
| GET | `marketplace/organizers?search&service&lat&lng&radius_km&sort&page&per_page` | cards: name, excerpt, location, cover, services (≤6), rating avg/count, distance_km when `lat/lng` given. `sort=rating|name|distance`; radius ≤ 200 km; search also matches service names; LIKE wildcards are escaped. |
| GET | `marketplace/organizers/{slug}` | profile + all public services + organizer's custom event types + rating summary (avg, count, 5→1 distribution) + 5 recent reviews |
| GET | `marketplace/organizers/{slug}/reviews?page` | paginated public reviews (reviewer = first name + last initial) |
| GET | `marketplace/event-types` | platform-default event types |
| GET | `marketplace/services` | service names offered by listed organizers + organizer counts (filter chips) |

Organizer (owner-managed, members view): `GET/POST/PATCH/DELETE organizations/{org}/event-types[/{eventType}]`: own custom types next to the platform defaults; names unique (case-insensitive) including against platform defaults; platform defaults and other tenants' types are 404; soft delete (past events keep their type).

## Frontend

- `layouts/public.vue` (ported from the prototype, wired to `useAuth`), `/` → guests to `/organizers`, signed-in users to their home.
- `/organizers`: search, "Near me" (browser geolocation → distance search + sort), service chips, sort, pagination.
- `/organizers/{slug}`: cover, rating, about, services with starting prices, reviews (load more), contact, OpenStreetMap link, "Request a quotation" → `/client/post-event?organizer={slug}` (B4 wires it).
- Organizer `/organizer/profile` (new "Business Profile" nav item): publish/hide listing (explains that it only shows after approval), edit details incl. coordinates ("Use my location") and https cover URL, services, custom event types, "View public page".
- `useMarketplace()` composable.

## Verified

- Full suite (B0–B3): **153 tests / 719 assertions pass**; `nasery:doctor` passes (incl. 0029 check); no test data left.
- `tests/Feature/Marketplace/MarketplaceTest.php` (8) + `tests/Feature/Organizations/EventTypesTest.php` (4): only approved+published+non-suspended appear (pending/hidden/suspended absent, 404 by slug), cards carry no private fields, search by name and service, literal wildcards, near-me distance filter and ordering (Davao/Digos/Manila), filter validation, profile content (inactive services hidden), event-type rules and tenant isolation.
- Real browser: guest `/` → directory → profile (services, prices, contact, map link) → unknown slug page; organizer Business Profile: loads, add custom event type, hide (public page disappears) and publish again. Test data purged (`nasery:test-data:purge`, now also removes custom event types).

## Open

- **Ratings are untestable until reviews exist** (reviews need events, B6, and the review flow, B16). The aggregation reads `public_reviews`; re-verify with real data in B16.
- No portfolio/gallery or file uploads (no schema for them); cover image is an https URL. Uploads (Supabase Storage) could come later.
- Service catalog still empty (see B2); filter chips come from organizers' own service names.
- Organizer-home stats remain mock (B6/B15).
