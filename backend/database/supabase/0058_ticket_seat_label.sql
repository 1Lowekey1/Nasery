-- =====================================================================
-- 0058_ticket_seat_label.sql
-- NaSeRy
--
-- Apply AFTER 0057.
--
-- Purpose (user, 2026-11): an optional seat or table for each guest, so a
-- wedding can print "Table 4" on the ticket. Off by default: nothing changes
-- for events that don't use it, and zones stay the normal way to seat people.
-- A free-text label only: no seat map and no automatic numbering.
--
--   tickets.seat_label   e.g. "Table 4" or "A12"; null = no assigned seat
--
-- Written only in Laravel's logged system context after the attendee.manage
-- check, like every other ticket write.
--
-- Rollback: 0058_ticket_seat_label.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE tickets
  ADD COLUMN seat_label text CHECK (seat_label IS NULL OR char_length(btrim(seat_label)) BETWEEN 1 AND 30);

COMMENT ON COLUMN tickets.seat_label IS
  'Optional seat or table for this guest, set by the organizer. Free text.';

COMMIT;

-- =====================================================================
-- END 0058
-- =====================================================================
