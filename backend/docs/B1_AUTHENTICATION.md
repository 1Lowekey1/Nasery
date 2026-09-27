# B1 — Authentication (spec + handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B2 Organizer onboarding / approval**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Session mechanism | Sanctum SPA cookie session + CSRF (B0) | pack lock |
| Email verification | 6-digit code **after** sign-up; user is signed in but unverified; modules that need it use the `verified` middleware (403 `EMAIL_NOT_VERIFIED`) | user asked me to choose; chosen for UX (one form) and to avoid storing codes for addresses without an account |
| Identity field | Email required (unique, case-insensitive), phone optional, normalized to `+639XXXXXXXXX`; no phone login | user |
| Social sign-in | Google, Facebook, Apple, kept from the prototype and hardened (below) | user |
| Account types | Self-registration only as `client` or `business`; `admin` is never self-registered | schema/pack |
| Password policy | min 8, max 128, letters + numbers; production also checks Have I Been Pwned (k-anonymity) | — |
| Codes | 6 digits, HMAC-SHA256 at rest, 10 min TTL, 5 attempts, 60 s resend cooldown, one live code per user+purpose | — |

## Endpoints (`/api/v1/auth`)

| Method | Path | Auth | Limits | Notes |
|---|---|---|---|---|
| POST | `register` | guest | `register` 5/h/IP | 201 + signed in + verification email |
| POST | `login` | guest | `login` 5/min/IP + `login-account` 10/15 min/email | 401 `INVALID_CREDENTIALS` (identical for unknown email), 403 `ACCOUNT_SUSPENDED`/`ACCOUNT_DEACTIVATED` |
| POST | `logout` | user | api | invalidates session + CSRF token |
| GET / PATCH | `me` | user | sensitive (PATCH) | profile: names + phone (email change out of scope) |
| POST | `email/verification-code` | user | `email-verification` | 429 `RESEND_COOLDOWN` with `retry_after_seconds` |
| POST | `email/verify` | user | sensitive | `INVALID_CODE` (+`attempts_remaining`), `CODE_EXPIRED`, `TOO_MANY_ATTEMPTS` |
| POST | `password/forgot` | guest | `password-reset` | always the same 200 (no enumeration) |
| POST | `password/reset` | guest | `password-reset` + sensitive | also verifies the email, signs out every session |
| PUT | `password` | user | sensitive | needs `current_password` (not for social-only accounts); keeps this device, signs out others |
| GET | `sessions` | user | api | devices with opaque ids (sha256 of session id) |
| DELETE | `sessions/{id}` / `sessions` | user | sensitive | sign out one / all other devices |
| GET | `social/providers` | guest | public | which buttons to show |
| GET | `social/{provider}/redirect?mode&account_type` | guest | login | returns `redirect_url`, sets flow cookie |
| GET/POST | `social/{provider}/callback` | provider | login | redirects to SPA `/auth/social/callback?code=` or `/login?social_error=` |
| POST | `social/exchange` | guest (SPA) | login | one-time code → session |
| DELETE | `social/{provider}` | user | sensitive | refused if it's the only sign-in method |

All authenticated routes use `auth:sanctum` + `active` (suspension/deactivation ends a live session immediately).

## Social sign-in hardening (vs prototype)

- **Apple actually implemented** (prototype listed it but only allowed Google/Facebook). Needs HTTPS + Apple credentials.
- **Browser-bound flow (RFC 9700 §2.1):** `redirect` sets an HttpOnly `nasery_social_flow` cookie; callback and exchange succeed only in that browser → no login CSRF via forwarded links.
- **Provider-verified email only** for linking to an existing account; linking to an **unverified** local account wipes its password and sessions (pre-registration hijack).
- **No exception text** in redirect URLs: fixed `social_error` codes.
- Ends in a normal Sanctum session (prototype issued bearer tokens).
- Setup: set `GOOGLE_CLIENT_ID/SECRET`, `FACEBOOK_CLIENT_ID/SECRET`, `APPLE_*` in `.env`; register `{APP_URL}/api/v1/auth/social/{provider}/callback` with each provider. Unconfigured providers are hidden by the SPA.

## Storage

- `public.users` (existing): `password_hash`, `email_verified_at`, `status`, `deleted_at`.
- New private tables (Laravel migrations, `laravel` schema, **no domain schema change**): `auth_codes`, `social_accounts`.
- Audit rows: `user.registered`, `user.email_verified`, `user.profile_updated`, `user.password_changed`, `user.password_reset`, `user.social_linked`, `user.social_unlinked`. Logins/failures go to the `security` log channel.

## RLS notes

A guest can't see any `users` row, so login, registration uniqueness, forgot/reset and social resolution look users up inside `DatabaseContext::system()`. Everything after authentication (profile, email verification, password change) runs through RLS as the user. The request's RLS identity is fixed when it starts, so code that just signed someone in must use `system()` to re-read their row.

## Frontend (Nuxt)

`useAuth()` (state + all calls), global route guard `middleware/auth.global.ts` (areas `/client`, `/organizer`, `/admin` by account type; guest-only login/register/forgot), pages `login`, `register` (first/last name, optional phone, role), `verify-email`, `forgot-password`, `auth/social/callback`; real `SocialButtons`; layouts' **Sign out** now logs out. Removed the bearer-token `BaseAPIService`/`AuthService` and the email/phone toggle. The login `redirect` parameter only accepts same-site paths (no `//evil.com`).

## Verified

- Full suite after the performance changes: **115 tests / 468 assertions pass** (B0 + B1), `nasery:doctor` passes, no test data left in the database. Tests force in-memory rate-limit counters (`CACHE_LIMITER_STORE=array` in phpunit.xml).
- Backend: `tests/Feature/Auth/*` — 60 tests (registration, login incl. account lockout across rotating IPs, suspension of live sessions, email verification incl. attempt counting that survives rollback, password reset/change, sessions, full social flows incl. login-CSRF and hijack cases).
- Real browser (Nuxt dev ↔ Laravel ↔ Supabase): register → verification email via queue worker → wrong code (attempts shown) → correct code → organizer home; area guard; sign out; protected-page redirect; sign in with mixed-case email back to the requested page. Test user and its rows were deleted afterwards.

## Performance fix found during E2E

Local dev talks to Supabase Tokyo (~130 ms RTT). Requests took ~8 s. Fixed:
- PDO **emulated prepares** (1 round trip per query instead of prepare+execute; `DB_EMULATE_PREPARES`).
- Role + identity set in **one** statement; the reset is skipped at top-level commit (commit reverts `SET LOCAL`).
- Rate-limit counters on a configurable store (`CACHE_LIMITER_STORE=file` locally; database/redis in production).
- Removed the Laravel welcome page (every hit created a DB session; the preview poller flooded the single-threaded dev server).
Health request: ~8 s → ~1.6 s locally (remaining cost is the TLS connect to Tokyo). A server deployed near the database won't have this latency.

## Local run

```
backend> .\php artisan serve          # http://localhost:8000
backend> .\php artisan queue:work     # sends verification / reset emails (MAIL_MAILER=log → storage/logs)
frontend> npm run dev                 # http://localhost:3000
```

## Open

- Email change (needs re-verification of the new address) — not in B1 scope.
- Real mail provider for staging/production (MAIL_MAILER=log locally). Free options: Resend / Brevo.
- Social providers need credentials from the team; Apple requires a paid developer account and HTTPS.
- Admin accounts: create manually for now; an admin-creation command belongs to B17.
