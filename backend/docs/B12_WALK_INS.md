# B12: Walk-ins and capacity (spec and handoff)

Status: **implemented 2026-09-25**. No migration (it uses the 0015 `walk_in_records` table and the 0021 capacity functions). Next phase: **B13 Notifications**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Three ways in | One counter; each event uses whatever fits (user: "a mix of both"):<br>• **Sell / register ticket:** pick a tier, type the name. A paid tier requires "₱X collected in cash"; a free tier just registers. A real ticket is issued (source `walk_in`, price = tier price) and checked in at once. Tier **and** venue capacity apply. Recorded as a `ticket_linked` walk-in, which adds nothing extra to capacity (the ticket already counts, 0015). Any tier of the event can be sold at the door, including one kept off online sale (for example a "Door" tier in draft).<br>• **Log a name:** open events where people write their name. An attendee without a ticket (`registered_attendee`).<br>• **Headcount:** staff count people who just walk in (`headcount_only`, +1 or a group up to 50). | user + old prototype (tiered door sales) |
| Capacity | Venue capacity = valid/used tickets + active reservations + walk-ins without a ticket (0021 `event_consumed_capacity`). Levels: `ok`, **`warning` from 90%**, `full`, `over`. Events without an overall capacity count walk-ins but never refuse them (the counter says so). | 0021 + paper |
| When full | A mix (user):<br>• at 100%, door staff are refused (`CAPACITY_EXCEEDED`, `scope: event`)<br>• anyone with **`event.update`** can **admit anyway with a reason**; the response and counter show "over capacity by N", audited as `walkin.capacity_override` (also in the activity timeline)<br>• a **full tier is always a hard stop** (`scope: tier`), even with an override: choose another tier or raise its capacity | user |
| Door open | Same rule as check-in (the `SessionWindow` class now shared by B11 and B12): 3 hours before a session until it ends, event published or ongoing. | B11 |
| Double taps | Recording endpoints need an `Idempotency-Key` (a retried request counts once). On the page, actions are **queued**, so a burst of +1 taps all count, and forms are disabled while submitting. | integrity |
| Undo | `attendee.manage` with a reason:<br>• removes the entry<br>• a door sale's ticket is cancelled (QR invalidated, check-in removed, tier count refreshed)<br>• audited `walkin.undone`<br>• the counter reminds staff to return any cash themselves | consistent with B11 |
| Money | Door cash is recorded as the ticket's `purchased_price` and shown as "Cash at the door". It is not a PayMongo payment, so no platform fee is taken. | B10 fee policy |
| Permissions | Record (all three): `checkin.perform` (door staff). Override: `event.update`. Undo: `attendee.manage`. Writes need an operational organization. | 0024 + B8 |
| Live numbers | Check-in (B11) and the client's live view now include walk-ins without a ticket: "3 of 3 guests arrived, + 4 walk-ins, 7 people in total". Door-sale tickets already count as checked in. | paper |

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/organizations/{org}/events/{event}/walk-ins` | `checkin.perform` or `attendee.manage`: capacity state, totals (door sales, door cash, names, headcount), tiers with places left, sessions, 25 recent, `can` |
| POST | `…/walk-ins/sale` `{ticket_type_id, full_name, email?, contact_number?, cash_received, session_id?, override_reason?}` | `checkin.perform`; the override needs `event.update` |
| POST | `…/walk-ins/name` `{full_name, email?, contact_number?, session_id?, override_reason?}` | same |
| POST | `…/walk-ins/headcount` `{count 1–50, session_id?, override_reason?}` | same |
| POST | `…/walk-ins/{walkIn}/undo` `{reason}` | `attendee.manage` |

Recording endpoints use `throttle:checkin` (120/min per staff member) and `idempotent`.

## Frontend

- `/organizer/ticketattendance/walkin-counter` (was a mock):
  - a color-coded capacity banner (green, amber from 90%, red when full or over)
  - three tabs: sell/register ticket, log a name, headcount (big +1, or a group)
  - a green confirmation
  - on "full": managers get **Admit anyway** with a reason; staff get "ask a manager"
  - totals, and recent entries with Undo
- QR check-in and the client live view show walk-ins.
- `useCheckIn()` gains `walkIns`, `sell`, `logName`, `headcount`, `undoWalkIn`.

## Tests

`tests/Feature/CheckIn/WalkInTest.php` (4):
- **Door sale:** requires cash confirmation; issues a used `walk_in` ticket at the tier price with a check-in and a `ticket_linked` row. A door-only draft tier sells. A full tier is a hard stop even with an override. Totals and door cash are correct, and the check-in summary counts it.
- **Names and headcount:** they fill the venue through `warning` (90%) and `full`. Door staff are refused and can't override (403). The owner admits past capacity with a reason, which is audited. The level becomes `over`, and live check-in totals include walk-ins.
- **Double taps and undo:** a repeated idempotency key counts once. Undoing a door sale cancels its ticket and frees the place. Door staff can't undo.
- **Door rules:** a draft event is refused; before the door opens, "Check-in opens…"; with no capacity set, walk-ins are counted, never refused.

B11's 6 check-in tests still pass after the session-window refactor.

**Verification (2026-09-25):** browser run as organizer on a 6-place venue with 3 invited guests:
- a ₱300 door sale (button disabled until cash confirmed): 4/6
- two rapid +1 taps, both counted: **full, 6/6**
- a third tap: "This event is full", then **Admit anyway** with a reason: **over capacity by 1, 7/6**
- undo the door sale: 6/6, cash total cleared
- no horizontal scroll at 375 px
- E2E data purged

Fixed during the run: the door-sale confirmation said "collect ₱300" after the cash was already confirmed; it now says "paid ₱300.00 cash".

## Open items

- **Full-venue alerts** to staff and organizers (paper): shown on the counter now; e-mail and in-app notifications come in **B13**.
- **Paid-at-door without a ticket** (`paid_walk_in`) isn't used: door sales issue real tickets instead, so revenue shows in reports. The category remains available.
- **GCash/Maya at the door** isn't supported (cash only). It could reuse PayMongo later.
- **Existing layout:** on phones the dashboard sidebar stays visible and leaves the page about 250 px wide. This affects every organizer page. Worth a responsive pass (a collapsible sidebar).
