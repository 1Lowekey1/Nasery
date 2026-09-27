# B14: Post-event attendance report (spec and handoff)

Status: **implemented 2026-09-25**. Migration **0037_event_reports** (approved and applied after a backup). Next phase: **B15 Analytics**.

## What the report contains (paper)
Computed live from check-ins, walk-ins and tickets (`AttendanceReport`):

| Section | Meaning |
|---|---|
| **Attended / expected** | Tickets checked in to at least one session / tickets that could admit someone (valid or used, including door sales). Attendance rate. |
| **No-shows** | Expected but never checked in to any session (user). |
| **Walk-ins** | People let in without a ticket (logged names + headcount); door sales shown separately. |
| **People in total** | Attended + walk-ins, and % of the venue capacity. |
| Footnotes | Cancelled tickets (not counted), manual check-ins, repeat scans caught at the door. |
| **By ticket tier** | Expected, attended, no-shows, rate. |
| **By source** | Online registration, guest list, door sales. |
| **By session** | Multi-day events: checked in and walk-ins per session. |
| **Check-in timeline** | Arrivals per 15 minutes (hourly for long events) in the event's timezone, split into ticket check-ins and walk-ins, with the busiest slot. Multi-day events show only days with arrivals. |
| **Revenue** | Only for staff with `finance.view`: online gross, platform fee kept, refunds, online net, cash at the door, total. |
| **Guest list** | Every ticket with status (attended / no-show / cancelled), source, first check-in and method, plus logged walk-in names. |

Exports: **PDF** (A4, dompdf; summary cards, tier bars, timeline bars, revenue, then the guest list on its own page; about 33 KB) and **CSV** (one row per guest, plus logged walk-ins and a headcount line; formula cells neutralized).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Review, then share | Paper: "Organizers review and approve the reports before sending them to clients."<br>• The organizer sees the live report (marked *Preliminary* until the event is completed).<br>• After completion, **Approve and share** freezes the figures as **version N** in `event_reports` (0037), with an optional note.<br>• The client always sees exactly what was approved; later corrections change only the live report until it's shared again (a new version; old ones stay readable).<br>• The client is notified (`report.shared`), and it appears on both activity timelines. | user |
| Revenue in the client copy | Off by default. The organizer can tick **"Include ticket sales and revenue"** when sharing (requires `finance.view`). | user |
| Guest names in the client copy | **Private events: included** (they're the client's own guests). **Public events: numbers only** (ticket buyers are strangers to the client). | privacy |
| Who can share | Needs `event.update`, the event completed, and the event created from a client's inquiry (independent events have no client; download the PDF instead). | B6/B8 |
| Who can view | `event.update` or `attendee.manage` (door staff with only `checkin.perform` can't). | B8 |

### 0037_event_reports
`event_reports(id, event_id, organization_id, version, snapshot jsonb, note ≤2000, includes_revenue, shared_by → users, shared_at)`:
- composite FK `(event_id, organization_id)` → `events`
- unique `(event_id, version)`
- RLS read for the organization's members (`user_org_ids()`) and the event's client (`user_client_event_ids()`)
- `nasery_app` has **SELECT only**; rows are written in the logged system context and never updated or deleted
- rollback: `0037_event_reports.down.sql`

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/organizations/{org}/events/{event}/report` | `event.update` or `attendee.manage`: live report + `shares` + `can` |
| GET | `…/report/pdf`, `…/report/csv` | same (revenue in the PDF only with `finance.view`) |
| POST | `…/report/share` `{note?, include_revenue?}` | `event.update` (+ `finance.view` to include revenue); idempotent |
| GET | `/events/{event}/report?version=` | the event's client: the shared version (latest by default) + version list |
| GET | `/events/{event}/report/pdf?version=` | the event's client |

## Frontend
- `components/reports/ReportView.vue`: one read-only view shared by the organizer's live report and the client's copy:
  - cards, tier bars, sources, sessions
  - timeline bars (dark: check-ins, light: walk-ins)
  - revenue
  - searchable, filterable guest list
- `/organizer/ticketattendance/attendance-report` (was a mock): PDF/CSV buttons, the report, and **Share with the client** (note, revenue checkbox, version history).
- `/client/event-report?event=` (new): version picker, organizer's note, Download PDF. Linked from the client's completed event card and from the notification.

## Tests
`tests/Feature/Reports/ReportTest.php` (3):
- **Report figures:** exact totals, tiers, sources, timeline sum and guest statuses for a mix of guests, a cancellation, scans, a door sale, a named walk-in and a headcount. PDF and CSV exports work. Door staff are refused.
- **Sharing:**
  - blocked until completed; guest-list staff can read but not share
  - v1 without revenue reaches the client with the note and guest list, and the client is notified
  - an undone check-in changes the live report but not v1
  - v2 with revenue; `?version=1` stays unchanged
  - client PDF works; other users get 404; audited twice
- **Public event:** the client copy has no guest names.

**Verification (2026-09-25):** browser run on a completed event, seeded through the real check-in and walk-in services with arrivals spread over the first hour:
- **Organizer:** 3/4 attended (75%), 1 no-show, 1 walk-in, 1 door sale, 4 of 6 capacity, 1 repeat scan. Tier bars, timeline (busiest 11:30 AM) and revenue (₱300 door cash) display correctly. The PDF was rendered and inspected (two pages; header alignment fixed afterwards).
- **Sharing:** shared v1 with a note; history shows "Version 1 · shared … by Owen Cruz"; the button offers "Share version 2".
- **Client:** 3 unread notifications including the report. The report page shows the note, the same figures and the guest list, and no revenue. The event card shows "3 of 4 guests arrived · + 1 walk-in · 4 people in total" and the report link.
- E2E data purged.

## Open items
- The organizer **analytics dashboard** (bid win/loss, attendance trends by month and quarter) is **B15**.
- Seating-zone occupancy (the paper's "dynamic seating allocation") isn't modelled beyond tier zones.
- The report doesn't include the preparation checklist or budget; the paper keeps finance tracking out of scope.
