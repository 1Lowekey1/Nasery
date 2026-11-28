-- =====================================================================
-- 0068_event_max_per_buyer.sql
-- NaSeRy
--
-- Apply AFTER 0067.
--
-- Purpose (advisor's final consultation follow-up, 2026-11-27): one order
-- was capped at 10 tickets for every event, and a buyer could simply order
-- again. Organizers now choose, per event, how many tickets one buyer may
-- hold across all their orders. A buyer is their account, or for guests
-- the e-mail they confirmed with a code.
--
--   events
--     max_per_buyer   1 to 100; NULL = the default (10)
--
-- The app role may update the column (column grants, as 0055), under the
-- existing organization RLS policies.
--
-- Rollback: 0068_event_max_per_buyer.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE events
  ADD COLUMN max_per_buyer smallint CHECK (max_per_buyer IS NULL OR max_per_buyer BETWEEN 1 AND 100);

GRANT UPDATE (max_per_buyer) ON events TO nasery_app;

COMMIT;
