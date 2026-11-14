-- =====================================================================
-- 0059_restore_other_event_type.sql
-- NaSeRy
--
-- Apply AFTER 0058.
--
-- Purpose (user, 2026-11): bring back the standard "Other" event type, which
-- was removed on the admin page on 2026-11-03, for events that fit no other
-- type. Laravel now treats it as "any event type": it skips the organizer's
-- event tags and matches on services and distance alone.
--
-- Rollback: 0059_restore_other_event_type.down.sql
-- =====================================================================

BEGIN;

UPDATE event_types SET deleted_at = NULL, updated_at = now()
WHERE organization_id IS NULL AND name = 'Other' AND deleted_at IS NOT NULL;

COMMIT;

-- =====================================================================
-- END 0059
-- =====================================================================
