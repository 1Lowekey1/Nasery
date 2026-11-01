-- =====================================================================
-- 0053_more_event_types.sql
-- NaSeRy
--
-- Apply AFTER 0052.
--
-- Purpose (user, 2026-11-01): "Reunion" and "Seminar" are offered on Post an
-- Event but weren't NaSeRy event types, so those requests were saved as
-- "Other" and, with event-type matching, reached no organizer. They become
-- standard event types organizers can tag on their profile.
--
-- Rollback: 0053_more_event_types.down.sql
-- =====================================================================

BEGIN;

INSERT INTO event_types (organization_id, name, is_system_default) VALUES
  (NULL, 'Reunion', true),
  (NULL, 'Seminar', true)
ON CONFLICT DO NOTHING;

COMMIT;

-- =====================================================================
-- END 0053
-- =====================================================================
