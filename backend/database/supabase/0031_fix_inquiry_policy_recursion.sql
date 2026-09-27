-- =====================================================================
-- 0031_fix_inquiry_policy_recursion.sql
-- NaSeRy
--
-- Apply AFTER 0030.
--
-- Problem (found in B4 testing, 2026-09-24):
--   inquiries_org_read            (on inquiries)            subqueries inquiry_organizations
--   inquiry_orgs_client_read      (on inquiry_organizations) subqueries inquiries
--   PostgreSQL applies every permissive policy, so any RLS read of either
--   table recurses forever:  ERROR 42P17 infinite recursion detected in
--   policy for relation "inquiries". Every policy that subqueries inquiries
--   (inquiry_services_*, quotations_client_read, quotation_history_read,
--   event_progress_client_read) fails the same way. In addition,
--   event_progress_client_read joined events under the CLIENT's RLS, which
--   never lets a client see events rows, so it could never match.
--
-- Fix: the pattern the schema already uses for user_org_ids() (0022).
--   SECURITY DEFINER helpers resolve "inquiries I own", "inquiries my
--   organizations were invited to" and "events created from my inquiries"
--   without re-entering RLS; identity always comes from auth.uid().
--   The affected policies are recreated with the SAME names, commands,
--   roles and meaning, using the helpers instead of cross-table subqueries.
--
-- No table, column, grant on tables, or row changes.
-- Rollback: 0031_fix_inquiry_policy_recursion.down.sql
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Helpers (owned by the migration role, which bypasses RLS)
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION user_client_inquiry_ids() RETURNS SETOF uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT id FROM inquiries WHERE client_user_id = auth.uid();
$$;

CREATE OR REPLACE FUNCTION user_invited_inquiry_ids() RETURNS SETOF uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT inquiry_id FROM inquiry_organizations
  WHERE organization_id IN (SELECT user_org_ids());
$$;

CREATE OR REPLACE FUNCTION user_client_event_ids() RETURNS SETOF uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT e.id FROM events e
  JOIN inquiries i ON i.id = e.inquiry_id
  WHERE i.client_user_id = auth.uid();
$$;

REVOKE EXECUTE ON FUNCTION user_client_inquiry_ids()  FROM PUBLIC, anon;
REVOKE EXECUTE ON FUNCTION user_invited_inquiry_ids() FROM PUBLIC, anon;
REVOKE EXECUTE ON FUNCTION user_client_event_ids()    FROM PUBLIC, anon;
GRANT  EXECUTE ON FUNCTION user_client_inquiry_ids()  TO authenticated;
GRANT  EXECUTE ON FUNCTION user_invited_inquiry_ids() TO authenticated;
GRANT  EXECUTE ON FUNCTION user_client_event_ids()    TO authenticated;

-- ---------------------------------------------------------------------
-- Recreated policies (same name / command / roles / meaning)
-- ---------------------------------------------------------------------
DROP POLICY inquiries_org_read ON inquiries;
CREATE POLICY inquiries_org_read ON inquiries FOR SELECT
  USING (id IN (SELECT user_invited_inquiry_ids()));

DROP POLICY inquiry_orgs_client_read ON inquiry_organizations;
CREATE POLICY inquiry_orgs_client_read ON inquiry_organizations FOR SELECT
  USING (inquiry_id IN (SELECT user_client_inquiry_ids()));

DROP POLICY inquiry_services_client_write ON inquiry_services;
CREATE POLICY inquiry_services_client_write ON inquiry_services FOR INSERT
  WITH CHECK (inquiry_id IN (SELECT user_client_inquiry_ids()));

DROP POLICY inquiry_services_visible ON inquiry_services;
CREATE POLICY inquiry_services_visible ON inquiry_services FOR SELECT
  USING (
    inquiry_id IN (SELECT user_client_inquiry_ids())
    OR inquiry_id IN (SELECT user_invited_inquiry_ids())
  );

DROP POLICY quotations_client_read ON quotations;
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (inquiry_id IN (SELECT user_client_inquiry_ids()));

DROP POLICY quotation_history_read ON quotation_status_history;
CREATE POLICY quotation_history_read ON quotation_status_history FOR SELECT
  USING (
    quotation_id IN (SELECT q.id FROM quotations q WHERE q.organization_id IN (SELECT user_org_ids()))
    OR quotation_id IN (SELECT q.id FROM quotations q WHERE q.inquiry_id IN (SELECT user_client_inquiry_ids()))
  );

DROP POLICY event_progress_client_read ON event_progress;
CREATE POLICY event_progress_client_read ON event_progress FOR SELECT
  USING (event_id IN (SELECT user_client_event_ids()));

COMMIT;

-- =====================================================================
-- END 0031
-- =====================================================================
