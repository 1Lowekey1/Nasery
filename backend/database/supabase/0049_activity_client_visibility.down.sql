-- Rollback of 0049_activity_client_visibility.sql (activities become internal-only again).
BEGIN;
ALTER TABLE event_activities DROP COLUMN IF EXISTS visible_to_client;
COMMIT;
