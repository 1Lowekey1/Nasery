-- =====================================================================
-- 0064_mood_board_limit_20.sql
-- NaSeRy
--
-- Apply AFTER 0063.
--
-- Purpose (members' testing, 2026-11-23): a request's mood board holds up
-- to 20 pictures (was 8), now that the booked organizer adds to it too.
-- Only the size rule changes; no data changes.
--
-- Rollback: 0064_mood_board_limit_20.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE inquiries DROP CONSTRAINT inquiries_mood_board_check;
ALTER TABLE inquiries ADD CONSTRAINT inquiries_mood_board_check
  CHECK (jsonb_typeof(mood_board) = 'array' AND jsonb_array_length(mood_board) <= 20);

COMMIT;
