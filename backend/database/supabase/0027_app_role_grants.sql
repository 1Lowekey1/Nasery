-- =====================================================================
-- 0027_app_role_grants.sql
-- NaSeRy
--
-- Apply AFTER 0001-0026.
--
-- Purpose:
--   Make RLS an actual backstop for Laravel traffic. Before this migration,
--   Laravel connected as `postgres` (BYPASSRLS), so none of the 0022/0022b
--   policies applied to it, and `anon`/`authenticated` had no base-table
--   grants at all (so even the security_invoker public views from 0026
--   returned "permission denied").
--
-- Design:
--   * `nasery_app` is a NOLOGIN, NOBYPASSRLS role. Laravel keeps logging in
--     as `postgres` but, per request and inside a transaction, runs
--       SET LOCAL ROLE nasery_app;
--       SELECT set_config('request.jwt.claims', '{"sub":"<users.id>"}', true);
--     so every policy and every auth.uid()-based SECURITY DEFINER function
--     (0021) sees the real caller.
--   * `nasery_app` inherits `authenticated` only to obtain USAGE on the
--     `auth` schema (needed to evaluate auth.uid() inside policies);
--     `postgres` has no GRANT OPTION on that schema, so it cannot be
--     granted directly.
--   * Supabase's own `anon`/`authenticated` roles gain NOTHING here, so
--     the public anon key still exposes no base table through the Data API.
--   * Table privileges mirror exactly the commands that have an RLS policy
--     (derived from pg_policies on 2026-09-24). A command without a policy
--     would be denied by RLS anyway; not granting it adds a second layer.
--   * Operations RLS intentionally does not model (sign-up, organization
--     creation, guest registration, webhooks, audit writes, marketplace
--     reads) run in Laravel's explicit, logged "system" context as
--     `postgres`, never through broad grants here.
--
-- This migration does NOT alter any table, column, constraint, policy or
-- function body.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 1. Application role
-- ---------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nasery_app') THEN
    CREATE ROLE nasery_app NOLOGIN INHERIT NOBYPASSRLS;
  END IF;
END
$$;

-- Laravel's login role may switch into nasery_app but must not inherit its
-- (narrower) privileges implicitly.
GRANT nasery_app TO postgres WITH INHERIT FALSE, SET TRUE;

-- USAGE on schema auth (for auth.uid()) and SELECT on the four public views.
GRANT authenticated TO nasery_app WITH INHERIT TRUE, SET FALSE;

GRANT USAGE ON SCHEMA public TO nasery_app;

-- ---------------------------------------------------------------------
-- 2. Table privileges (mirror of RLS policy coverage)
-- ---------------------------------------------------------------------

-- Policies cover SELECT/INSERT/UPDATE/DELETE (FOR ALL)
GRANT SELECT, INSERT, UPDATE, DELETE ON
  admin_access_grants,
  attendance_records,
  checklists,
  event_member_permissions,
  event_progress,
  event_sessions,
  event_stages,
  event_types,
  inquiries,
  inquiry_organizations,
  member_permission_overrides,
  moderation_cases,
  notification_preferences,
  notifications,
  organization_profiles,
  organization_roles,
  organization_services,
  platform_settings,
  qr_tickets,
  quotation_items,
  quotations,
  registrations,
  review_responses,
  reviews,
  role_permissions,
  task_assignees,
  tasks,
  ticket_holds,
  ticket_types,
  tickets,
  walk_in_records
TO nasery_app;

-- Policies cover SELECT, INSERT, UPDATE
GRANT SELECT, INSERT, UPDATE ON
  event_members,
  events,
  organization_members,
  platform_announcements
TO nasery_app;

-- Policies cover SELECT, INSERT
GRANT SELECT, INSERT ON
  inquiry_services,
  moderation_reports,
  platform_fee_configs,
  review_reports
TO nasery_app;

-- Policies cover SELECT, UPDATE
GRANT SELECT, UPDATE ON
  organizations,
  users
TO nasery_app;

-- Policies cover SELECT only (writes happen via 0021 functions or the
-- Laravel system context; audit_logs in particular stays read-only here).
GRANT SELECT ON
  attendees,
  audit_logs,
  features,
  notification_deliveries,
  organizer_approval_requests,
  ownership_transfers,
  payment_refunds,
  payment_webhook_events,
  payments,
  permissions,
  plan_entitlements,
  plans,
  platform_fee_snapshots,
  quotation_status_history,
  review_edits,
  subscription_invoices,
  subscriptions,
  ticket_transfers
TO nasery_app;

-- `services` has RLS enabled and no policy: intentionally no grant.

-- ---------------------------------------------------------------------
-- 3. Close the one SECURITY DEFINER function with no caller check
-- ---------------------------------------------------------------------
-- expire_stale_holds() had default PUBLIC EXECUTE, which made it callable
-- by anyone holding the anon key via the Data API (/rpc). It is a
-- maintenance routine for the Laravel scheduler (runs as postgres).
REVOKE EXECUTE ON FUNCTION expire_stale_holds() FROM PUBLIC, anon, authenticated;

-- ---------------------------------------------------------------------
-- 4. Schema for Laravel infrastructure tables
-- ---------------------------------------------------------------------
-- migrations, cache, jobs, sessions, idempotency keys, etc. Not exposed
-- by the Supabase Data API (only `public`/`graphql_public` are) and not
-- granted to nasery_app/anon/authenticated.
CREATE SCHEMA IF NOT EXISTS laravel AUTHORIZATION postgres;
REVOKE ALL ON SCHEMA laravel FROM PUBLIC;

COMMIT;

-- =====================================================================
-- END 0027
-- =====================================================================
