# NaSeRy — Demo Setup & Presentation Guide

For whoever runs the NaSeRy prototype on their own laptop (Windows 10/11).
Last updated: **2026-09-30** (first prototype presentation to the adviser).

> **Read this first — three rules that avoid 90% of problems**
> 1. **Only ONE computer runs the backend at a time.** Every laptop talks to the same free Supabase database, which accepts only 15 connections. Two backends running at once = "EMAXCONNSESSION" errors and pages that never load.
> 2. **Never share the `.env` files through Git, GitHub, Messenger or e-mail.** They hold the database password and API keys. Hand them over on a USB drive or in person, and delete any copy you don't need.
> 3. **Always open the site as `http://localhost:3000`**, never `127.0.0.1:3000`. Sign-in cookies only work on `localhost`.

---

## 1. What runs where

```
 Browser  ──►  Frontend (Nuxt, npm run dev)      http://localhost:3000
                     │  calls
                     ▼
               Backend API (Laravel on Apache)    http://localhost:8000/api/v1
                     │  PHP 8.5.10 (Apache module)
                     ▼
               Supabase Postgres (cloud, shared by everyone)
                     ▲
               Queue worker (php artisan queue:work)  – sends notifications & e-mail
```

Three things must be running during the demo: **Apache (API)**, **the queue worker**, and **the frontend**.

Folder layout of the repository:

```
Dev/
├─ backend/               Laravel 13 API
│  ├─ server/apache-local.conf   ← the Apache config for the API (you edit 1 line)
│  ├─ docs/DEMO_DATA.md          ← what's in the demo data
│  └─ .env                       ← secrets (NOT in Git — get it from Nathan)
├─ frontend/              Nuxt 4 website
│  └─ .env                       ← frontend settings (NOT in Git — get it from Nathan)
└─ DEMO_SETUP_GUIDE.md    ← this file
```

---

## 2. One-time setup on a new laptop

Budget about **1–1.5 hours** (mostly downloads). Do it at least a day before the presentation.

### 2.1 Install the tools

| Tool | Version we use | Where to get it | Notes |
|---|---|---|---|
| Git | 2.51+ | git-scm.com | Default options are fine. |
| Node.js | **24 LTS** (we use 24.14) | nodejs.org | Comes with npm 11. |
| XAMPP | any recent (we use Apache **2.4.58**) | apachefriends.org | Install to **`C:\xampp`**. We only use its Apache. You do **not** need to start XAMPP's control panel. |
| Microsoft Visual C++ Redistributable 2015–2022 **x64** | latest | learn.microsoft.com → "Latest supported Visual C++ Redistributable" | PHP 8.5 and Apache need it. |
| PHP | **8.5.10, VS17 x64 _Thread Safe_** | see 2.3 | Must be the **Thread Safe** build (it contains `php8apache2_4.dll`). |
| Composer | 2.9+ | see 2.5 | Installs the backend's PHP packages. |
| PostgreSQL command-line tools | 18 | enterprisedb.com (PostgreSQL 18 installer → tick only **Command Line Tools**) | *Optional.* Only needed to run backups/migrations/reseeding (`pg_dump`). The presentation machine should have it if **you** will reseed on the day. |

> Do **not** change the PHP that XAMPP ships (8.2) and do not put PHP 8.5 on the system PATH. We always call PHP 8.5 by its full path: `C:\xampp\php8.5.10\php.exe`.

### 2.2 Get the code

```powershell
cd $HOME\Desktop
git clone https://github.com/1Lowekey1/Nasery.git NaSeRy-Dev
cd NaSeRy-Dev
git checkout frontend-merge      # the branch with the new frontend (until it is merged into main)
```

From here on, `<REPO>` means the folder you cloned into, e.g. `C:\Users\<you>\Desktop\NaSeRy-Dev`.

> Avoid putting the repo inside a OneDrive folder on the laptop if you can: OneDrive syncing can lock files while `npm install` runs.

### 2.3 PHP 8.5.10

**Option A — copy it from Nathan's PC (easiest, recommended).**
Copy the whole folder `C:\xampp\php8.5.10` from Nathan's PC to the **same path** on the laptop (`C:\xampp\php8.5.10`). Its `php.ini` already has the right extensions and certificate settings, and all paths match. Then skip to the check below.

**Option B — install from scratch.**
1. Download **PHP 8.5.10 — VS17 x64 Thread Safe (zip)** from windows.php.net/download and unzip it to `C:\xampp\php8.5.10`.
2. Copy `php.ini-development` to `php.ini` in that folder and edit `php.ini`:
   ```ini
   extension_dir = "C:/xampp/php8.5.10/ext"

   extension=curl
   extension=fileinfo
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_pgsql
   extension=pgsql
   extension=sodium
   extension=zip

   ; HTTPS certificates (needed for Supabase, PayMongo and e-mail)
   curl.cainfo   = "C:\xampp\php8.5.10\extras\ssl\cacert.pem"
   openssl.cafile = "C:\xampp\php8.5.10\extras\ssl\cacert.pem"
   ```
   (Remove the `;` in front of each `extension=` line that exists already.)
3. Download `cacert.pem` from **curl.se/docs/caextract.html** and save it as `C:\xampp\php8.5.10\extras\ssl\cacert.pem` (create the folders if needed).

**Check (both options):**
```powershell
C:\xampp\php8.5.10\php.exe -v            # PHP 8.5.10 (cli) ... (ZTS Visual C++ 2022 x64)
C:\xampp\php8.5.10\php.exe -m            # must list: curl intl mbstring openssl pdo_pgsql pgsql sodium zip
```
If `-v` fails with a missing DLL → install the Visual C++ Redistributable (2.1).

### 2.4 The secret files (`.env`)

Get **`backend/.env`** and **`frontend/.env`** from Nathan (USB / in person) and put them at:
```
<REPO>\backend\.env
<REPO>\frontend\.env
```
Use them **as they are** — they already contain the database, `APP_KEY`, `DEMO_PASSWORD`, PayMongo **test** keys, and mail settings. Nothing in them depends on the computer, except one optional line:

- `PG_DUMP_PATH` — where `pg_dump.exe` is (only for backups). Default install location:
  `PG_DUMP_PATH="C:/Program Files/PostgreSQL/18/bin/pg_dump.exe"`

`frontend/.env` should include the **LocationIQ** token (place suggestions + map preview in Post an Event and the organizer profile):
```
NUXT_PUBLIC_LOCATION_IQ_API_KEY=<token>
```
Without it the site still works: people type the venue as text and can pin it with "Use my current location". After changing `frontend/.env`, restart `npm run dev`.

### 2.5 Backend packages (Composer)

Composer as a single file, run with PHP 8.5 (so it never picks XAMPP's PHP 8.2 by mistake):

```powershell
mkdir C:\composer
C:\xampp\php8.5.10\php.exe -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', 'C:/composer/composer.phar');"

cd <REPO>\backend
C:\xampp\php8.5.10\php.exe C:\composer\composer.phar install
```

Then check the backend can reach the database (this reads only — it changes nothing):
```powershell
C:\xampp\php8.5.10\php.exe artisan nasery:doctor
```
Expected last line: **`All checks passed.`** If it says it can't connect → see Troubleshooting.

> Do **not** run `composer require laravel/boost` / `php artisan boost:install` or the "php.new" installer mentioned in `backend/CLAUDE.md`. They are not part of this project's setup.

### 2.6 Apache for the API (the important part)

We run a **separate, private Apache** just for the API, on port **8000**, with PHP 8.5.10 loaded as a module. It does **not** touch XAMPP's own Apache config or its PHP 8.2.

Why Apache and not `php artisan serve`? `artisan serve` answers one request at a time, and every page asks the API for 5–10 things at once — pages would take 30+ seconds. Apache answers them in parallel.

**Step 1 — point the config at your folders.** Open `<REPO>\backend\server\apache-local.conf` and edit the three `Define` lines at the top:

```apache
Define XAMPP_APACHE "C:/xampp/apache"          # where XAMPP's Apache is (usually unchanged)
Define NASERY_PHP   "C:/xampp/php8.5.10"       # PHP 8.5.10 folder (unchanged if you followed 2.3)
Define NASERY_ROOT  "C:/Users/<you>/Desktop/NaSeRy-Dev/backend"   # ← YOUR backend folder
```
Use **forward slashes** `/`, no trailing slash.

> Don't commit this edit back to Git (it's your personal path). If Git shows it as changed, that's expected — just don't include it in your commits: `git update-index --skip-worktree backend/server/apache-local.conf`

**Step 2 — check the config:**
```powershell
cd <REPO>\backend
C:\xampp\apache\bin\httpd.exe -t -f "$PWD\server\apache-local.conf"
```
Expected: **`Syntax OK`**.

**Step 3 — start it:**
```powershell
cd <REPO>\backend
C:\xampp\apache\bin\httpd.exe -f "$PWD\server\apache-local.conf"
```
The window stays open with no output — that means it is running. **Leave this window open**; closing it (or `Ctrl+C`) stops the API.
If Windows Firewall asks, you can click **Cancel**: the API only listens on this computer (127.0.0.1).

**Step 4 — test it** (in another PowerShell window):
```powershell
curl.exe http://localhost:8000/up                                  # → 200 page
curl.exe http://localhost:8000/api/v1/marketplace/event-types      # → JSON list of event types
```

What the config does (so you know what not to change):

| Setting | Why |
|---|---|
| `Listen 127.0.0.1:8000` | Same address the frontend expects. |
| `LoadFile … .dll` lines | Loads PHP 8.5's own OpenSSL/ICU/libpq DLLs first. XAMPP ships older copies that would crash PHP 8.5. |
| `LoadModule php_module … php8apache2_4.dll` + `PHPIniDir` | Runs PHP 8.5.10 inside Apache with its own `php.ini`. |
| `SetEnv DB_PERSISTENT true` | Re-uses database connections between requests (much faster with Supabase). |
| `SetEnv CACHE_STORE file` | Keeps rate-limit counters on disk instead of in Supabase. |
| `SetEnv FRONTEND_URLS` / `SANCTUM_STATEFUL_DOMAINS` | Which website addresses may sign in (`localhost:3000`). |
| **`ThreadsPerChild 6`** | 6 requests in parallel. Each thread may hold 2 Supabase connections → 12, + 2 for the queue worker = **14 of Supabase's 15**. **Do not raise it.** Because of this limit, don't run tests/migrations/other artisan commands that use the database while Apache is running — stop Apache first. |
| Logs | `backend/storage/logs/apache-error.log` and `apache-access.log` (the last number on each access-log line is the time in microseconds). |

### 2.7 Frontend

```powershell
cd <REPO>\frontend
npm install
```
(One-time; takes a few minutes.) Make sure `frontend\.env` from 2.4 is in place.

---

## 3. Starting and stopping (every time)

Open **three PowerShell windows**:

| Window | Commands | Leave it open? |
|---|---|---|
| **1. API (Apache)** | `cd <REPO>\backend`<br>`C:\xampp\apache\bin\httpd.exe -f "$PWD\server\apache-local.conf"` | Yes |
| **2. Queue worker** | `cd <REPO>\backend`<br>`C:\xampp\php8.5.10\php.exe artisan queue:work --tries=3 --sleep=2` | Yes |
| **3. Website** | `cd <REPO>\frontend`<br>`npm run dev` | Yes |

Then open **http://localhost:3000** in Chrome/Edge.

- The **first** page load after `npm run dev` can take 20–40 s (Nuxt compiles pages the first time they're visited). Click through the pages you'll demo once beforehand (see 4) so they're warm.
- Later page loads take **~3–9 s** — that's the free Supabase plan's speed, not a bug.

**To stop:** press `Ctrl+C` in each window (window 1: if `Ctrl+C` doesn't stop Apache, just close the window).

**Optional — scheduler** (expires unpaid ticket reservations, sends reminders, closes stale inquiries). Not needed for a short demo:
`C:\xampp\php8.5.10\php.exe artisan schedule:work` (4th window).

---

## 4. Presentation-day checklist

**The day before**
- [ ] Laptop set up and the full demo walk-through (section 6) done once on it.
- [ ] Laptop charger, and a phone hotspot in case the venue Wi-Fi blocks Supabase.

**On the day, ~1 hour before**
1. [ ] Make sure **no other computer** is running the NaSeRy backend (Apache or queue worker). Ask the team.
2. [ ] **Reset the demo data** — "live now" and "upcoming" events are relative to the day you seed. Run it **once, from one computer**, with **Apache and the queue worker stopped** (it makes a backup first, so `pg_dump` must be installed — see 2.1; otherwise ask Nathan to run it from his PC):
   ```powershell
   cd <REPO>\backend
   C:\xampp\php8.5.10\php.exe artisan nasery:demo:seed --fresh
   ```
   Takes several minutes. Ends with "Demo ready."
3. [ ] Start the three windows (section 3).
4. [ ] **Warm up** (so nothing is slow in front of the panel): sign in and open each page you'll show once — as admin, Maria, Ken, Miguel, Joshua (section 6).
5. [ ] Sign out of everything, close extra tabs, zoom the browser to 100–110%.

---

## 5. Demo accounts

Every demo account's password is the **`DEMO_PASSWORD`** value in `backend/.env` (ask Nathan; it's never written in docs or chat). All e-mails end in **`@demo.nasery.test`** — they never receive real mail.

| Role | E-mail | Use it to show |
|---|---|---|
| Super admin | `admin@demo.nasery.test` | Overview, organizer approvals, payouts, analytics, activity log, event tags |
| Organizer (Pro) | `maria@demo.nasery.test` | **Davao Dream Events** — home, inquiries & offers, events, preparation, ticketing, earnings, analytics, team |
| Organizer (Starter) | `carlo@demo.nasery.test` | **Mindanao Grand Occasions** — a **live event right now** (Davao Tech Summit), check-in & walk-ins |
| Organizer (Free) | `liza@demo.nasery.test` | Samal Island Weddings — plan limits / upgrade |
| Organizer (pending) | `jomar@demo.nasery.test` | Application waiting for admin approval |
| Staff (Coordinator) | `ken@demo.nasery.test` | Team member dashboard: assigned tasks, completion notes |
| Staff | `aira@…` (check-in), `paolo@…` (finance) | Role-based access |
| Client | `miguel@demo.nasery.test` | **Two quotations to compare**, messages with organizers |
| Client | `joshua@demo.nasery.test` | Booked birthday: event details, readiness, milestones |
| Client | `ella@demo.nasery.test` | Completed wedding, shared report, her 5★ review |
| Ticket buyers | `bea@`, `carl@`, `dana@`, `enzo@`, `faith@`, `gino@` | My Tickets (QR codes) |

More detail about the data: `backend/docs/DEMO_DATA.md`.

### 5.1 Real admin accounts for the team

Besides the demo admin, each team admin has their **own** NaSeRy administrator account with a real e-mail. These accounts live in the shared Supabase database, so they work on **any** laptop, and `nasery:demo:seed --fresh` does **not** delete them (it only removes `@demo.nasery.test` accounts).

| Admin | E-mail | Status |
|---|---|---|
| Nathaniel Sevilla | `nathansev715@gmail.com` | Active |
| (team admin) | `ryllemanco555@gmail.com` | To be added |
| Sean Kenneth Axalan | `seankennethaxalan@gmail.com` | To be added |

**Adding an admin** (done once, by an admin who can already sign in):
1. Sign in at `http://localhost:3000/superadmin/login` → **Admin Team** (left menu).
2. **Add admin**: first name, last name, e-mail → *Add admin*. The new admin gets an e-mail "*… added you as a NaSeRy administrator*" with a code.
   - The e-mail must not already belong to a NaSeRy account (client/organizer). Use a different address if it does.
   - Didn't arrive? Check spam, then **Resend code** on the Admin Team page (make sure the queue worker is running — it sends the mail).

**The new admin sets their own password** (on the demo laptop or any computer running NaSeRy):
1. Go to `http://localhost:3000/forgot-password`, enter **your** e-mail, use the code from the e-mail, and choose a password.
2. Sign in at `http://localhost:3000/superadmin/login`.

Nobody else ever types or learns your password — don't share it, and don't save it in the laptop's browser if the laptop is shared.

---

## 6. Suggested demo flow (≈15 minutes)

Use a normal window for one role and an **InPrivate/Incognito** window for a second role, so you can show both sides without signing out.

1. **Public site (no sign-in)** — `http://localhost:3000`
   - Browse organizers → open **Davao Dream Events**: services, portfolio (private events show only type + date), reviews with the organizer's reply.
   - **Quotation calculator**: tick "Wedding coordination package" + "Styling per guest", 100 guests → **₱80,000** estimate.
   - Event Marketplace → a public event with ticket tiers.

2. **Client — Miguel** (sign in)
   - My Events → the corporate inquiry → **Review offers**: two quotations side by side (₱215,000 vs ₱240,000).
   - **Message** an organizer (private thread).
   - *(Optional, changes data)* Accept one quotation → the other is declined automatically.
   - Post Event Inquiry: the form (you don't have to submit).
   - From an organizer page: **Request a quotation with these** → "Direct quotation request" goes only to that organizer.

3. **Organizer — Maria** (second window)
   - Home: ticket share, upcoming events.
   - Inquiry & Bidding → Matching Inquiries (a **Direct request** badge when a client asked only her) → **Submit Offer** with the quotation calculator; My Offers → **View Full Proposal**, **Message Client**.
   - Event Management → an event → **Preparation Tracking**: add a checklist item, assign it to Ken, **Verify** / **Request changes**.
   - Ticketing & Attendance → tiers, attendees, QR check-in, walk-in counter, **Payments**.
   - **Earnings**: "Your Ticket Share" — gross ticket sales minus refunds and NaSeRy's fee, and the payouts received.
   - Business profile panel (click her name, bottom-left): tags, specialties, links, banner color → shows on her public page.

4. **Team member — Ken**: My Assignments → mark a task done with a note → back in Maria's Preparation Tracking it waits for her review; readiness only counts **verified** work.

5. **Client — Joshua**: Event Details (his booked birthday), **Preparation Tracking** (readiness %, stage, milestones — never the organizer's internal task list), **Activity Timeline**.

6. **Super admin**: overview & analytics (platform revenue = subscriptions + NaSeRy fees, not ticket prices), organizer approvals (Jomar), **Payouts**, activity log.

**Payments** are PayMongo **test mode**: no real money moves. On PayMongo's test checkout, use one of PayMongo's published **test card numbers** (developers.paymongo.com → Testing). If asked: in production the same flow uses live keys.

---

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Pages spin forever; API log shows **`EMAXCONNSESSION`** / "max clients reached" | Too many Supabase connections. Make sure **only one** computer runs Apache + queue worker. Stop everything, wait 1 minute, start again. Don't run tests/artisan DB commands while Apache runs. |
| `httpd.exe` closes immediately | Run the `-t` check from 2.6. Then read `backend\storage\logs\apache-error.log`. Common: wrong `NASERY_ROOT`, or port 8000 in use (below). |
| "**(OS 10048) Only one usage of each socket address**" / `make_sock: could not bind to address 127.0.0.1:8000` | Something already uses port 8000 (an old Apache, or `php artisan serve`). Find it: `netstat -ano | findstr :8000` → `taskkill /PID <pid> /F`. |
| Apache error about **`php8apache2_4.dll`** or "The specified module could not be found" | PHP isn't the **Thread Safe** build, or the Visual C++ Redistributable is missing, or `NASERY_PHP` points to the wrong folder. |
| API errors mentioning **SSL certificate problem** | `curl.cainfo` / `openssl.cafile` in `php.ini` don't point to an existing `cacert.pem` (2.3). |
| Sign-in succeeds then immediately signs out / "**CSRF token mismatch**" / 419 | You opened `127.0.0.1:3000` — use **`http://localhost:3000`**. Or the frontend is on a port other than 3000. |
| Page is completely **blank** after code changes | Stop `npm run dev` (`Ctrl+C`), start it again, reload the page (Ctrl+Shift+R). |
| `npm run dev`: "**Failed to resolve import …**" | Run `npm install` again in `frontend` (a package is missing, e.g. after pulling new code). |
| `nasery:doctor` can't connect | No internet / venue Wi-Fi blocks port 5432 → use a phone hotspot. Or `backend\.env` missing/incomplete. |
| Notifications / e-mails never arrive | The queue worker (window 2) isn't running. Demo `@demo.nasery.test` addresses never get mail by design. |
| Location search in "Post Event" doesn't suggest places | Expected: we don't have a LocationIQ key. Type the venue and (optionally) click **Use my current location**. |
| Seeding fails with "Set DEMO_PASSWORD…" | `backend\.env` is missing `DEMO_PASSWORD` — get the real `.env` from Nathan. |
| Seeding fails at the backup step | `pg_dump` not installed or `PG_DUMP_PATH` wrong (2.1, 2.4). |

Logs to look at: `backend\storage\logs\laravel-YYYY-MM-DD.log` (app errors) and `backend\storage\logs\apache-error.log` (Apache/PHP start-up).

---

## 8. Do / don't

- ✅ Pull the latest code before the presentation: `git pull`, then `composer install` (backend) and `npm install` (frontend) in case packages changed.
- ✅ Use PHP by full path: `C:\xampp\php8.5.10\php.exe artisan …`
- ❌ Don't commit `.env` files, database backups (`backend/storage/backups`), or your edited `apache-local.conf`.
- ❌ Don't run `php artisan test`, migrations (`nasery:db:sql … --apply`) or `nasery:test-data:purge` unless you know why — they use the shared database. Tests and migrations always need a backup first; ask Nathan.
- ❌ Don't raise `ThreadsPerChild` above 6.
- ❌ Don't type real card numbers anywhere — test mode only.
