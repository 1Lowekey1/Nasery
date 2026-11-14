-- Rollback of 0059_restore_other_event_type.sql: removes "Other" again.
BEGIN;

UPDATE event_types SET deleted_at = now(), updated_at = now()
WHERE organization_id IS NULL AND name = 'Other' AND deleted_at IS NULL;

COMMIT;
