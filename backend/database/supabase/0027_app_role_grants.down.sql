-- =====================================================================
-- 0027_app_role_grants.down.sql  (rollback for 0027)
--
-- Restores the pre-0027 state. Drops the private `laravel` schema, which
-- deletes Laravel's sessions/cache/jobs/idempotency tables (no business
-- data lives there). Afterwards Laravel can no longer switch into
-- nasery_app, so set DB_RLS_ENABLED=false or the API will fail.
-- =====================================================================

BEGIN;

DROP SCHEMA IF EXISTS laravel CASCADE;

GRANT EXECUTE ON FUNCTION expire_stale_holds() TO PUBLIC;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nasery_app') THEN
    EXECUTE 'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM nasery_app';
    EXECUTE 'REVOKE USAGE ON SCHEMA public FROM nasery_app';
    EXECUTE 'REVOKE authenticated FROM nasery_app';
    EXECUTE 'REVOKE nasery_app FROM postgres';
    EXECUTE 'DROP ROLE nasery_app';
  END IF;
END
$$;

COMMIT;
