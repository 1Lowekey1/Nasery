-- =====================================================================
-- 0051_inquiry_revisions.sql
-- NaSeRy
--
-- Apply AFTER 0050.
--
-- Purpose (2026-10-20): a client may edit a request after sending it (date,
-- guests, budget, services, notes) while it is still open. Organizers who
-- already quoted are told, and see that their quotation predates the change
-- so they can revise it (the existing quotation revise flow).
--
--   inquiries.revised_at       when the client last changed the sent request
--                              (NULL = never changed after sending)
--   inquiries.revision_count   how many times; the app caps it (5) so invited
--                              organizers aren't flooded with notices
--
-- Both are written by the app in the system context after it checks the
-- owner and status. Existing rows: NULL / 0. Table grants and RLS unchanged.
--
-- Rollback: 0051_inquiry_revisions.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE inquiries
  ADD COLUMN revised_at     timestamptz,
  ADD COLUMN revision_count integer NOT NULL DEFAULT 0 CHECK (revision_count >= 0);

COMMIT;
