# Demo day: NaSeRy with a year of data + a live ticket-to-door event

## Before the demo (5 minutes)

1. Double-click **`start.bat`** → **1** (public). Wait for the link (it is copied to the clipboard).
2. Optional, for a clean slate: in `backend\` run
   `C:\xampp\php8.5.10\php.exe artisan nasery:demo:event --reset`
   (removes test tickets bought earlier; the rest of the data stays).
3. Open the link on the phone that will scan tickets and sign in as the check-in staff (below).

## Accounts

Every demo account uses the password in `backend\.env` → `DEMO_PASSWORD` (set by us, never e-mailed).
They end in `@demo.nasery.test`, so NaSeRy never sends them real e-mail.

| Use it for | E-mail |
|---|---|
| Organizer owner (Davao Dream Events, runs the demo event) | `maria@demo.nasery.test` |
| Check-in staff (scans QR codes only) | `aira@demo.nasery.test` |
| Event coordinator / finance staff | `ken@demo.nasery.test` / `paolo@demo.nasery.test` |
| Another organizer (Mindanao Grand Occasions) | `carlo@demo.nasery.test` |
| Mindanao Grand staff (coordinator / check-in) | `jessa@demo.nasery.test` / `mark@demo.nasery.test` |
| Client with bookings, reviews and tickets | `ella@demo.nasery.test`, `miguel@demo.nasery.test`, `bea@demo.nasery.test` |
| Demo platform admin | `admin@demo.nasery.test` (your own super-admin account works too) |

Pending / under-review / rejected organizer applications: Toril Party Planners, Buhangin Balloons, Quick Party PH.

## The live flow: buy a ticket → get the QR → scan at the door

Event: **NaSeRy Live Demo Night** (Events page). Its check-in is open at any hour: the scheduler
keeps its start within the next ~2 hours (check-in opens 3 hours before the start).

1. **Buyer** (a panelist's phone): sign in as a demo client (e.g. `bea@demo.nasery.test`), or let the
   panelist **Register** with their own e-mail (they get a real 6-digit code). Then
   Events → NaSeRy Live Demo Night → pick a ticket.
   - **Free Pass**: no payment, the QR ticket appears immediately (also e-mailed to real addresses; demo accounts get no e-mail).
   - **Regular ₱150 / VIP ₱500**: goes to PayMongo **test mode**. Pay with one of PayMongo's
     published test cards (developers.paymongo.com → "Testing"); no real money moves.
2. The buyer shows the QR (from the e-mail, or My Tickets when signed in).
3. **Door** (signed in as `aira@`): Ticketing & Attendance → QR Check-in tab → pick the event → scan the QR with the camera.
   It says *Welcome, …*; scanning the same QR again says *Already checked in*.
4. **Organizer** (`maria@`): the event's dashboard shows the sale, the payment and the check-in live;
   Earnings shows the ticket share.

## What else is in the data (about a year of use)

- 12 organizers: 9 approved (with services, detailed price lists, plans and paid invoices), 3 in review/pending/rejected.
- ~50 clients; 54 inquiries with 75 quotations (won, lost, declined, cancelled), 47 completed events
  with guest lists, check-ins, walk-ins, shared reports and reviews (some with organizer replies, one reported).
- Preparation on every event: event teams, checklists with tasks assigned to the owner and staff, and an
  activity timeline (milestones + event-day program; guest-facing items visible to the client). Past events are
  100% done; upcoming ones are in progress, e.g. **Santos–Lim Wedding** (40 days), **Mintal Agri 25th Anniversary
  Night** (9 days, Davao Dream) and **Joshua's 18th Birthday** (Mindanao Grand) with tasks waiting for review,
  sent back with changes, overdue and blocked. Sign in as `ken@` or `aira@` to see "my tasks".
- 10 past public ticketed events (one about every month) and 5 upcoming ones on sale, one happening right now.
- Payouts to organizers, a support-access request and an announcement for admins.

## Commands (in `backend\`)

| Command | What it does |
|---|---|
| `php artisan nasery:demo:event` | Shows the demo event, its link and ticket/check-in counts (keeps it open) |
| `php artisan nasery:demo:event --reset` | Fresh demo event: retires the old one and the tickets bought on it |
| `php artisan nasery:demo:seed --fresh` | Rebuilds ALL demo data from scratch (backup taken automatically; real accounts untouched) |

(`php` = `C:\xampp\php8.5.10\php.exe`.)
