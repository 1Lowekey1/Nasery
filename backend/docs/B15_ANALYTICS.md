# B15: Organizer analytics (spec and handoff)

Status: **implemented 2026-09-25**. No migration: everything is aggregated from existing data. Next phase: **B16 Reviews**.

Paper: an analytics dashboard with bid history, win and loss rates, inquiry trends, event attendance averages, and trends by **month and quarter**; plus **live event-day analytics** (check-in rate, walk-in vs registered ratio, capacity fill). Figures reflect only activity inside NaSeRy (paper limitation), and new organizers will see little at first.

## Definitions

| Metric | Definition |
|---|---|
| Inquiry | An invitation or match to the organization (`inquiry_organizations`), counted by the date it arrived |
| Bid | An inquiry the organization sent at least one quotation for. **Counted once per inquiry**, so revisions don't double count. |
| Outcome | From the organization's latest non-draft quotation:<br>• `won` (accepted), `lost` (rejected: the client chose someone else or declined), `pending` (awaiting the client)<br>• with no quotation: `declined` (the organizer said no), `open` (the inquiry is still receiving bids), `missed` (it closed before an answer)<br>• withdrawn or cancelled quotes count as neither won nor lost |
| Win rate | won ÷ (won + lost) |
| Response rate | bids ÷ inquiries |
| Typical response | **Median** time from invitation to the first quotation sent |
| Average quote / value won | Final quote per bid; sum of accepted quotations |
| Event attendance | Same definitions as the B14 report, for events whose first session falls in the period. Rates are **weighted** (sum attended ÷ sum expected over completed events), not an average of per-event percentages. |
| Walk-in share | Walk-ins without a ticket ÷ everyone welcomed |
| Revenue | Online net (paid − fee kept − refunds) + door cash, per event; `finance.view` only |
| Live | An event whose door is open now (B11 window) or that is marked ongoing: check-in rate, walk-ins vs registered, **booked capacity** (tickets + walk-ins, what the door enforces) and **occupancy** (people inside ÷ capacity), arrivals in the last 15 minutes, latest arrivals, per-tier progress; plus events starting within 7 days |

Trends: `range` = 3m / 6m / **12m** / ytd / 24m; `group` = **month** or **quarter** (Asia/Manila). Won/lost are bucketed by the date the client decided; bids by the date the first quotation was sent.

## Access (per section)

| Section | Who |
|---|---|
| Bid performance | `inquiry.manage` or `quotation.manage` |
| Event performance | Members who see every event (event-wide roles, B8) |
| Revenue | `finance.view` (on top of the above) |
| Live events | Any member, for events they can work on (`checkin.perform`, `event.update` or `attendee.manage`) |

A member with neither bid nor event-wide access gets 403 on the overview; the frontend takes them to Live events. The sidebar link now shows for finance, inquiry, quotation, event-update and check-in roles (it used to need `finance.view`).

## Endpoints
| Method | Path | Returns |
|---|---|---|
| GET | `/organizations/{org}/analytics?range=&group=` | `period`, `bids` (totals, trend, by event type, 25-row history), `events` (totals, trend, per-event rows), `can` |
| GET | `/organizations/{org}/analytics/live` | `live[]`, `upcoming[]` |

## Frontend
- `components/analytics-tabs.vue`: tabs plus **one filter row** (range and Monthly/Quarterly) kept in the URL, so switching tabs keeps the filters.
- `components/analytics/BarChart.vue` and `LineChart.vue` follow the data-viz method:
  - one axis; categorical slots 1–2 of the validated reference palette (blue `#2a78d6`, orange `#eb6834`: validator PASS, CVD ΔE 24.7). They replace the mock's red/green, which fails for red-green color blindness and collides with status colors.
  - bars: thin, 4px rounded tops, 2px gaps; lines: 2px with 8px markers and a surface ring
  - recessive dashed grid, whole-number count axes
  - legend only for 2+ series
  - hover tooltip (and crosshair on lines); **"Show as table"** on every chart
  - gaps shown as gaps, not zero
- Pages:
  - **Overview:** four tiles, the inquiry → quotation → won funnel, wins by event type, recent events.
  - **Bid performance:** five tiles, won-vs-lost and inquiries-vs-quotations charts, bid history. Outcome badges use an icon plus a label, never color alone.
  - **Event performance:** five tiles, the attendance vs no-show line, events per period, results table linking to each report.
  - **Live events:** refreshes every 10 seconds; links to QR check-in and the walk-in counter.
- `useAnalytics()` caches one overview per range+group, shared by the three tabs.

## Tests
`tests/Feature/Analytics/AnalyticsTest.php` (3):
- **Bid outcomes:** one inquiry each won, lost to a rival, pending with a revision (not double counted), declined, missed (the client cancelled) and open. Checks totals, 50% win and response rates, value won, this month's trend bucket, by event type, history, and that the rival sees only its own bids.
- **Event performance:** 3 of 4 attended plus 1 walk-in on a 10-seat venue gives 75% attendance, 25% no-show, 25% walk-in share and 40% capacity fill; revenue; the upcoming event isn't averaged; quarter labels and buckets.
- **Live and roles:** the live event's numbers (50% check-in rate, 66.7% walk-in ratio, 40% booked vs 30% occupancy, +3 in 15 minutes, upcoming list). Door staff get 403 on the overview and see only their event live; other organizations get 404 and see only their own.

**Verification (2026-09-25)**, browser run on seeded data (a won wedding with a completed event, a corporate event lost to a rival, a pending quote, a declined debut, an unanswered inquiry, a live food fest, an upcoming concert):
- **Overview:** win rate 50%, 3 bids of 5 inquiries, 75% attendance, funnel and by-type table correct.
- **Bids:** charts render cleanly (checked on screen); history badges and amounts correct.
- **Events:** the line chart places September at 75% and 25%, with empty months left blank.
- **Live:** 66.7% check-in, 3 : 4 walk-ins, 7 inside of 20 (35%, 45% booked), +7 in 15 minutes, the upcoming concert.
- **Fixed during the run:** duplicate y-tick for tiny counts, "0 h" response time (now "1 min"), "1 walk-ins", a duplicated session time on Live.
- E2E data purged.

## Open items
- The admin platform-wide analytics (all organizers) belong to **B17**.
- No export of analytics yet (reports have PDF/CSV). Could add CSV of the trend tables.
- Quotation "average quote" includes pending bids; per-event-type pricing insight could come later.
