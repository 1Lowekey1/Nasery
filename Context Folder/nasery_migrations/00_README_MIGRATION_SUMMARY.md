# NaSeRy — PostgreSQL/Supabase Migration Package

This package implements the finalized NaSeRy specification as raw SQL migrations (raw SQL, not Laravel Schema Builder, because partial/composite indexes, generated columns, deferrable constraints, triggers, PL/pgSQL functions, and RLS all require it — Laravel Schema Builder cannot correctly express any of these). Each file is directly executable against Supabase PostgreSQL in the order listed.

25 files total: 24 in the requested dependency-ordered stages, plus `0022b` (RLS coverage for tables not already handled by the highest-sensitivity policies in `0022`).

---

## A. Migration Execution Order

```
0001_extensions.sql
0002_types.sql
0003_users.sql
0004_organizations.sql
0005_roles_permissions.sql
0006_marketplace.sql
0007_inquiries.sql
0008_quotations.sql
0009_events.sql
0010_preparation.sql
0011_ticketing.sql
0012_payments.sql
0013_platform_fees.sql
0014_refunds.sql
0015_attendance.sql
0016_notifications.sql
0017_reviews.sql
0018_admin.sql
0019_audit.sql
0020_saas.sql
0021_functions_triggers.sql
0022_rls.sql
0022b_rls_supplemental.sql
0023_indexes.sql
0024_seed.sql
```

Two deliberate forward-reference resolutions, both commented inline at their source:
- `organization_members.organization_role_id` has no FK until `0005` adds it (needs `organization_roles`, created in `0005`).
- `payments.subscription_invoice_id` has no FK until `0020` adds it (needs `subscription_invoices`, created in `0020`).

Each table's *column* exists from its own creation migration; only the *foreign key constraint* is added later, which is standard practice for a circular-ish dependency and does not block any INSERT in between (the column is simply unconstrained until the FK lands).

---

## B. Seed Execution Order

Seed data (`0024_seed.sql`) is a single file, run last, and is idempotent (`ON CONFLICT DO NOTHING` throughout, or existence checks for rows without a natural unique key like `platform_fee_configs`). It can be safely re-run. Internal order within the file:

1. Permissions catalog
2. Platform-default event types
3. Platform fee configuration (V1 deduction model, 1% default)
4. SaaS plans
5. Features
6. Plan entitlements (depends on plans + features existing)
7. Platform settings

No fake user accounts or passwords are seeded, per instruction — `users` rows are created only through real signup/Supabase Auth flows.

---

## C. Rollback Considerations

- **Additive-only philosophy:** every migration only creates; none contains a corresponding down-migration in this package, consistent with the specification's stance that financial/audit/business history must never be silently destroyed. If a rollback is genuinely needed pre-production, drop the files' objects in **exact reverse order** (`0024` down to `0001`), since later files depend on earlier ones (FKs, composite FKs, and functions referencing tables created earlier all point backward, never forward, except for the two documented cases in §A).
- **`0021` and `0022`/`0022b` are safe to re-run individually** (`CREATE OR REPLACE FUNCTION`, and policies can be dropped/recreated) without touching table data — useful for iterating on business logic or RLS policy shape without a full schema rebuild.
- **Never roll back `0012`–`0014` (payments/fees/refunds) or `0019` (audit) in a system that has processed any real transaction** — per the specification's own locked rule, financial and audit history must survive; a rollback here should be treated as equivalent to data destruction and requires a business decision, not a routine `DROP TABLE`.
- **Extensions (`0001`) are never rolled back** in practice — dropping `pgcrypto`/`citext`/`pg_trgm` after other objects depend on them (generated columns, `citext` typed columns, trigram indexes) will fail with dependency errors by design; this is PostgreSQL protecting you, not a bug to work around.

---

## D. Database-Level Invariants

The most important business rules enforced by the database itself (not merely by Laravel), each traceable to a specific mechanism:

| Invariant | Mechanism |
|---|---|
| Exactly one active primary owner per organization | Generated column (`primary_owner_marker`) + deferrable whole-table `UNIQUE` constraint (at most one) + `BEFORE UPDATE/DELETE` trigger (at least one) — `0004` |
| Only `account_type='business'` users can be organization members; only `account_type='client'` users can own inquiries | Triggers on `organization_members` and `inquiries` — `0004`, `0007` |
| An event can only use its own organization's custom event types or a platform default | Trigger on `events` (the one deliberate exception to "prefer composite FK") — `0009` |
| `COMPLETED`/`CANCELLED` events cannot be silently reopened | Trigger on `events.status`, escape hatch only via `admin_override_event_status()` — `0009`, `0021` |
| A ticket's registration and ticket type must belong to the same event; attendance session must belong to the same event as the ticket; a walk-in's linked ticket must belong to the same event; an event member's org must match the event's org; a quotation's org must be one actually invited to bid | Real composite foreign keys — `0009`, `0011`, `0015` |
| One active quotation per organizer per inquiry | Partial unique index (`WHERE status IN ('draft','submitted')`) — `0008` |
| At most one `PAID` payment ever settles a given registration or subscription invoice | Partial unique indexes (`WHERE status='paid'`) — `0012` |
| Webhook events are processed at most once | `UNIQUE(provider_event_id)` — `0012` |
| Non-`PAID` admission tickets never carry a price or fee | `CHECK` constraint on `tickets` — `0011` |
| Total refunds for a payment never exceed the original amount, even under concurrent refund-recording transactions | Deferred `CONSTRAINT TRIGGER` that locks the parent payment row `FOR UPDATE` before summing — `0014` |
| Duplicate check-in is prevented per ticket/session | Partial unique indexes on `attendance_records` — `0015` |
| A single identified walk-in row can never represent more than one person | `CHECK` constraint tying `count` to `category` — `0015` |
| One review per client per completed event | Partial unique index on `reviews` — `0017` |
| Admin access to private tenant data requires a live, time-bound, reasoned grant | `admin_access_grants` with `NOT NULL` reason, default 24h expiry, checked by `admin_has_active_grant()` in every relevant RLS policy — `0018`, `0022` |
| Audit history is immutable and cannot be forged by a direct client write | No `INSERT`/`UPDATE`/`DELETE` RLS policy grants direct client access to `audit_logs`; rows are written exclusively inside `SECURITY DEFINER` domain functions — `0019`, `0021`, `0022` |
| One "live" subscription per organization at a time | Partial unique index on `subscriptions` — `0020` |
| Cross-tenant data is never returned regardless of application-layer bugs | RLS enabled on every private table, backed by four `SECURITY DEFINER`/`BYPASSRLS`-owner helper functions that resolve identity exclusively from `auth.uid()`, never from a caller-supplied parameter — `0022`, `0022b` |

---

## E. Known Application-Level Rules

Rules that intentionally remain in Laravel/application services rather than being fully enforced through schema constraints, with the reason for each:

- **Ticket-type and event-overall capacity enforcement at purchase time.** The database exposes the authoritative calculation as `ticket_type_available_capacity()` / `event_available_capacity()` (`0021`), and the *supporting* index and row-lock target (`ticket_types`, `events`) exist in the schema — but the actual `SELECT ... FOR UPDATE` → check → insert sequence, in the fixed lock order (event row before ticket-type row), is orchestrated by Laravel, because it spans a multi-step checkout flow the database alone cannot safely own.
- **PayMongo webhook signature verification and the full payment-confirmation flow.** The database provides the idempotency key (`provider_event_id` uniqueness) and the settlement-exclusivity constraint, but verifying a webhook's authenticity against PayMongo's signing secret, and orchestrating the external API interaction, is inherently outside the database's reach.
- **Capacity-checked, non-transactional side effects** such as sending confirmation emails/QR delivery after a ticket is finalized — deliberately kept outside the database transaction (per the notifications design: delivery failure must not roll back the underlying business operation).
- **Fee-config selection logic beyond "which config is effective now."** The database stores versioned configs and can identify the currently effective one via `effective_from`/`effective_to`, but the actual fee *computation and rounding/allocation* (largest-remainder method across multiple tickets) is performed in Laravel at settlement time and the *result* is what gets snapshotted into `platform_fee_snapshots` — this keeps the arithmetic testable and adjustable in application code without a migration.
- **Whether the platform fee itself is refunded, retained, or split on a given refund.** `payment_refunds.fee_portion_refunded` is nullable specifically because this is an unresolved *business* policy, not a schema gap — see the specification's explicit note on this.
- **Entitlement enforcement at the point of resource creation** (e.g., blocking a 4th active event on the Free plan). The database stores the entitlement values (`plan_entitlements`) and current usage is queryable, but the actual "block this INSERT because the plan limit is reached" check is a Laravel-side `EntitlementService` call before the domain action runs — deliberately not a database `CHECK` or trigger, so a plan-limit change (an application-config concern) never requires a migration.
- **`event_role_label` is explicitly documented (comment on the column, `0009`) as display-only** — nothing in the database prevents a future developer from mistakenly branching application code on this text field; this is a code-review-enforced rule, not a schema-enforced one, because a database constraint cannot police how a value is *used* by application logic.
- **The convention that `transfer_organization_ownership()`, `accept_quotation()`, `set_organizer_approval_status()`, `admin_override_event_status()`, and `transfer_ticket()` are the ONLY sanctioned paths for their respective transitions.** Each function internally re-verifies the caller's authorization via `auth.uid()` (so a malicious direct call can't bypass authorization), but nothing stops a Laravel developer from writing a raw multi-table UPDATE that achieves a similar-looking effect through ordinary table grants — this is an architectural/code-review convention, not something a database can force by itself short of revoking all direct table UPDATE grants from `authenticated`, which was judged too restrictive for this system's many legitimate single-table edits (e.g., editing a task's description).
- **RLS coverage in `0022`/`0022b` is comprehensive for every table identified in the specification's access matrix, but is not formally proven exhaustive against every possible query shape.** Before production use, run Supabase's RLS linter/advisor and a manual cross-tenant penetration pass (attempt to read another organization's data as an authenticated but unrelated user) as a final verification step — this is standard practice for any RLS-based system and is called out explicitly rather than silently assumed complete.

---

## Final Validation (per the pre-answer checklist)

- **Every FK points to an existing table/column:** verified — the two intentionally-deferred FKs (`organization_role_id`, `subscription_invoice_id`) are explicitly commented and resolved by `0005`/`0020` respectively; no other forward references exist.
- **Every composite FK has a matching parent unique constraint, exact column order:** verified — `uq_registrations_id_event`, `uq_ticket_types_id_event`, `uq_tickets_id_event`, `uq_event_sessions_id_event`, `uq_events_id_org`, `uq_org_members_id_org`, `uq_inquiry_organizations` all created before the composite FKs that reference them, in matching column order.
- **No invalid deferrable partial unique indexes:** verified — the ownership guarantee uses a generated column + whole-table (non-partial) deferrable `UNIQUE` **constraint**; the refund-total guard uses a deferrable **constraint trigger**; neither uses `DEFERRABLE` on a `WHERE`-clause index anywhere in this package.
- **Money uses `NUMERIC`:** verified — every monetary column is `numeric(12,2)` (or `numeric(6,4)` for the fee percentage rate); no `DOUBLE PRECISION`/`FLOAT` anywhere.
- **Attendee email is not globally unique:** verified — `attendees.email` has a plain lookup index only, no `UNIQUE`.
- **Inquiry/event relationship allows independent events:** verified — `events.inquiry_id` and `events.accepted_quotation_id` are both nullable.
- **Quotation active-uniqueness correctly enforced:** verified — partial unique index, `WHERE status IN ('draft','submitted')`.
- **Registration supports multiple tickets:** verified — `tickets.registration_id` is a plain FK, no cardinality limit.
- **Mixed admission types are possible:** verified — `admission_type` lives on `tickets`, not `registrations`.
- **Ticket capacity does not depend on `sold_count`:** verified — `cached_sold_count` is explicitly commented as derived/cache-only; enforcement functions (`0021`) compute from `tickets`/`ticket_holds` directly.
- **Successful-payment uniqueness correctly enforced:** verified — partial unique indexes on both `registration_id` and `subscription_invoice_id` where `status='paid'`.
- **Payment amount equals attendee checkout subtotal:** verified — `payments.amount` is documented and, by construction (Laravel computes it as the ticket subtotal, never subtotal+fee), equals the Ticket Subtotal; the fee is deducted internally via `platform_fee_snapshots`, never added to `payments.amount`.
- **Platform fee is deducted internally, no fee-payer column exists:** verified — neither `platform_fee_configs` nor `platform_fee_snapshots` has a `payer` column anywhere in this package.
- **Refund aggregate constraint locks the parent payment row:** verified — `trg_payment_refunds_check_total()` issues `SELECT ... FOR UPDATE` on `payments` before summing.
- **Refunds cannot exceed original payment:** verified — same trigger raises an exception otherwise.
- **Original payment remains `PAID` after a refund:** verified — no code path in this package ever updates `payments.status` or `payments.amount` from a refund; `payment_refunds` is a fully separate, additive table.
- **Refund rows do not use negative reversal values:** verified — `CHECK (amount > 0)` on `payment_refunds`; no negative-value pattern exists anywhere.
- **Audit logs survive business-record deletion:** verified — `audit_logs` has no FK `ON DELETE CASCADE` from any business table (in fact, business tables reference audit-adjacent concepts only by loose `entity_id`/`entity_type`, never the reverse), and soft deletion (`deleted_at`) never physically removes the referenced row in the first place.
- **RLS does not accidentally expose another organization's private data:** every private-table policy in `0022`/`0022b` filters through `user_org_ids()`, `user_has_event_permission()`, `admin_has_active_grant()`, or a direct `auth.uid()` ownership check — no policy grants blanket authenticated access to a tenant-private table.
- **Admin access grants are time-limited:** verified — `expires_at` defaults to `now() + interval '24 hours'`, `NOT NULL`.
- **Ownership cannot accidentally leave an organization without an owner:** verified — `0004`'s trigger, independently of the deferred uniqueness constraint.
- **Subscription and ticket payments remain distinct:** verified — both flow through the same `payments` table by design (per the finalized architecture), but are structurally distinguished by the mutually-exclusive `registration_id`/`subscription_invoice_id` CHECK, and settlement-exclusivity is enforced independently for each via two separate partial unique indexes.
- **All required indexes exist:** verified against the specification's indexing strategy — capacity, webhook idempotency, ownership, settlement exclusivity, quotation exclusivity, subscription exclusivity, duplicate check-in, and every composite-FK support index are present; `0023` adds the remaining reporting/lookup indexes.
- **All PostgreSQL-specific SQL is syntactically valid:** reviewed statement-by-statement for correct PostgreSQL 15+/Supabase syntax (generated columns, deferrable constraints vs. constraint triggers, partial indexes, `SECURITY DEFINER` functions with fixed `search_path`, `MATCH SIMPLE` composite FK nullability behavior) — no MySQL-isms, no unsupported constructs.

No feature beyond the finalized specification was added. Where a genuine ambiguity existed (platform-fee refund allocation), it is represented flexibly in the schema rather than resolved by an invented business rule, and is called out explicitly in §E above rather than silently decided.

> **READY FOR EXECUTION**, subject to the standard pre-production step noted in §E (RLS linter + manual cross-tenant penetration check) and to Laravel-side implementation of the two Laravel-owned responsibilities in §E (capacity lock orchestration, webhook signature verification) that this package intentionally does not and cannot own from the database side alone.
