-- =====================================================================
-- 0052_price_option_event_tags.sql
-- NaSeRy
--
-- Apply AFTER 0051.
--
-- Purpose (advisor review, 2026-11): an organizer's price options say which
-- kinds of events they are for, so "Build from my price list" on an offer
-- shows only the options that fit the client's event (birthday options for a
-- birthday, not wedding ones).
--
--   organization_price_options
--     event_tags   event type names the option is for (JSON array, like
--                  organization_profiles.event_tags); empty = every event type
--
-- Existing options get [] and so keep showing for every event. The table's
-- existing grants (0033) cover the new column; the public price-option view
-- is unchanged (the public calculator still shows every active option).
--
-- Rollback: 0052_price_option_event_tags.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE organization_price_options
  ADD COLUMN event_tags jsonb NOT NULL DEFAULT '[]'
    CHECK (jsonb_typeof(event_tags) = 'array' AND jsonb_array_length(event_tags) <= 30);

COMMIT;

-- =====================================================================
-- END 0052
-- =====================================================================
