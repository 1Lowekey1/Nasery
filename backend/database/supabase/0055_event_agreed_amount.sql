-- =====================================================================
-- 0055_event_agreed_amount.sql
-- NaSeRy
--
-- Apply AFTER 0054.
--
-- Purpose (user, 2026-11-01): the price an organizer and client finally
-- agree often differs from the accepted quotation (added services, discounts,
-- final accounting after the event). Organizers record it on the event so the
-- won value in their analytics stays accurate.
--
--   events
--     agreed_amount        the final agreed price; NULL = the accepted
--                          quotation's total still applies
--     agreed_amount_note   what changed (e.g. "Added photo booth, less 5%")
--
-- Organizer-only: the client API doesn't return these columns. Changes are
-- audited (old and new amount). The app role may update the two columns
-- (column grants, as 0034), under the existing organization RLS policies.
--
-- Rollback: 0055_event_agreed_amount.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE events
  ADD COLUMN agreed_amount      numeric(12,2) CHECK (agreed_amount IS NULL OR agreed_amount >= 0),
  ADD COLUMN agreed_amount_note text CHECK (agreed_amount_note IS NULL OR char_length(agreed_amount_note) <= 500);

GRANT UPDATE (agreed_amount, agreed_amount_note) ON events TO nasery_app;

COMMIT;

-- =====================================================================
-- END 0055
-- =====================================================================
