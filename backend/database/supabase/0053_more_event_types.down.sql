-- Rollback of 0053_more_event_types.sql. Requests and events already using these
-- types keep them (the rows are soft-deleted, not removed).
BEGIN;

UPDATE event_types SET deleted_at = now()
WHERE organization_id IS NULL AND name IN ('Reunion', 'Seminar') AND deleted_at IS NULL;

COMMIT;
