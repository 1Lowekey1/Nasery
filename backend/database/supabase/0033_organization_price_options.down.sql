-- Rollback of 0033_organization_price_options.sql. Drops organizers' price
-- lists: export organization_price_options first if it holds real data.
BEGIN;

DROP VIEW IF EXISTS public_organization_price_options;
DROP TABLE IF EXISTS organization_price_options;
DROP FUNCTION IF EXISTS check_price_option_service();

COMMIT;
