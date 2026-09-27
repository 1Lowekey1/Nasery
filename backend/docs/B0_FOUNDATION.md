# B0 — Backend Foundation (spec + handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B1 Authentication**.

## Decisions (FINAL unless noted)

| Topic | Decision |
|---|---|
| Framework | Laravel **13.19.0** (pinned exactly, per the context pack lock). Newer 13.x patch releases exist (13.33.0); upgrading is OPEN. |
| PHP | **8.5.10** for NaSeRy only: `composer.json` requires `^8.5` with `config.platform.php = 8.5.10`; the machine default PHP (XAMPP 8.2) is left untouched. Use the `Nasery.code-workspace` terminal or the `backend/.\php` / `.\composer` wrappers. Hosting must run PHP 8.5. |
| API | `/api/v1`, standard envelope (`App\Support\Http\ApiResponse`), error codes in `App\Support\Http\ErrorCode` |
| Auth | Sanctum **SPA cookie session + CSRF** (pack lock). Free hosting works by proxying `/api` through the frontend origin (Vercel rewrite → Railway), so cookies are first-party. |
| DB access | Direct Laravel → Supabase Postgres (session pooler). Domain connection `pgsql`; infrastructure connection `system` (schema `laravel`). |
| RLS | Every API request runs as NOLOGIN role `nasery_app` with `auth.uid()` = user id (migration 0027). Laravel = primary authorization, RLS = backstop. Privileged paths only via `DatabaseContext::system()`. |
| Transactions | One per API request; any status >= 400 rolls back. Jobs dispatch after commit. Streamed bodies (exports) re-enter the RLS context for the same user in their own transaction. |
| Idempotency | `idempotent` / `idempotent:required` middleware, `Idempotency-Key` header, `laravel.idempotency_keys`, 24h TTL, 409 on reuse/in-flight, 5xx releases the key, 4xx is replayed, JSON object key order ignored (list order kept), streamed/file responses not cached. |
| CSRF | Sanctum stateful pipeline with Laravel 13 `PreventRequestForgery`: token required for every first-party mutating request, including cross-port same-site (localhost:3000 → :8000). Only `Sec-Fetch-Site: same-origin` (production proxy setup) skips the token. |
| Proxies | `TRUSTED_PROXIES` (default: none). `*` = immediate peer; CIDR list for a chain. Needed behind Vercel/Railway so rate limits see real client IPs. |
| Rate limits | Named limiters from `config/nasery.php` (`throttle:login`, `register`, `password-reset`, `email-verification`, `public`, `api`, `sensitive`, `checkout`, `checkin`, `webhook`). Default `api` on all routes. |
| CORS | Only `FRONTEND_URLS` origins, credentials on, explicit header list. |
| Logging | App log (`daily`/`stderr`) with secret redaction tap; `security` channel for auth/abuse signals; business accountability in `public.audit_logs` via `AuditLogger` (same transaction as the change). Request id (`X-Request-Id`) in logs and audit rows. |
| Queues | `database` driver on `system` connection, `after_commit`. Worker: `php artisan queue:work`. |
| Tests | Run against the configured Supabase DB inside rolled-back transactions on both connections; `composer test` takes a pg_dump first. Destructive DB traits are blocked in `Tests\TestCase`. |
| Environments | local / staging / production via `.env` (template: `.env.example`). Separate Supabase + PayMongo credentials per environment. |

## Layout

```
app/Support/Database/DatabaseContext.php   role/identity switching, system()
app/Http/Middleware/ApplyDatabaseContext   per-request RLS transaction
app/Http/Middleware/AssignRequestId        X-Request-Id
app/Http/Middleware/EnsureIdempotency      Idempotency-Key handling
app/Exceptions/ApiExceptionRenderer        all errors -> envelope (incl. SQLSTATE mapping)
app/Exceptions/ApiException                throw from actions for a specific error
app/Support/Audit/AuditLogger              audit_logs writer + secret scrubbing
app/Enums/*                                PHP mirrors of the 30 PG enum types (+ UserStatus)
app/Models/Model                           base domain model (uuid, timestamptz)
app/Console/Commands/{Doctor,BackupDatabase}
config/nasery.php                          rate limits, db role, idempotency, backups
database/supabase/                         authoritative SQL (see README there)
```

## Conventions for B1+

- Controllers stay thin: Form Request → Action/Service → `ApiResponse`.
- Never query around RLS casually. If an operation truly needs it, wrap only
  that part in `DatabaseContext::system('module.action', fn () => ...)` and
  do the authorization check in Laravel first.
- Throw `ApiException` for business errors (409 for state/capacity/idempotency conflicts).
- Retry-sensitive POSTs get `->middleware('idempotent:required')`.
- Use 0021 functions (`accept_quotation`, `transfer_ticket`, ...) for their transitions; they audit themselves.
- Every module ships tests (feature + authorization + business rules; concurrency where relevant).
- **Login / any lookup by a guest** (email → user, token → registration) can't see rows under RLS:
  do that lookup inside `system()`; everything after authentication runs as the user.
- Tests: JSON requests only send cookies with `->withCredentials()`; `withSession()`/`actingAs()`
  bypass the cookie layer, so session-security tests must replay real cookies (see `CsrfAndSessionTest`).
- Background jobs acting for a user: `DatabaseContext::asUser($userId, fn () => ...)`.

## Verified

- `php artisan nasery:doctor`: all checks pass (0025/0026/0027 present, RLS on every table, anon/authenticated without base-table grants, live probe: app role, auth.uid(), RLS isolation, audit_logs not writable, system context returns to app role).
- `composer test` (after the fix-up round): **55 tests / 190 assertions pass**; afterwards the DB holds 0 users, 0 audit rows, 0 idempotency keys (the only `laravel.sessions` row is a guest session from a manual curl smoke test).
- Coverage: envelope/errors, RLS context (guest, user, isolation, no leak, rollback, top-level COMMIT/ROLLBACK seen from another session, streamed bodies, `asUser`, `system()` incl. caught exceptions), SQLSTATE mapping, idempotency (replay, reuse, in-flight, abandoned, expired, key order, 4xx replay, streamed, malformed, per-user scope, 5xx release), rate limits (incl. per-client behind proxy, spoofed XFF), CSRF (missing/wrong/valid token, real cookie flow, same-site cross-port, foreign origin ignores session cookie, stolen cookie without token), CORS, timestamps, log/audit redaction.
- Real HTTP smoke test: `/sanctum/csrf-cookie` issues XSRF + session cookies with credentialed CORS for `http://localhost:3000`; `/api/v1/health` returns the envelope + `X-Request-Id`.
- Frontend: new `useApi()` (Sanctum cookies, XSRF header, 419 retry, `ApiError` with `code`), base URL `/api/v1`; `nuxi build` succeeds.

## Open / follow-ups

- **Public marketplace under RLS**: `organization_profiles`/`services` have no public-read policy and the 0026 views are security_invoker, so guests can't read them as `nasery_app`. B3 must either read the views via `system()` (current plan) or add a public-read policy migration; decide in B3.
- Several flows have no RLS INSERT path by design (sign-up, organization creation, guest registration, webhooks); these use `system()` in their actions.
- Laravel Boost: the skeleton's `backend/CLAUDE.md`/`AGENTS.md` suggest installing it; not installed (team decision).
- Hosting: Railway no longer has a permanent free tier (trial credit, then paid); compare free Laravel hosts before deployment.
- Old frontend `components/api/BaseAPIService.ts` (bearer tokens) is replaced module by module starting with B1 (login/register).
- Tests take ~8 minutes due to latency to the Tokyo pooler (`composer test` has no timeout).
- Not covered by automated tests: truly parallel requests with the same idempotency key
  (PHP's built-in server on Windows is single-threaded). Correctness relies on the
  `unique(scope, key)` index + `insertOrIgnore`, which PostgreSQL enforces atomically; re-check
  under a multi-worker server before B10 payments.
- Production values to set at deploy time: `TRUSTED_PROXIES`, `SESSION_DOMAIN`,
  `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URLS`, `APP_DEBUG=false`.

## B0 fix-up round (2026-09-24)

Issues found in review and fixed, each with a regression test:

| Issue | Fix |
|---|---|
| Streamed responses ran their queries after the request transaction, as `postgres` (RLS bypassed) | `ApplyDatabaseContext` wraps the stream callback in `asUser()` |
| Behind a proxy every client shared one IP → one rate-limit bucket (login lockout for all) | `TrustConfiguredProxies` + `TRUSTED_PROXIES` |
| CSRF protection never actually exercised (Laravel disables it in tests) | `Tests\Support\EnforcedCsrf` + real cookie replay tests |
| Idempotency: key-order retries rejected; unbounded retry recursion; streamed bodies cached as empty | canonical JSON fingerprint; single retry; streamed/file not cached |
| Cleanup after a failed SQL statement ran SQL in an aborted transaction | rollback first, then `forget()` (no SQL) |
| Caught exception inside `system()` could leave the request elevated | role restored in all paths |
| Cookies/session ids could reach logs | log-only redaction keys |
| Raw `PDOException` (DB unreachable) rendered as 500 | mapped like `QueryException` (503) |
| Doctor's "system context" check proved nothing | now proves elevation *and* restoration on an ungranted table |
| `composer test` killed after 300 s | process timeout disabled for the test script |
