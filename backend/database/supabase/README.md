# NaSeRy database (Supabase PostgreSQL)

The domain schema is raw SQL, not Laravel migrations: it relies on RLS,
SECURITY DEFINER functions, deferrable constraints, partial indexes and
triggers that the schema builder cannot express. Laravel migrations
(`database/migrations`) only manage the private `laravel` schema.

| File | What | Status on the shared Supabase project |
|---|---|---|
| `0001-0026_compiled.sql` | Finalized schema, RLS, functions, seed, 0025 security fixes, 0026 security_invoker views | applied before 2026-09-22 |
| `0027_app_role_grants.sql` | `nasery_app` role + grants mirroring RLS policies, revoke of public `expire_stale_holds()`, private `laravel` schema | applied 2026-09-24 |
| `0027_app_role_grants.down.sql` | Rollback of 0027 | — |
| `0028_app_role_column_grants.sql` | Column-level grants: nasery_app can't write approval/suspension, account type/status/email/verification, or ownership flags | applied 2026-09-24 |
| `0028_app_role_column_grants.down.sql` | Rollback of 0028 | — |
| `0029_public_profiles_view_marketplace.sql` | Appends slug, latitude, longitude to public_organization_profiles (marketplace URLs + near-me) | applied 2026-09-24 |
| `0029_public_profiles_view_marketplace.down.sql` | Rollback of 0029 | — |
| `0030_inquiry_matching.sql` | inquiries.latitude/longitude/match_radius_km (+CHECKs); app role may not create/delete inquiry_organizations rows, only update the response columns; `inquiry_auto_close_days = 30` setting | applied 2026-09-24 |
| `0030_inquiry_matching.down.sql` | Rollback of 0030 | — |
| `0031_fix_inquiry_policy_recursion.sql` | SECURITY DEFINER id helpers + recreated inquiry/quotation/event_progress policies; fixes infinite RLS recursion (42P17) between inquiries and inquiry_organizations | applied 2026-09-24 |
| `0031_fix_inquiry_policy_recursion.down.sql` | Rollback of 0031 (restores the recursive policies) | — |
| `0032_quotation_content_and_grants.sql` | quotations.package_name/proposal; column grants (status/decisions not app-writable); drafts hidden from clients; client read of quotation_items | applied 2026-09-24 |
| `0032_quotation_content_and_grants.down.sql` | Rollback of 0032 | — |
| `0033_organization_price_options.sql` | Organizer price list for the quotation calculator + public view (listed organizers, active options; no anon grant) + same-organization trigger | applied 2026-09-24 |
| `0033_organization_price_options.down.sql` | Rollback of 0033 (drops price lists) | — |
| `0034_event_core.sql` | events venue/map pin/visibility/point person; client read of own events + sessions; one live event per inquiry; column grants (inquiry links/org/creator not app-writable, new events always draft) | applied 2026-09-24 |
| `0034_event_core.down.sql` | Rollback of 0034 | — |
| `0035_default_event_stages.sql` | Data seed: platform preparation stages Planning → Confirmed → Ready → Event day → Completed (client milestone view) | applied 2026-09-24 |
| `0035_default_event_stages.down.sql` | Rollback of 0035 (clears references, removes the seed) | — |
| `0036_ticket_tiers_public_events.sql` | ticket_types zone_name/benefits/sort_order; public views public_events + public_ticket_types (system reads, no anon grant) | applied 2026-09-24 |
| `0036_ticket_tiers_public_events.down.sql` | Rollback of 0036 | — |
| `0037` – `0046` | Event reports, review replies, admin access requests (0039 unused), SaaS billing, inquiry threads, payouts, task reviews, event activities, profile details: see each file's header | applied 2026-09-25 – 2026-09-30 |
| `0047_services_rls.sql` | Enables RLS on `services`: it was already on in Supabase but in no file, so databases built from these files failed `nasery:doctor` | no-op on Supabase; applied by the local setup |

**Self-hosted database:** the same files build a plain PostgreSQL 18 database. See `../local/README.md`; it adds a small Supabase-compatibility script first.

Apply/dry-run with `php artisan nasery:db:sql <file>` (dry run, rolled back) and
`php artisan nasery:db:sql <file> --apply` (takes a backup first).

Rules
- Never re-run 0025 casually; never resurrect older 0026 variants.
- New database changes get the next number (`0028_...sql`) plus a `.down.sql`,
  are dry-run inside a rolled-back transaction first, and are applied only
  after `php artisan nasery:db:backup`.
- After applying anything, run `php artisan nasery:doctor`.

## Fresh environment (staging / new project)

1. Apply `0001-0026_compiled.sql`, then `0027_app_role_grants.sql`
   (Supabase SQL editor, or `psql -f`).
2. `php artisan migrate` (creates `laravel.*` tables).
3. `php artisan nasery:doctor` must pass.

## How RLS applies to Laravel (0027)

Laravel logs in as `postgres` (BYPASSRLS). For every `/api/v1` request,
`ApplyDatabaseContext` opens a transaction and runs

```sql
SET LOCAL ROLE nasery_app;                        -- NOLOGIN, NOBYPASSRLS
SELECT set_config('request.jwt.claims', '{"sub":"<users.id>"}', true);
```

so every policy and every `auth.uid()`-based function sees the real caller.
`nasery_app` has exactly the table privileges that have a matching policy.
Operations that RLS intentionally does not model (sign-up, organization
creation, guest checkout, webhooks, audit writes, marketplace reads) must go
through `DatabaseContext::system('<reason>', fn () => ...)`: explicit,
logged, and still inside the same transaction.

Supabase's own `anon`/`authenticated` roles have no base-table grants, so the
public anon key exposes nothing through the Data API.

## Backups / restore

`php artisan nasery:db:backup --reason=...` writes a pg_dump (custom format,
`public` + `laravel` schemas) to `storage/backups/` (gitignored, keeps 10).
`composer test` always takes one first.

Restore (DESTRUCTIVE, replaces the dumped objects):

```
pg_restore --clean --if-exists --no-owner --single-transaction -d "<connection-uri>" storage/backups/<file>.dump
```

Roles are cluster-level and not in the dump; re-apply 0027 if `nasery_app`
is missing.
