-- =====================================================================
-- 0049_activity_client_visibility.sql
-- NaSeRy
--
-- Apply AFTER 0048.
--
-- Purpose (2026-10-16, user): an organizer can show chosen program items
-- (event_activities, 0045) to the client, e.g. the day's schedule. Off by
-- default, so existing activities stay internal.
--
--   event_activities.visible_to_client   boolean, default false
--
-- Clients still can't read event_activities under RLS: Laravel's logged
-- system context returns the visible rows for the client's own event,
-- without the person in charge or internal tags.
--
-- Rollback: 0049_activity_client_visibility.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE event_activities
  ADD COLUMN visible_to_client boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN event_activities.visible_to_client IS
  'Shown on the client''s Activity Timeline (title, description, time, status only).';

COMMIT;

-- =====================================================================
-- END 0049
-- =====================================================================
