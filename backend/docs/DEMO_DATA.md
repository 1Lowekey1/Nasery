# Demo data and admin accounts (handoff)

Status: **2026-09-26**. For the advisor check and the final presentation.

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
| Organizer (owner) | `maria@` | **Davao Dream Events**: Pro plan (yearly); staff, completed wedding with report and 5★ review, festival with ticket sales, a refund (fee claim), lost and pending bids |
| Organizer (owner) | `carlo@` | **Mindanao Grand Occasions**: Starter plan; **live event right now** (Davao Tech Summit), upcoming birthday with checklist in progress, completed christening with a **reported 3★ review**, a support invite to NaSeRy |
| Organizer (owner) | `liza@` | **Samal Island Weddings**: Free plan; quoted a debut; upcoming paid concert |
| Organizer (owner) | `jomar@` | **Toril Party Planners**: application **pending** approval |
| Organizer (owner) | `rhea@` | **Buhangin Balloons & Co.**: application **under review** |
| Staff | `ken@`, `aira@`, `paolo@` | Davao Dream team: Coordinator, Check-in Staff, Finance |
| Client | `ella@` | Booked Davao Dream: completed wedding, shared report, her review |
| Client | `miguel@` | Corporate inquiry: **two quotations to compare**, message threads |
| Client | `sofia@` | Debut inquiry: one quotation, one organizer declined |
| Client | `joshua@` | Booked Mindanao Grand: upcoming birthday, follow preparation |
| Client | `andrea@` | Completed christening, left a 3★ review (reported) |
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
