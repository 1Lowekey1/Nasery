-- Rollback of 0034_event_core.sql (restores the 0027 grants and policies).
-- Drops the venue/visibility/contact columns: export them first if they hold real data.
BEGIN;

DROP POLICY IF EXISTS event_sessions_client_read ON event_sessions;
DROP POLICY IF EXISTS events_client_read ON events;

REVOKE INSERT, UPDATE ON events FROM nasery_app;
GRANT INSERT, UPDATE ON events TO nasery_app;

DROP INDEX IF EXISTS uq_events_inquiry_live;

ALTER TABLE events
  DROP CONSTRAINT IF EXISTS chk_events_visibility,
  DROP CONSTRAINT IF EXISTS chk_events_venue_len,
  DROP CONSTRAINT IF EXISTS chk_events_coordinates,
  DROP CONSTRAINT IF EXISTS chk_events_contact_len,
  DROP CONSTRAINT IF EXISTS chk_events_name_len,
  DROP COLUMN IF EXISTS venue_name,
  DROP COLUMN IF EXISTS venue_address,
  DROP COLUMN IF EXISTS latitude,
  DROP COLUMN IF EXISTS longitude,
  DROP COLUMN IF EXISTS visibility,
  DROP COLUMN IF EXISTS contact_name,
  DROP COLUMN IF EXISTS contact_email,
  DROP COLUMN IF EXISTS contact_phone;

COMMIT;
