# B4: Inquiry (spec and handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B5 Quotation**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Two ways to send | **Direct**: the client picks up to 10 listed organizers (from the directory or a profile's "Request a quote"). **Open posting**: the client pins the event location and chooses a radius (1–500 km). NaSeRy invites every listed organizer within that radius that offers a requested service, nearest first, capped at 100. | paper + user |
| Service matching | Names only, read from public views. An organizer matches when a requested service name equals one of its public service names, or when either name contains the other and both are at least 4 characters. Catalog services compare by their catalog name. | public views rule |
| Drafts | A draft may be incomplete. On submit, event type, date (today or later), guests and location are required; open posting also needs a pin and at least one service. | UX |
| Invitations | Rows in `inquiry_organizations` are created only by the server, through `system()` after ownership and state checks. 0030 revokes INSERT and DELETE from the app role, so neither side can invite itself through RLS. | user ("close it") |
| Client identity | Organizers see the client as first name plus last initial. Contact details are not shared until later phases. | privacy |
| Auto-close | Open inquiries idle for `inquiry_auto_close_days` (30) are closed daily at 01:00 by `nasery:inquiries:close-stale`. | user |
| Migration 0030 (applied) | Adds the matching columns and CHECKs, narrows the grants on `inquiry_organizations`, and seeds the setting. | user approved |
| Migration 0031 (applied) | Fixes the infinite RLS recursion (42P17) between the `inquiries` and `inquiry_organizations` policies using SECURITY DEFINER id helpers. `nasery:doctor` probes every affected table. | user approved |

## Endpoints

**Client** (`account:client`, verified):

| Method | Path | Notes |
|---|---|---|
| GET | `inquiries?status&page` | Summaries with invited, viewed and declined counts; 15 per page. |
| POST | `inquiries` | Creates a draft. `services[]` takes `{service_id or custom_service_name, notes}`. |
| GET | `inquiries/{id}` | Detail: services, invitations (organizer name and slug, status, decline reason), pin, radius. |
| PATCH | `inquiries/{id}` | Drafts only. |
| DELETE | `inquiries/{id}` | Drafts only; a sent inquiry is cancelled instead. |
| GET | `inquiries/{id}/matches?match_radius_km` | Previews who an open posting would reach. |
| POST | `inquiries/{id}/submit` | `{mode: direct, organization_ids[]}` or `{mode: open, match_radius_km}`. Idempotent. Returns `NO_MATCHING_ORGANIZERS` (422) when nothing matches. |
| POST | `inquiries/{id}/invite` | Adds more organizers while the inquiry is open. Idempotent. |
| POST | `inquiries/{id}/cancel` | `{reason?}`. |

**Organizer** (`account:business`, needs `inquiry.manage` and an operational, approved organization):

| Method | Path | Notes |
|---|---|---|
| GET | `organizations/{org}/inquiries?status[]&page` | Filters on invitation status. |
| GET | `organizations/{org}/inquiries/{id}` | Full detail (notes, requirements). The first open marks the invitation `viewed`. |
| POST | `organizations/{org}/inquiries/{id}/decline` | `{reason}`, 3–500 characters. Only while the inquiry is open and not yet quoted. |

## Frontend

- `composables/useInquiries.ts`: client and organizer APIs, status labels, `budgetLabel`.
- `pages/client/post-event.vue`: saves the draft, then submits it. With `?organizer=slug` (from a profile page) it sends directly to that organizer. Otherwise the client chooses between a nearby open posting (pin and radius) and picking organizers from a directory search.
- `pages/client/my-events.vue`: the real inquiry list with stats and pagination.
- `pages/client/inquiries/[id].vue`: detail, organizer responses, cancel, and delete draft.
- `pages/organizer/inquiries/match-inquiries.vue`: filter tabs, view (marks viewed), and decline with a reason. "Send Quotation" is disabled until B5.
- The client nav gained "Post an Event"; the mock badge on the inquiry tab was removed.

## Tests

`tests/Feature/Inquiries/InquiryTest.php` (15 tests) covers:
- only verified clients create inquiries; draft validation
- direct submit reaches only the chosen, listed organizers; unlisted ones are refused
- open posting invites matching organizers within range; no matches → refused and still a draft
- completeness on submit, and submit happens once
- drafts editable, sent inquiries not; clients isolated from each other
- organizers see and decline only their invitations; staff without `inquiry.manage` and suspended orgs cannot respond
- cancelled inquiries close for organizers; inviting more while open
- the database refuses self-invitation by an organizer (0030); stale inquiries auto-close

## Open items

- Quotations (the "Send Quotation" button and the `receiving_bids` status) arrive in B5.
- Clients and organizers get no email about new or declined inquiries until B13.
- The services catalog is empty, so matching runs on custom service names for now.
