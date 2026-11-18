# Demo data and admin accounts (handoff)

Status: **2026-11-02**. For the advisor check and the final presentation.

## Commands
| Command | What it does |
|---|---|
| `php artisan nasery:demo:seed` | Builds the demo dataset (first time). Refuses if it already exists. |
| `php artisan nasery:demo:seed --fresh` | **Reset before a presentation**: deletes the demo accounts and everything they own (after a backup), then rebuilds. |
| `php artisan nasery:test-data:purge --everyone --force` | Clean slate: deletes **every** account and organization (after a backup), keeping reference data (standard event types, service catalog, plans, platform settings, fee history). |
| `php artisan nasery:test-data:purge --domain=demo.nasery.test --force` | Only the demo accounts. |
| `php artisan nasery:admin:create you@example.com --first=Ana --last=Cruz --invite` | Creates the **first** admin without a password and e-mails a code; they set their own password on "Forgot password". After that, add admins in the app. |

Run them with PHP 8.5.10: `C:\xampp\php8.5.10\php.exe artisan …`

**Password:** every demo account uses `DEMO_PASSWORD` from `backend/.env` (set by the team; the seeder refuses to run without it and never prints it). Demo e-mails end in `@demo.nasery.test`, which never receives mail. E-mail is switched off during seeding, so rebuilding never mails your real admins.

## Accounts (all `@demo.nasery.test`)
| Role | E-mail | Who / what to show |
|---|---|---|
| Admin | `admin@` | Dana Admin: approvals, moderation, fee claims, support invite, analytics |
| Organizer (owner) | `maria@` | **Davao Dream Events**: Pro plan (yearly), 11 price options (the offer builder folds long lists); staff, completed wedding with report and 5★ review, festival with ticket sales, a refund (fee claim), lost and pending bids |
| Organizer (owner) | `carlo@` | **Mindanao Grand Occasions**: Starter plan; **live event right now** (Davao Tech Summit), upcoming birthday with checklist in progress, completed christening with a **reported 3★ review**, a support invite to NaSeRy |
| Organizer (owner) | `liza@` | **Samal Island Weddings**: Free plan; quoted a debut; upcoming paid concert |
| Organizer (owner) | `jomar@` | **Toril Party Planners**: application **pending** approval, no documents yet (upload one to unlock Approve) |
| Organizer (owner) | `rhea@` | **Buhangin Balloons & Co.**: application **under review** |
| Staff | `ken@`, `aira@`, `paolo@` | Davao Dream team: Coordinator, Check-in Staff, Finance |
| Client | `ella@` | Booked Davao Dream: completed wedding, shared report, her review |
| Client | `miguel@` | Corporate inquiry: **two quotations to compare**, message threads |
| Client | `sofia@` | Debut inquiry: one quotation, one organizer declined |
| Client | `joshua@` | Booked Mindanao Grand: upcoming birthday, follow preparation, with a cancellation request waiting for Carlo |
| Client | `andrea@` | Completed christening, left a 3★ review (reported); open "Other" request (Davao Pet Adoption Fair) waiting for Maria's offer |
| Client | `rafael@` | Older booking (analytics history) |
| Buyers | `bea@`, `carl@`, `dana@`, `enzo@`, `faith@`, `gino@` | Ticket holders across the public events (QR tickets under My Tickets) |

## What's in it
- **Service catalog:** 10 standard services (reference data, kept by purges).
- **Organizers:** each approved one has services, a published profile and (Davao Dream, Mindanao Grand) a price-list calculator.
- **Inquiries in every state:** won, lost, pending decision, declined, with threads.
- **Private booked events:**
  - completed: the wedding and the christening, with guest lists, check-ins, walk-ins and headcount
  - upcoming: the birthday
- **Public events:**
  - Kadayawan Street Food Festival (on sale, 3 tiers, several buyers, one force-majeure refund)
  - Davao Tech Summit (**live now**: doors open, check-ins and walk-ins)
  - Sunset Acoustic Night (upcoming)
  - Davao Food Expo (last month, completed)
- **Organizer field test (2026-11-18):**
  - Davao Dream's and Mindanao Grand's main price list items have a description and an "includes" list (shown as package cards on their pages). No pictures: organizers add their own.
  - Davao Dream doesn't charge VAT on **weddings** (set after the past bookings, so their offers keep VAT). **Faith & Marco Garden Wedding** (Faith, open, no offer yet): Maria's offer has no VAT. Miguel's corporate offers show VAT as "Miscellaneous charges".
  - Mood boards (colour palettes): Faith's wedding (2), Miguel's Northwind anniversary (1), Bea's **Santos–Lim Wedding** (3), which Ken sees in My Assignments.
  - **Enzo's refund request** on the Kadayawan Street Food Festival waits for Maria (Ticketing & Attendance → Payments: Refund or Decline).
- **Newer features (2026-11):**
  - requests carry the client's event name ("Northwind Davao 10th Anniversary", "Sofia's Sunset Debut"); Rafael's has none, so it shows the default "Reunion for Rafael …"
  - event tags include **Seminar** (Mindanao Grand, Davao Ushers) and **Reunion** (Matina Kitchen, Calinan Garden)
  - some price options are for certain event types (e.g. Davao Dream's "Full wedding coordination" = Wedding); the rest show "All events"
  - **final agreed price** on the completed wedding (₱197,500, more than the ₱185,000 offer) and christening (₱45,000, a discount)
  - **shared tasks**: the check-in briefing and check-in setup have two assignees
  - **event type suggestions**: Gender Reveal (approved), Kiddie Party (merged into Birthday Party), Product Launch (waiting on Admin → Event Tags)
  - Quick Party PH is **rejected** (its owner sees the reviewer's note and "Resubmit for review")
- **Earnings (for the business-model demo):** the ticket fee is **5%** of each online sale (Admin → Settings; plans don't change it), and Admin → Overview opens with "What NaSeRy earns": monthly recurring revenue, paying vs free organizers, and the last 12 months split between plans and ticket fees.
- **Platform:** a welcome announcement, a pending support invite, a pending fee claim, a reported review, 2 organizer applications, and subscription revenue (Pro yearly + Starter monthly).

"Live now" and "upcoming" are relative to when you run the seeder: **run `--fresh` on the day of the presentation** (it takes several minutes against Supabase).

## Admin team (in the app)
Admin → **Admin Team**:
- **Add admin:** name and e-mail (an address with no NaSeRy account yet). They get "*X added you as a NaSeRy administrator*" with a code, and set their password on "Forgot password".
- **Resend code**, and **Disable / Enable** with a reason (signs them out everywhere; you can't disable yourself).
- Everything is in the Activity Log.

Tests: `tests/Feature/Admin/AdminTeamTest.php`:
- invite; can't sign in until a password is set; the e-mail says who added them; sets password, signs in and is verified
- duplicate e-mail refused; resend cooldown; disable / enable
- the first admin can be invited from the server

## Payments: PayMongo test mode
With `PAYMONGO_DRIVER=test` and PayMongo **test** keys in `backend/.env`, ticket purchases and plan upgrades go through PayMongo's real checkout page (test mode, no real money). Pay with **GCash → "Authorize Test Payment"**, or a PayMongo test card from their docs.
- No webhook is needed for the demo: when the buyer returns to NaSeRy, the return page asks PayMongo and confirms the payment. (Closing the PayMongo tab before returning leaves the payment pending; the ticket hold then expires.)
- The seeded demo sales always use the built-in demo checkout, and refunding them never calls PayMongo.
- Bad venue internet? Set `PAYMONGO_DRIVER=fake` and restart the API: the built-in demo checkout takes over.
- Tests never call PayMongo (`phpunit.xml` pins `PAYMONGO_DRIVER=fake`).
- PHP 8.5.10 needs a CA bundle to reach PayMongo: `curl.cainfo` / `openssl.cafile` in `C:\xampp\php8.5.10\php.ini` point to `extras\ssl\cacert-….pem` (from https://curl.se/ca/cacert.pem).

## Running the API for the presentation (Apache, free)
`php artisan serve` answers one request at a time; every page sends several. For the demo, run the API on XAMPP's Apache instead: a **separate** Apache process with PHP 8.5.10 on the same address (`localhost:8000`), so XAMPP's own Apache and PHP 8.2 are untouched.

From `backend/`:
```
C:\xampp\apache\bin\httpd.exe -f "%CD%\server\apache-local.conf"
```
(Stop with Ctrl+C. Don't run `artisan serve` at the same time: both want port 8000.) Also keep `php artisan queue:work` running for e-mails.

What it does (all in `server/apache-local.conf`, nothing in `.env`):
- 4 worker threads answer requests in parallel (each keeps up to 2 Supabase connections; the free plan allows 15 in total, and the queue worker and tests need some).
- Reuses Supabase connections between requests (`DB_PERSISTENT`): connecting to Supabase (Tokyo) costs ~1 s. Safe with RLS: every role/claim is `SET LOCAL`.
- Rate-limit counters in local files (`CACHE_STORE=file`). Sessions stay in the database (signed-in devices, "sign out everywhere").
- Logs: `storage/logs/apache-error.log`, `apache-access.log` (last number = microseconds).

The first request on each thread is slower (it opens the connection): click through the demo once before presenting.
