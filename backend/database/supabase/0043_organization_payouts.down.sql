-- Rollback of 0043_organization_payouts.sql (drops every recorded payout).
BEGIN;
DROP TABLE IF EXISTS organization_payouts;
COMMIT;
