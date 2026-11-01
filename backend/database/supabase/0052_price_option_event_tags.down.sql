-- Rollback of 0052_price_option_event_tags.sql: options are for every event type again.
BEGIN;

ALTER TABLE organization_price_options DROP COLUMN IF EXISTS event_tags;

COMMIT;
