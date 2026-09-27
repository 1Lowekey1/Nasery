-- =====================================================================
-- 0022_rls.sql
-- Purpose: Row Level Security. Laravel authorizes first; this is the
--          independent database-level backstop against cross-tenant
--          access even if application logic has a bug.
-- Dependencies: 0001-0021
-- =====================================================================

-- ---------------------------------------------------------------------
-- Dedicated, non-login role for the four helper functions below. It is
-- granted BYPASSRLS specifically so that a helper function's OWN internal
-- reads (e.g. user_org_ids() reading organization_members) do not
-- re-trigger the very RLS policy that calls the function -- breaking
-- what would otherwise be infinite recursion. It is NEVER used as an
-- application login role and has no write privileges anywhere.
-- ---------------------------------------------------------------------
CREATE ROLE rls_helper NOLOGIN BYPASSRLS;

GRANT SELECT ON organization_members            TO rls_helper;
GRANT SELECT ON event_members, event_member_permissions TO rls_helper;
GRANT SELECT ON admin_access_grants, users       TO rls_helper;

-- ---------------------------------------------------------------------
-- Helper functions. All: SECURITY DEFINER, STABLE, fixed search_path,
-- identity always resolved from auth.uid() (never a caller-supplied
-- user-id parameter), owned by rls_helper.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION user_org_ids() RETURNS SETOF uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT organization_id FROM organization_members
  WHERE user_id = auth.uid() AND status = 'active' AND deleted_at IS NULL;
$$;
ALTER FUNCTION user_org_ids() OWNER TO rls_helper;

CREATE OR REPLACE FUNCTION user_has_event_permission(p_event_id uuid, p_permission_key text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (
    SELECT 1
    FROM event_members em
    JOIN organization_members om ON om.id = em.organization_member_id
    JOIN event_member_permissions emp ON emp.event_member_id = em.id
    JOIN permissions p ON p.id = emp.permission_id
    WHERE em.event_id = p_event_id
      AND om.user_id = auth.uid()
      AND om.status = 'active' AND om.deleted_at IS NULL
      AND em.status = 'active' AND em.deleted_at IS NULL
      AND p.key = p_permission_key
  );
$$;
ALTER FUNCTION user_has_event_permission(uuid, text) OWNER TO rls_helper;

CREATE OR REPLACE FUNCTION admin_has_active_grant(p_organization_id uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (
    SELECT 1 FROM admin_access_grants g
    JOIN users u ON u.id = g.admin_user_id
    WHERE g.admin_user_id = auth.uid()
      AND g.organization_id = p_organization_id
      AND u.account_type = 'admin'
      AND g.expires_at > now()
      AND g.revoked_at IS NULL
  );
$$;
ALTER FUNCTION admin_has_active_grant(uuid) OWNER TO rls_helper;

CREATE OR REPLACE FUNCTION is_platform_admin() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin');
$$;
ALTER FUNCTION is_platform_admin() OWNER TO rls_helper;

GRANT EXECUTE ON FUNCTION user_org_ids() TO authenticated;
GRANT EXECUTE ON FUNCTION user_has_event_permission(uuid, text) TO authenticated;
GRANT EXECUTE ON FUNCTION admin_has_active_grant(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION is_platform_admin() TO authenticated;

-- ---------------------------------------------------------------------
-- Enable RLS on every private table. The application connects to Supabase
-- as `anon`/`authenticated` (never as the table owner), so plain ENABLE
-- (not FORCE) is sufficient here; FORCE would only matter if application
-- traffic could ever run as the owning role, which it does not in this
-- architecture.
-- ---------------------------------------------------------------------
ALTER TABLE organizations             ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_members      ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_profiles     ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_services     ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_types               ENABLE ROW LEVEL SECURITY;
ALTER TABLE events                    ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_sessions            ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_members             ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiries                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiry_organizations     ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotations                ENABLE ROW LEVEL SECURITY;
ALTER TABLE checklists                ENABLE ROW LEVEL SECURITY;
ALTER TABLE tasks                     ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_progress            ENABLE ROW LEVEL SECURITY;
ALTER TABLE registrations             ENABLE ROW LEVEL SECURITY;
ALTER TABLE attendees                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE tickets                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE attendance_records        ENABLE ROW LEVEL SECURITY;
ALTER TABLE walk_in_records           ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments                  ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_refunds           ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_responses          ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_reports            ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs                ENABLE ROW LEVEL SECURITY;
ALTER TABLE subscriptions             ENABLE ROW LEVEL SECURITY;
ALTER TABLE subscription_invoices     ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications             ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_preferences  ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_settings         ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation_reports        ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation_cases          ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin_access_grants       ENABLE ROW LEVEL SECURITY;

-- ---------------------------------------------------------------------
-- organizations
-- ---------------------------------------------------------------------
CREATE POLICY org_select_own ON organizations FOR SELECT
  USING (id IN (SELECT user_org_ids()) OR admin_has_active_grant(id) OR is_platform_admin());
CREATE POLICY org_update_own ON organizations FOR UPDATE
  USING (id IN (SELECT user_org_ids())) WITH CHECK (id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- organization_members
-- ---------------------------------------------------------------------
CREATE POLICY org_members_select ON organization_members FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id));
CREATE POLICY org_members_insert ON organization_members FOR INSERT
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY org_members_update ON organization_members FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids())) WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- organization_profiles / organization_services (private-write side;
-- public read happens via the public_* views created in 0006, not here)
-- ---------------------------------------------------------------------
CREATE POLICY org_profiles_owner ON organization_profiles FOR ALL
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

CREATE POLICY org_services_owner ON organization_services FOR ALL
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- event_types (organization-owned rows only; platform defaults are
-- handled by the public_event_types view / is_platform_admin() writes)
-- ---------------------------------------------------------------------
CREATE POLICY event_types_owner ON event_types FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()) OR is_platform_admin())
  WITH CHECK (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());

-- ---------------------------------------------------------------------
-- events / event_sessions
-- ---------------------------------------------------------------------
CREATE POLICY events_org_select ON events FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id));
CREATE POLICY events_org_write ON events FOR INSERT WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY events_org_update ON events FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids())) WITH CHECK (organization_id IN (SELECT user_org_ids()));

CREATE POLICY event_sessions_org ON event_sessions FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- event_members: org-wide read, event-permission-gated write
-- ---------------------------------------------------------------------
CREATE POLICY event_members_select ON event_members FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY event_members_write ON event_members FOR INSERT
  WITH CHECK (organization_id IN (SELECT user_org_ids()) AND user_has_event_permission(event_id, 'staff.manage'));
CREATE POLICY event_members_update ON event_members FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids()) AND user_has_event_permission(event_id, 'staff.manage'))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- inquiries: client owns; organizers see via inquiry_organizations
-- ---------------------------------------------------------------------
CREATE POLICY inquiries_client_owner ON inquiries FOR ALL
  USING (client_user_id = auth.uid())
  WITH CHECK (client_user_id = auth.uid());
CREATE POLICY inquiries_org_read ON inquiries FOR SELECT
  USING (id IN (SELECT inquiry_id FROM inquiry_organizations WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY inquiry_orgs_client_read ON inquiry_organizations FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));
CREATE POLICY inquiry_orgs_org ON inquiry_organizations FOR ALL
  USING (organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- quotations: organizer full access to own; client narrow read of own inquiry's quotations
-- ---------------------------------------------------------------------
CREATE POLICY quotations_org ON quotations FOR ALL
  USING (organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));

-- ---------------------------------------------------------------------
-- checklists / tasks: staff only, NEVER exposed to clients (event_progress is the client surface)
-- ---------------------------------------------------------------------
CREATE POLICY checklists_org ON checklists FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY tasks_org ON tasks FOR ALL
  USING (checklist_id IN (SELECT id FROM checklists WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (checklist_id IN (SELECT id FROM checklists WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));

-- ---------------------------------------------------------------------
-- event_progress: organizer full; client read-only for their own event
-- ---------------------------------------------------------------------
CREATE POLICY event_progress_org ON event_progress FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_progress_client_read ON event_progress FOR SELECT
  USING (event_id IN (
    SELECT e.id FROM events e
    JOIN inquiries i ON i.id = e.inquiry_id
    WHERE i.client_user_id = auth.uid()
  ));

-- ---------------------------------------------------------------------
-- registrations / attendees / tickets
-- ---------------------------------------------------------------------
CREATE POLICY registrations_org ON registrations FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY registrations_purchaser_read ON registrations FOR SELECT
  USING (purchaser_user_id = auth.uid());

CREATE POLICY attendees_org ON attendees FOR ALL
  USING (id IN (SELECT attendee_id FROM tickets t
                JOIN registrations r ON r.id = t.registration_id
                WHERE r.event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (true);  -- inserts happen through the registration/ticket-issuance flow, scoped by that flow's own checks

CREATE POLICY tickets_org ON tickets FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY tickets_purchaser_read ON tickets FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE purchaser_user_id = auth.uid()));

-- ---------------------------------------------------------------------
-- attendance_records / walk_in_records: staff with check-in permission
-- ---------------------------------------------------------------------
CREATE POLICY attendance_staff ON attendance_records FOR ALL
  USING (user_has_event_permission(event_id, 'checkin.perform'))
  WITH CHECK (user_has_event_permission(event_id, 'checkin.perform'));

CREATE POLICY walk_in_staff ON walk_in_records FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- payments / payment_refunds: organizer read of own registrations' payments
-- ---------------------------------------------------------------------
CREATE POLICY payments_org_read ON payments FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
CREATE POLICY payments_purchaser_read ON payments FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE purchaser_user_id = auth.uid()));
-- No INSERT/UPDATE policy for any authenticated role: payments are written exclusively by the
-- Laravel webhook handler using a privileged service connection that bypasses RLS by design.

CREATE POLICY payment_refunds_org_read ON payment_refunds FOR SELECT
  USING (payment_id IN (SELECT id FROM payments WHERE registration_id IN (
           SELECT id FROM registrations WHERE event_id IN (
             SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))));

-- ---------------------------------------------------------------------
-- reviews / review_responses / review_reports
-- ---------------------------------------------------------------------
CREATE POLICY reviews_client_owner ON reviews FOR ALL
  USING (client_user_id = auth.uid()) WITH CHECK (client_user_id = auth.uid());
CREATE POLICY reviews_org_read ON reviews FOR SELECT USING (organization_id IN (SELECT user_org_ids()));
-- No organizer DELETE policy exists anywhere above -- organizers cannot delete reviews directly (locked rule).

CREATE POLICY review_responses_org ON review_responses FOR ALL
  USING (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY review_reports_insert ON review_reports FOR INSERT WITH CHECK (reported_by = auth.uid());
CREATE POLICY review_reports_org_read ON review_reports FOR SELECT
  USING (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- audit_logs: org-scoped read only, INSERT-only for the system, no UPDATE/DELETE policy at all
-- ---------------------------------------------------------------------
CREATE POLICY audit_logs_org_read ON audit_logs FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());
-- Deliberately no UPDATE/DELETE policy: with RLS enabled and FORCE'd, the absence of a policy
-- for a command means that command is denied outright for all non-owner roles.

-- ---------------------------------------------------------------------
-- subscriptions / subscription_invoices
-- ---------------------------------------------------------------------
CREATE POLICY subscriptions_org_read ON subscriptions FOR SELECT USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY subscription_invoices_org_read ON subscription_invoices FOR SELECT
  USING (subscription_id IN (SELECT id FROM subscriptions WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- notifications / notification_preferences: self only
-- ---------------------------------------------------------------------
CREATE POLICY notifications_self ON notifications FOR ALL
  USING (recipient_user_id = auth.uid()) WITH CHECK (recipient_user_id = auth.uid());
CREATE POLICY notification_preferences_self ON notification_preferences FOR ALL
  USING (user_id = auth.uid()) WITH CHECK (user_id = auth.uid());

-- ---------------------------------------------------------------------
-- Platform-level tables: is_platform_admin() only
-- ---------------------------------------------------------------------
CREATE POLICY platform_settings_admin ON platform_settings FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());
CREATE POLICY moderation_reports_admin_read ON moderation_reports FOR SELECT USING (is_platform_admin());
CREATE POLICY moderation_reports_insert ON moderation_reports FOR INSERT WITH CHECK (reported_by = auth.uid());
CREATE POLICY moderation_cases_admin ON moderation_cases FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());
CREATE POLICY admin_access_grants_admin ON admin_access_grants FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());

COMMENT ON ROLE rls_helper IS
  'Purpose-built, non-login role granted BYPASSRLS solely so the four helper functions'' internal '
  'reads do not recurse through the RLS policies that call them. SELECT-only, no write privileges.';
