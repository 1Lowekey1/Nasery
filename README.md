# NaSeRy

**An event management platform for Davao City**: clients find and book event organizers, organizers plan and run the event with their team, and guests get QR tickets they show at the door.

**Live prototype:** https://nasery.bid
**Version:** v1.0-rc3 (capstone prototype, November 2026)

> NaSeRy is a capstone project. Online payments run in **test mode only** (PayMongo test keys or a built-in demo checkout); no real money moves.

---

## What it does

NaSeRy has four kinds of users, each with their own portal.

### Clients (people planning an event)
- Browse organizers on the **Marketplace** by event type, service, budget and distance.
- **Post an event request** once; matching organizers send **offers** (itemised quotations).
- Compare offers side by side, accept one, and **message** each organizer privately.
- Follow the event's preparation, see the program, get the final report, and leave a review.

### Organizers (event businesses)
- Business profile, documents for verification, price list and services.
- **Inquiry & Bidding:** receive matching requests and answer with quotations (optional VAT).
- **Event Management:** event details, guests and tickets, preparation tasks, event program, and a team with roles and permissions.
- **Ticketing & Attendance:** ticket types, private or public events, guest list import (CSV/XLSX), QR check-in, walk-ins, payments and refunds.
- Analytics, reviews, earnings and payouts, plan and billing.

### Attendees (ticket buyers)
- Find public events, buy or register for tickets, with or without an account.
- QR tickets by e-mail and in **My Tickets**; ask for a refund from there.

### NaSeRy admins
- Approve organizers, moderate reviews, manage event and service tags.
- Platform analytics, payouts to organizers, refunds overview, settings, activity log.

---

## How it's built

| Part | Technology |
|---|---|
| Frontend | [Nuxt 4](https://nuxt.com) (Vue 3, single-page app), Tailwind CSS 3 |
| Backend API | [Laravel 13](https://laravel.com) on PHP 8.5 |
| Database | PostgreSQL 18 with row-level security (each organization only sees its own data) |
| Payments | PayMongo (test mode) or a built-in demo checkout |
| Background work | Laravel queue (e-mails, notifications) and scheduler (reminders, clean-up) |
| Hosting | One Ubuntu server on AWS Lightsail (Sydney), behind Cloudflare |

```
Browser ──► Nuxt frontend (static files)
               │  /api/v1 (JSON, cookie sign-in)
               ▼
           Laravel API ──► PostgreSQL 18 (row-level security)
               │
               ├── queue worker  → e-mails and notifications
               └── scheduler     → reminders, expiries, picture clean-up
```

---

## Repository layout

```
backend/                  Laravel API
  app/                    Controllers, services (business rules), models
  database/supabase/      Database schema as numbered SQL migrations (each with a .down.sql)
  database/local/         Scripts to run PostgreSQL on a Windows laptop
  docs/                   Design notes per backend phase (B0–B18)
  tests/                  PHPUnit feature and unit tests
frontend/                 Nuxt app
  app/pages/              One file per screen, grouped by portal (client/, organizer/, superadmin/, team/)
  app/components/         Shared building blocks
  app/composables/        API calls and shared state
  tests/                  Vitest unit tests
scripts/                  Deploy, server update and backup scripts
DEMO_SETUP_GUIDE.md       Running the prototype on a laptop for a demo
DEMO_DAY.md               Demo-day checklist
REVIEW_GUIDE.md           How to review and test the prototype
```

---

## Running it locally

**You need:** PHP 8.5 (with the `pdo_pgsql`, `gd` and `exif` extensions), Composer, Node.js 22+, and PostgreSQL 18.

1. **Database.** Follow [`backend/database/local/README.md`](backend/database/local/README.md). It creates the database, builds the schema from the SQL migrations and points `backend/.env` at it.
2. **Backend**
   ```bash
   cd backend
   cp .env.example .env        # then fill in the values (ask the team; never commit .env)
   composer install
   php artisan key:generate
   php artisan nasery:doctor   # checks the setup
   php artisan serve --port=8000
   php artisan queue:work      # in a second terminal: sends e-mails and notifications
   ```
3. **Frontend**
   ```bash
   cd frontend
   cp .env.example .env        # API address (localhost:8000) and the map key
   npm install
   npm run dev                 # http://localhost:3000
   ```
4. **Demo data** (optional): `php artisan nasery:demo:seed` creates organizers, clients, events and tickets around Davao City. See [`backend/docs/DEMO_DATA.md`](backend/docs/DEMO_DATA.md).

Open the site as `http://localhost:…`, not `127.0.0.1`: sign-in cookies only work on `localhost`.

---

## Tests

```bash
cd backend && php artisan test     # 337 tests: API, permissions, payments, row-level security
cd frontend && npx vitest run      # 45 unit tests
cd frontend && npx nuxi typecheck  # type check
```

---

## Deploying

`bash scripts/nasery-deploy.sh` builds the frontend on the laptop, sends it and the backend to the server, runs any new database migrations, restarts the workers and checks the public site. The server keeps a nightly backup of the database and uploaded files for 7 days.

Secrets (`.env` files, server keys, payment keys) never go in this repository.

---

## Team

Capstone project, <!-- school / program -->, 2026.

| Name | Role |
|---|---|
| <!-- name --> | <!-- e.g. Project lead, backend --> |
| <!-- name --> | <!-- e.g. Frontend and design --> |
| <!-- name --> | <!-- e.g. Documentation and testing --> |
| <!-- name --> | <!-- e.g. Research and QA --> |

Adviser: <!-- name -->

---

## License

All rights reserved. This code is shared for academic review; please ask the team before reusing it.
