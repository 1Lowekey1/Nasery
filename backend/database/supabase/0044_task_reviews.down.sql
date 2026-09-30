-- Rollback of 0044_task_reviews.sql (drops completion notes and reviews).
BEGIN;
ALTER TABLE tasks
  DROP COLUMN IF EXISTS reviewed_at,
  DROP COLUMN IF EXISTS reviewed_by,
  DROP COLUMN IF EXISTS review_note,
  DROP COLUMN IF EXISTS review_status,
  DROP COLUMN IF EXISTS completion_note;
COMMIT;
