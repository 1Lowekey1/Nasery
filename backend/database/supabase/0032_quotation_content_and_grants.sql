-- =====================================================================
-- 0032_quotation_content_and_grants.sql
-- NaSeRy
--
-- Apply AFTER 0031.
--
-- Purpose (B5 Quotation):
--   1. Content the paper describes for a bid: a package name and the
--      organizer's proposal (pitch / service plan). `terms` stays for
--      conditions (payment schedule, inclusions/exclusions).
--   2. Column-level grants: quotation status and decision fields change only
--      through Laravel's checked, logged system context (submit, withdraw,
--      revise, client decline) and accept_quotation(). A new row therefore
--      always starts as 'draft'.
--   3. Clients never see draft quotations, and can read the cost breakdown
--      (quotation_items) of quotations sent to them. Before this, the
--      breakdown had no client read policy at all.
--
-- No data is changed. Rollback: 0032_quotation_content_and_grants.down.sql
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 1. Content columns
-- ---------------------------------------------------------------------
ALTER TABLE quotations
  ADD COLUMN package_name text,
  ADD COLUMN proposal     text,
  ADD CONSTRAINT chk_quotations_package_name_len CHECK (package_name IS NULL OR char_length(package_name) <= 150),
  ADD CONSTRAINT chk_quotations_proposal_len     CHECK (proposal IS NULL OR char_length(proposal) <= 5000);

-- ---------------------------------------------------------------------
-- 2. Grants: no direct writes of status / submitted_at / decided_* /
--    inquiry / organization after creation.
-- ---------------------------------------------------------------------
REVOKE INSERT, UPDATE ON quotations FROM nasery_app;
GRANT INSERT (id, inquiry_id, organization_id, total_amount, currency, terms, package_name, proposal, created_at, updated_at)
  ON quotations TO nasery_app;
GRANT UPDATE (total_amount, terms, package_name, proposal, updated_at)
  ON quotations TO nasery_app;

-- Acceptance is the client's sanctioned path (SECURITY DEFINER, checks the
-- caller owns the inquiry). Made explicit instead of relying on PUBLIC.
GRANT EXECUTE ON FUNCTION accept_quotation(uuid) TO nasery_app;

-- ---------------------------------------------------------------------
-- 3. Client visibility
-- ---------------------------------------------------------------------
DROP POLICY quotations_client_read ON quotations;
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (status <> 'draft' AND inquiry_id IN (SELECT user_client_inquiry_ids()));

CREATE POLICY quotation_items_client_read ON quotation_items FOR SELECT
  USING (quotation_id IN (
    SELECT q.id FROM quotations q
    WHERE q.status <> 'draft' AND q.inquiry_id IN (SELECT user_client_inquiry_ids())
  ));

COMMIT;

-- =====================================================================
-- END 0032
-- =====================================================================
