-- Rollback for 0051_inquiry_revisions.sql
BEGIN;

ALTER TABLE inquiries
  DROP COLUMN IF EXISTS revision_count,
  DROP COLUMN IF EXISTS revised_at;

COMMIT;
