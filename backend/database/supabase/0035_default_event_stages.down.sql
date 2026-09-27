-- Rollback of 0035_default_event_stages.sql. Clears references first, then
-- removes the seeded system stages.
BEGIN;

UPDATE event_progress SET current_stage_id = NULL
WHERE current_stage_id IN (
  SELECT id FROM event_stages
  WHERE organization_id IS NULL AND name IN ('Planning', 'Confirmed', 'Ready', 'Event day', 'Completed')
);

DELETE FROM event_stages
WHERE organization_id IS NULL AND name IN ('Planning', 'Confirmed', 'Ready', 'Event day', 'Completed');

COMMIT;
