-- Rollback for 0031: restore the original (recursive) 0022 policy definitions
-- and drop the helper functions. NOTE: this brings back the 42P17 recursion.
BEGIN;

DROP POLICY inquiries_org_read ON inquiries;
CREATE POLICY inquiries_org_read ON inquiries FOR SELECT
  USING (id IN (SELECT inquiry_organizations.inquiry_id FROM inquiry_organizations
                WHERE inquiry_organizations.organization_id IN (SELECT user_org_ids())));

DROP POLICY inquiry_orgs_client_read ON inquiry_organizations;
CREATE POLICY inquiry_orgs_client_read ON inquiry_organizations FOR SELECT
  USING (inquiry_id IN (SELECT inquiries.id FROM inquiries WHERE inquiries.client_user_id = auth.uid()));

DROP POLICY inquiry_services_client_write ON inquiry_services;
CREATE POLICY inquiry_services_client_write ON inquiry_services FOR INSERT
  WITH CHECK (inquiry_id IN (SELECT inquiries.id FROM inquiries WHERE inquiries.client_user_id = auth.uid()));

DROP POLICY inquiry_services_visible ON inquiry_services;
CREATE POLICY inquiry_services_visible ON inquiry_services FOR SELECT
  USING ((inquiry_id IN (SELECT inquiries.id FROM inquiries WHERE inquiries.client_user_id = auth.uid()))
      OR (inquiry_id IN (SELECT inquiry_organizations.inquiry_id FROM inquiry_organizations
                         WHERE inquiry_organizations.organization_id IN (SELECT user_org_ids()))));

DROP POLICY quotations_client_read ON quotations;
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (inquiry_id IN (SELECT inquiries.id FROM inquiries WHERE inquiries.client_user_id = auth.uid()));

DROP POLICY quotation_history_read ON quotation_status_history;
CREATE POLICY quotation_history_read ON quotation_status_history FOR SELECT
  USING ((quotation_id IN (SELECT quotations.id FROM quotations WHERE quotations.organization_id IN (SELECT user_org_ids())))
      OR (quotation_id IN (SELECT quotations.id FROM quotations WHERE quotations.inquiry_id IN
                           (SELECT inquiries.id FROM inquiries WHERE inquiries.client_user_id = auth.uid()))));

DROP POLICY event_progress_client_read ON event_progress;
CREATE POLICY event_progress_client_read ON event_progress FOR SELECT
  USING (event_id IN (SELECT e.id FROM events e JOIN inquiries i ON i.id = e.inquiry_id WHERE i.client_user_id = auth.uid()));

DROP FUNCTION user_client_event_ids();
DROP FUNCTION user_invited_inquiry_ids();
DROP FUNCTION user_client_inquiry_ids();

COMMIT;
