-- Rollback of 0054_event_type_suggestions.sql. Event types already approved
-- from suggestions stay (they are ordinary standard event types).
BEGIN;

DROP TABLE IF EXISTS event_type_suggestions;

COMMIT;
