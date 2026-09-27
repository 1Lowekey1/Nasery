# B11: Event-day check-in (spec and handoff)

Status: **implemented 2026-09-25**. No migration (it uses the 0015 `attendance_records` table). Next phase: **B12 Walk-ins and capacity**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Check-in window | A session accepts tickets from **3 hours before it starts until it ends** (`NASERY_CHECKIN_OPENS_BEFORE_MINUTES`, default 180). Scans outside the window answer "Check-in opens Fri, Sep 25 at 3:00 PM" or "Check-in has closed". The event must be **published or ongoing**. | user |
| Multi-session | A ticket is admitted **once per session**, enforced by the 0015 unique index. The server picks the open session starting closest to now; door staff can choose when several are open. The first admission marks the ticket `used`, and it still opens the event's other sessions, so My Tickets keeps showing its QR until the event ends. | schema + UX |
| Outcomes | Every scan gets a clear answer, never an error page:<br>• green: `checked_in`<br>• amber: `duplicate` (shows first check-in time, who scanned, and the repeat count, stored in `duplicate_attempt_count`) and `not_open`<br>• red: `invalid`, `wrong_event`, `cancelled`, `unpaid`, `event_closed`<br>Unknown codes and other events' tickets reveal nothing about the ticket. A cancelled ticket's QR row still exists (invalidated), so it answers "cancelled"; a **reissued** QR replaces its row, so the old code answers "not recognized". | paper (invalid/duplicate alerts) |
| Concurrency | The ticket row is locked (`FOR UPDATE`) inside `system()`, so two doors scanning the same QR admit it once. | pack invariant |
| Manual check-in | Door staff search by name, email or phone and check in without a QR (`method = manual`), for example when a phone is dead. Same rules as a scan. | paper (guest list at the door) |
| Undo | **Allowed with a reason** for staff with guest-list permission (`attendee.manage`). The attendance row is removed, the ticket returns to `valid` if it has no other check-ins, and the undo is audited (`checkin.undone`, shown in the organizer activity timeline). | user |
| Permissions | Scan, manual and search: `checkin.perform`, organization-wide or on the event (B8 door staff). Undo: `attendee.manage`. Live numbers: either. Writes need an operational (approved, not suspended) organization. | 0024 + B8 |
| Rate limit | `throttle:checkin`, 120 per minute per staff member (pack). Repeats are answered `duplicate`, so no idempotency key is needed. | pack |
| Client live view | The client's event page shows **live attendance**: checked in / expected and per session, refreshing every 10 seconds while check-in is open. Numbers only, no names. | user + paper |
| Online only | Scanning needs the internet; offline scanning is out of scope (paper limitation). | paper |

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/organizations/{org}/events/{event}/checkin` | `checkin.perform` or `attendee.manage`: expected, checked in, sessions (open/opens at/count), per tier, 25 most recent, `can` |
| POST | `…/checkin/scan` `{token, session_id?}` | `checkin.perform` (`throttle:checkin`) |
| POST | `…/checkin/manual` `{ticket_id, session_id?}` | `checkin.perform` (`throttle:checkin`) |
| GET | `…/checkin/search?q=` | `checkin.perform`: up to 15 guests |
| POST | `…/checkin/{attendance}/undo` `{reason}` | `attendee.manage` |
| GET | `/events/{event}/attendance` | the client who owns the event |

## Frontend

- `/organizer/ticketattendance/qr-checkin` (was a mock):
  - **Camera scanner** (`components/organizer/QrScanner.vue`, `@zxing/browser` ported from the old prototype). It prefers the rear camera, gives clear permission, missing-camera and busy-camera messages, and releases the camera when the tab is hidden or left.
  - A typed-code box that also works with **USB or Bluetooth barcode scanners**.
  - Guest search with manual check-in.
  - A big colored result card with a **sound and vibration cue**.
  - Live counts that refresh every 5 seconds, and recent check-ins with **Undo**.
- Scans are **queued, never dropped**. The browser run found that ignoring input while a scan was in flight silently lost back-to-back scans.
- `components/client/LiveAttendance.vue` on the client's Your events page.
- `useCheckIn()` composable.

**Camera note:** browsers allow the camera only on **HTTPS or localhost**. To scan with a phone on the local network during development, use an HTTPS tunnel or the deployed site; the typed-code box works everywhere.

## Tests

`tests/Feature/CheckIn/CheckInTest.php` (6):
- one admission with duplicates flagged and counted, plus garbage, forged and other-event codes
- replaced, cancelled and unpaid tickets refused
- the session window: opens 3 hours before, closed after the end, draft event refused
- a multi-session ticket admitted once per session (explicit session choice respected)
- manual check-in, search, undo with a reason, door-staff vs guest-list permissions, and unassigned staff getting 404
- the client's live numbers (own event only, no names)

Also changed:
- the `cancellable` flag and QR for checked-in tickets (My Tickets)
- reissuing a QR allowed for `used` tickets
- the purge command clears `attendance_records` and `walk_in_records` first

**Verification (2026-09-25):**
- Browser run as organizer: typed/USB scan gives green, rescan gives amber with who/when, garbage gives red, two back-to-back scans are both admitted, undo with a reason works, and manual check-in from search works. No horizontal scroll at 375 px.
- Client view: "Live attendance · 3 of 3 guests arrived".
- E2E data purged.

## Open items

- The attendance report, PDF/CSV export and check-in timeline come in B14. Live event-day analytics (check-in rate, walk-in vs registered) come in B15.
- Walk-ins (B12) will add to the live counts and capacity.
- The QR ticket e-mail and PDF (old prototype's dompdf template) come in B13.
- `vue-tsc` isn't installed in the frontend, so there is no type-check step (the build doesn't type-check). Worth adding.
