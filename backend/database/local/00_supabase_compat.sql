-- =====================================================================
-- 00_supabase_compat.sql
-- NaSeRy — local PostgreSQL (self-hosted) only. NEVER run on Supabase.
--
-- The schema (database/supabase/0001-0046) was written for Supabase. It
-- uses exactly these Supabase pieces, which a plain PostgreSQL lacks:
--   * the login role `postgres` as a NON-superuser that owns everything
--     and has BYPASSRLS (Laravel connects as it; 0027 grants nasery_app
--     TO postgres),
--   * the roles `anon`, `authenticated`, `service_role` (grantees in the
--     schema and of the public views),
--   * the `auth` schema with `auth.uid()`, which reads the caller's id from
--     `request.jwt.claims` (Laravel sets it per request, see 0027).
--
-- Run ONCE, as the cluster's superuser, connected to the NaSeRy database,
-- before the 0001… files. The local setup command does this for you.
--
-- Our `postgres` role mirrors Supabase's: LOGIN, CREATEROLE, CREATEDB,
-- BYPASSRLS, but NOT SUPERUSER (so a bug can't escalate to the server).
-- Its password is set separately (never stored in this file).
-- =====================================================================

-- Supabase API roles: no login, no privileges beyond what the schema grants.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    CREATE ROLE anon NOLOGIN NOINHERIT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    CREATE ROLE authenticated NOLOGIN NOINHERIT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
    CREATE ROLE service_role NOLOGIN NOINHERIT BYPASSRLS;
  END IF;
END
$$;

-- The application login (same name and attributes as on Supabase).
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'postgres') THEN
    CREATE ROLE postgres LOGIN CREATEROLE CREATEDB BYPASSRLS NOSUPERUSER;
  END IF;
END
$$;
-- 0027 does "GRANT authenticated TO nasery_app"; on Supabase postgres may grant
-- membership in these roles (it administers them). Mirror that here.
GRANT anon, authenticated, service_role TO postgres WITH ADMIN OPTION;

-- The database and public schema belong to postgres, as on Supabase.
DO $$
BEGIN
  EXECUTE format('ALTER DATABASE %I OWNER TO postgres', current_database());
END
$$;
ALTER SCHEMA public OWNER TO postgres;

-- Extensions the schema uses (CREATE EXTENSION IF NOT EXISTS in 0001 then
-- finds them). Created by the superuser so postgres needs no extra rights.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- auth.uid(): Supabase's definition. Owned by the superuser (postgres has no
-- GRANT OPTION on schema auth on Supabase either; 0027 relies on that).
CREATE SCHEMA IF NOT EXISTS auth;
REVOKE ALL ON SCHEMA auth FROM PUBLIC;
GRANT USAGE ON SCHEMA auth TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION auth.uid() RETURNS uuid
LANGUAGE sql STABLE
AS $$
  SELECT coalesce(
    nullif(current_setting('request.jwt.claim.sub', true), ''),
    (nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'sub')
  )::uuid
$$;
REVOKE ALL ON FUNCTION auth.uid() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION auth.uid() TO anon, authenticated, service_role, postgres;
