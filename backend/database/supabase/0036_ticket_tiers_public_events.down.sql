-- Rollback of 0036_ticket_tiers_public_events.sql. Drops the public views
-- and the tier zone/benefits/order columns (export them first if needed).
BEGIN;

DROP VIEW IF EXISTS public_ticket_types;
DROP VIEW IF EXISTS public_events;

ALTER TABLE ticket_types
  DROP CONSTRAINT IF EXISTS chk_ticket_types_zone_len,
  DROP CONSTRAINT IF EXISTS chk_ticket_types_benefits,
  DROP CONSTRAINT IF EXISTS chk_ticket_types_sales_window,
  DROP COLUMN IF EXISTS zone_name,
  DROP COLUMN IF EXISTS benefits,
  DROP COLUMN IF EXISTS sort_order;

COMMIT;
