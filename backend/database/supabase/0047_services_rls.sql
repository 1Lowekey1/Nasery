-- =====================================================================
-- 0047_services_rls.sql
-- NaSeRy
--
-- Apply AFTER 0046.
--
-- Purpose (2026-10-16, found while building the self-hosted database):
-- the shared Supabase project has row level security ENABLED on the
-- `services` catalog, but no migration file ever did that (it was turned
-- on outside the SQL files, most likely from the Supabase dashboard).
-- A database built from 0001–0046 alone therefore had RLS off on
-- `services`, and `nasery:doctor` ("every public table has RLS enabled")
-- failed. This records the live state so both databases match.
--
-- No policy is added: the app role has no grant on `services` (doctor:
-- "app role cannot read ungranted table"), and the marketplace reads the
-- catalog through the logged system context. On Supabase this statement
-- is a no-op (already enabled).
--
-- Rollback: 0047_services_rls.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE services ENABLE ROW LEVEL SECURITY;

COMMIT;
