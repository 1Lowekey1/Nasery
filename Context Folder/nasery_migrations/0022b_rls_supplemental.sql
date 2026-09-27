-- =====================================================================
-- 0022b_rls_supplemental.sql
-- Purpose: RLS coverage for the remaining tables not already handled in
--          0022_rls.sql (which covered the highest-sensitivity/most
--          load-bearing tables explicitly per the access matrix). Every
--          table here follows the SAME established pattern -- no new
--          policy shapes are introduced.
-- Dependencies: 0001-0022
-- =====================================================================

ALTER TABLE organization_roles         ENABLE ROW LEVEL SECURITY;
ALTER TABLE permissions                ENABLE ROW LEVEL SECURITY;
ALTER TABLE role_permissions           ENABLE ROW LEVEL SECURITY;
ALTER TABLE member_permission_overrides ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_member_permissions   ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_types               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_holds               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_transfers           ENABLE ROW LEVEL SECURITY;
ALTER TABLE qr_tickets                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotation_items            ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotation_status_history   ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiry_services           ENABLE ROW LEVEL SECURITY;
ALTER TABLE task_assignees             ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_stages               ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_deliveries    ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_edits               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ownership_transfers        ENABLE ROW LEVEL SECURITY;
ALTER TABLE organizer_approval_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_announcements     ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_webhook_events     ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_fee_configs       ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_fee_snapshots     ENABLE ROW LEVEL SECURITY;
ALTER TABLE plans                      ENABLE ROW LEVEL SECURITY;
ALTER TABLE features                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE plan_entitlements          ENABLE ROW LEVEL SECURITY;

-- Permission/role catalogs: organization-scoped roles are private to the org;
-- the global `permissions` catalog and system-default roles are readable by
-- any authenticated user (needed to render permission-pickers in the UI).
CREATE POLICY org_roles_org ON organization_roles FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY permissions_read_all ON permissions FOR SELECT USING (true);
CREATE POLICY role_permissions_org ON role_permissions FOR ALL
  USING (organization_role_id IN (SELECT id FROM organization_roles
           WHERE organization_id IS NULL OR organization_id IN (SELECT user_org_ids())))
  WITH CHECK (organization_role_id IN (SELECT id FROM organization_roles WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY member_perm_overrides_org ON member_permission_overrides FOR ALL
  USING (organization_member_id IN (SELECT id FROM organization_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (organization_member_id IN (SELECT id FROM organization_members WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_member_perms_org ON event_member_permissions FOR ALL
  USING (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())));

-- Ticketing internals: organization-scoped, same join pattern as `tickets`/`registrations`.
CREATE POLICY ticket_types_org ON ticket_types FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY ticket_holds_org ON ticket_holds FOR ALL
  USING (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
CREATE POLICY ticket_transfers_org ON ticket_transfers FOR SELECT
  USING (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
-- No direct INSERT policy: ticket_transfers rows are written only via transfer_ticket() (0021), a SECURITY DEFINER function.
CREATE POLICY qr_tickets_org ON qr_tickets FOR ALL
  USING (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));

-- Quotation internals.
CREATE POLICY quotation_items_org ON quotation_items FOR ALL
  USING (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY quotation_history_read ON quotation_status_history FOR SELECT
  USING (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids()))
     OR quotation_id IN (SELECT id FROM quotations WHERE inquiry_id IN (
          SELECT id FROM inquiries WHERE client_user_id = auth.uid())));
-- No INSERT policy: written only via accept_quotation() and similar SECURITY DEFINER domain actions.

CREATE POLICY inquiry_services_visible ON inquiry_services FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid())
     OR inquiry_id IN (SELECT inquiry_id FROM inquiry_organizations WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY inquiry_services_client_write ON inquiry_services FOR INSERT
  WITH CHECK (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));

-- Preparation internals.
CREATE POLICY task_assignees_org ON task_assignees FOR ALL
  USING (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_stages_org ON event_stages FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- Notification delivery detail: same scope as the parent notification.
CREATE POLICY notification_deliveries_self ON notification_deliveries FOR SELECT
  USING (notification_id IN (SELECT id FROM notifications WHERE recipient_user_id = auth.uid()));

-- Review edit history: same visibility as the review itself.
CREATE POLICY review_edits_visible ON review_edits FOR SELECT
  USING (review_id IN (SELECT id FROM reviews WHERE client_user_id = auth.uid()
           OR organization_id IN (SELECT user_org_ids())));

-- Append-only audit-adjacent tables: org-scoped read, no direct write policy
-- (written only via the SECURITY DEFINER domain functions in 0021).
CREATE POLICY ownership_transfers_read ON ownership_transfers FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY approval_requests_org_read ON organizer_approval_requests FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());

-- Public announcements: readable by everyone; writable only by platform admins.
CREATE POLICY announcements_read_all ON platform_announcements FOR SELECT USING (deleted_at IS NULL);
CREATE POLICY announcements_admin_write ON platform_announcements FOR INSERT WITH CHECK (is_platform_admin());
CREATE POLICY announcements_admin_update ON platform_announcements FOR UPDATE
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());

-- System-only tables: no policy for `authenticated` at all -- these are written and read
-- exclusively by privileged service connections (webhook handler, fee-config admin tooling),
-- which is precisely what having zero permissive policies (with RLS enabled) achieves.
CREATE POLICY webhook_events_admin_read ON payment_webhook_events FOR SELECT USING (is_platform_admin());
CREATE POLICY fee_configs_admin_read ON platform_fee_configs FOR SELECT USING (true);   -- rates are not sensitive; needed client-side for price display
CREATE POLICY fee_configs_admin_write ON platform_fee_configs FOR INSERT WITH CHECK (is_platform_admin());
CREATE POLICY fee_snapshots_org_read ON platform_fee_snapshots FOR SELECT
  USING (payment_id IN (SELECT id FROM payments WHERE registration_id IN (
           SELECT id FROM registrations WHERE event_id IN (
             SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))));

-- SaaS catalog: public read (needed for a pricing page), admin-only write.
CREATE POLICY plans_read_all ON plans FOR SELECT USING (is_active);
CREATE POLICY features_read_all ON features FOR SELECT USING (true);
CREATE POLICY plan_entitlements_read_all ON plan_entitlements FOR SELECT USING (true);
