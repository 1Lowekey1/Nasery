-- =====================================================================
-- 0044_task_reviews.sql
-- NaSeRy
--
-- Apply AFTER 0043.
--
-- Purpose (preparation reviews, 2026-09-30; the redesigned preparation page
-- and team dashboard): a team member finishing a task can leave a note, and
-- the organizer reviews it: "verified", or "changes requested" (which puts
-- the task back in progress with the reviewer's note).
--
--   tasks.completion_note  what the person who finished it says
--   tasks.review_status    NULL (not done) | pending | verified | changes_requested
--   tasks.review_note      the reviewer's note (changes requested)
--   tasks.reviewed_by / reviewed_at
--
-- The existing tasks policies and grants cover the new columns.
--
-- Rollback: 0044_task_reviews.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE tasks
  ADD COLUMN completion_note text CHECK (completion_note IS NULL OR char_length(completion_note) <= 1000),
  ADD COLUMN review_status   text CHECK (review_status IN ('pending', 'verified', 'changes_requested')),
  ADD COLUMN review_note     text CHECK (review_note IS NULL OR char_length(review_note) <= 1000),
  ADD COLUMN reviewed_by     uuid REFERENCES users(id),
  ADD COLUMN reviewed_at     timestamptz;

COMMIT;

-- =====================================================================
-- END 0044
-- =====================================================================
