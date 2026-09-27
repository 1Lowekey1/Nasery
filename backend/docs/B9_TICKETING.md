# B9: Ticketing, registration and QR tickets (spec and handoff)

Status: **implemented and verified 2026-09-25**. Next phase: **B10 PayMongo** (checkout for paid tiers, refunds).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Tiers | Each event has ticket or invitation tiers with a name, **access zone**, **benefits** (up to 12), price, capacity, sales window, status (not on sale, on sale, paused, closed) and display order. Capacity can't go below places already taken; a tier with tickets can't be deleted (close it). | paper + 0036 |
| Capacity | Enforced under a row lock on the tier (and the event when it has an overall capacity) using the 0021 helpers: valid/used tickets + active unexpired holds (+ walk-ins, B12). Runs in the system context so every ticket counts. Error `CAPACITY_EXCEEDED` (409) with the places left. | pack invariant |
| Guest lists | The organizer adds guests one by one or **imports a CSV** (`name, email, contact, tier`; common header variants like "Full Name", "E-mail" and "Phone" accepted; Excel BOM handled). Each guest gets a confirmed organizer registration and a **valid QR ticket** (invited or complimentary). Imports are **all-or-nothing** with every problem listed; max 1,000 per file. The export is CSV with spreadsheet-formula neutralization. | paper ("import from a spreadsheet … QR-coded tickets per guest") |
| Public events | Published **public** events of approved organizers appear on `/events` and `/events/{id}` through the 0036 views only. Private events never do. | user (0036) |
| Self-registration | **Signed-in, verified accounts** only (user). Up to 10 people per registration, one QR ticket each. Free tiers issue tickets at once. **Paid tiers reserve places for 15 minutes** (hold + pending registration); B10's checkout issues the tickets (user). `expire_stale_holds()` runs every minute; capacity reads already ignore expired holds. | user |
| QR codes | `qr_tickets` stores only `sha256(token)`. The token is `NSR1.<qr id>.<HMAC>` over the QR row, ticket and issue time with the app key, so it can be shown again to the holder or organizer without the server ever storing it. **Reissue** makes a new one; the old stops matching. QR images are drawn in the browser (`qrcode` package). | schema (hash-only) |
| Holder actions | "My Tickets" lists your tickets with QR codes (only while valid). You can cancel your own unused **free** ticket; paid or organizer-issued tickets go through the organizer. | UX |
| Event lifecycle | An event with issued tickets can't go back to draft. **Cancelling an event** cancels unused tickets, invalidates their QR codes, releases holds and closes the tiers (refunds for paid tickets are B10). | integrity |
| Permissions | Tiers: `ticket.manage`. Guest list writes: `attendee.manage`. Reading the list and the export: `attendee.manage` or `checkin.perform` (door staff). All organization-wide or per event (B8). | 0024 + B8 |

## Endpoints

**Public:**
- `GET public-events?search&page`
- `GET public-events/{event}`: tiers with `remaining` and `availability` (available, sold_out, not_yet, ended).

**Signed in:**

| Method | Path | Notes |
|---|---|---|
| POST | `public-events/{event}/register` | `{ticket_type_id, attendees[{full_name, email?}]}`. Idempotent. Returns `confirmed` or `awaiting_payment` with `hold_expires_at`. |
| GET | `me/tickets` | Includes `qr_token` for valid tickets. |
| POST | `me/tickets/{ticket}/cancel` | Free tickets only. |

**Organizer** (`organizations/{org}/events/{event}/…`; `{ticketType}` and `{ticket}` are scoped to the event):

| Method | Path | Notes |
|---|---|---|
| GET | `ticket-types` | Tiers with sold, held, checked-in and remaining counts, plus a summary and `can_manage` / `can_manage_guests`. |
| POST / PATCH / DELETE | `ticket-types[/{ticketType}]` | |
| GET | `guests?search&status&ticket_type_id&page` | `meta.summary` |
| POST | `guests` | `{full_name, email?, contact_number?, ticket_type_id, admission_type: invited\|complimentary}`. Returns the QR token. |
| POST | `guests/import` | multipart `file` + `ticket_type_id` + `admission_type` |
| GET | `guests/export` | CSV |
| GET | `tickets/{ticket}/qr` | |
| POST | `tickets/{ticket}/reissue-qr` | |
| POST | `tickets/{ticket}/cancel` | `{reason}` |

## Frontend

- `components/QrCode.vue`: SVG QR from a token.
- `composables/useTicketing.ts`
- `useApi().blob()`: authenticated file downloads (CSV).
- `pages/organizer/eventmanagement/ticket-tiers.vue`: tier cards (fill bar, zone, benefits, sales window), add/edit dialog, delete when unused.
- `components/ticketing-tabs.vue`: event picker and real totals (issued, checked in, cancelled, capacity); tabs keep `?event=`.
- `pages/organizer/ticketattendance/attendees.vue`: add guest, CSV import with an error list, searchable/filterable list, QR dialog (print, reissue), cancel, export.
- `pages/events/index.vue` and `pages/events/[id].vue`: public listing and detail; tier picker (sold out, not yet open, closed states), attendee names, register (sign-in redirect keeps the page); paid tiers show the reservation time.
- `pages/my-tickets.vue` (signed-in only): tickets with QR codes; linked from the public header and the client sidebar.

## Tests

`tests/Feature/Ticketing/TicketingTest.php` (8 tests):
- tiers: zones, benefits trimmed and blanks dropped, validation, capacity floor, delete refused once used
- guest list: QR format and hash, same token re-shown, reissue invalidates the old one, search and summary, cancel invalidates the QR, double cancel refused
- CSV import: header aliases, BOM, all-or-nothing error list, tier by name, formula neutralized on export
- capacity per tier and per event
- public registration: unpublished and private events hidden, sign-in required, draft tier 404, free tickets in My Tickets with QR, other users see nothing, paid holds block capacity until expired, holder cancel
- sales window: not open yet
- lifecycle: unpublish blocked with tickets; cancel voids tickets and QR codes
- door staff: can read the list, can't add or manage tiers, can't see other events

## Open items

- **B10**: PayMongo checkout converts holds into paid tickets; refunds when paid events are cancelled.
- **B11**: QR scanning verifies `sha256(token)` against active `qr_tickets` and marks tickets used.
- **B12**: walk-ins count toward event capacity (already in the helper).
- **B13**: emailing QR tickets to guests and registrants.
- Seating inside a zone is first-come (paper); no seat maps.
- `npm audit` reports 3 advisories in Nuxt's build tooling (`devalue`, `nanoid`, `svgo`). They predate B9 and aren't from `qrcode`; review before deploying.
