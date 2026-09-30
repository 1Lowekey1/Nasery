-- Rollback of 0045_event_activities.sql (drops every written activity).
BEGIN;
DROP TABLE IF EXISTS event_activities;
COMMIT;
