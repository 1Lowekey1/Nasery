-- Rollback of 0032_quotation_content_and_grants.sql (restores the 0027/0031 state).
-- Drops package_name/proposal: export them first if they hold real data.
BEGIN;

DROP POLICY IF EXISTS quotation_items_client_read ON quotation_items;
DROP POLICY quotations_client_read ON quotations;
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (inquiry_id IN (SELECT user_client_inquiry_ids()));

REVOKE INSERT, UPDATE ON quotations FROM nasery_app;
GRANT INSERT, UPDATE ON quotations TO nasery_app;
REVOKE EXECUTE ON FUNCTION accept_quotation(uuid) FROM nasery_app;

ALTER TABLE quotations
  DROP CONSTRAINT IF EXISTS chk_quotations_package_name_len,
  DROP CONSTRAINT IF EXISTS chk_quotations_proposal_len,
  DROP COLUMN IF EXISTS package_name,
  DROP COLUMN IF EXISTS proposal;

COMMIT;
