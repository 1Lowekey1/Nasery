-- =====================================================================
-- 0034_event_core.sql
-- NaSeRy
--
-- Apply AFTER 0033.
--
-- Purpose (B6 Event core):
--   1. Event fields the paper and event form need: venue (name, address,
--      map pin), visibility (private guest-list event vs public event for
--      B9 ticketing; default private) and an event contact.
--   2. A client can read the event created from their accepted inquiry and
--      its sessions (previously only event_progress was client-readable).
--   3. At most one live event per inquiry.
--   4. Column grants: the app role cannot set or change an event's
--      inquiry_id / accepted_quotation_id (only Laravel's checked conversion
--      step links them, in its logged system context), nor move an event to
--      another organization or creator. New events always start as 'draft'.
--
-- Nothing is removed. Rollback: 0034_event_core.down.sql
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 1. Columns
-- ---------------------------------------------------------------------
ALTER TABLE events
  ADD COLUMN venue_name     text,
  ADD COLUMN venue_address  text,
  ADD COLUMN latitude       numeric(9,6),
  ADD COLUMN longitude      numeric(9,6),
  ADD COLUMN visibility     text NOT NULL DEFAULT 'private',
  ADD COLUMN contact_name   text,
  ADD COLUMN contact_email  text,
  ADD COLUMN contact_phone  text,
  ADD CONSTRAINT chk_events_visibility    CHECK (visibility IN ('private', 'public')),
  ADD CONSTRAINT chk_events_venue_len     CHECK ((venue_name IS NULL OR char_length(venue_name) <= 200)
                                                 AND (venue_address IS NULL OR char_length(venue_address) <= 500)),
  ADD CONSTRAINT chk_events_coordinates   CHECK ((latitude IS NULL) = (longitude IS NULL)
                                                 AND (latitude IS NULL OR latitude BETWEEN -90 AND 90)
                                                 AND (longitude IS NULL OR longitude BETWEEN -180 AND 180)),
  ADD CONSTRAINT chk_events_contact_len   CHECK ((contact_name IS NULL OR char_length(contact_name) <= 120)
                                                 AND (contact_email IS NULL OR char_length(contact_email) <= 254)
                                                 AND (contact_phone IS NULL OR char_length(contact_phone) <= 40)),
  ADD CONSTRAINT chk_events_name_len      CHECK (char_length(btrim(name)) BETWEEN 2 AND 200);

-- ---------------------------------------------------------------------
-- 3. One live event per inquiry
-- ---------------------------------------------------------------------
CREATE UNIQUE INDEX uq_events_inquiry_live
  ON events(inquiry_id) WHERE inquiry_id IS NOT NULL AND deleted_at IS NULL;

-- ---------------------------------------------------------------------
-- 4. Column grants
-- ---------------------------------------------------------------------
REVOKE INSERT, UPDATE ON events FROM nasery_app;
GRANT INSERT (id, organization_id, created_by_member_id, event_type_id, name, description, timezone,
              overall_capacity, venue_name, venue_address, latitude, longitude, visibility,
              contact_name, contact_email, contact_phone, created_at, updated_at)
  ON events TO nasery_app;
GRANT UPDATE (event_type_id, name, description, status, timezone, overall_capacity,
              cancelled_reason, cancelled_at, deleted_at,
              venue_name, venue_address, latitude, longitude, visibility,
              contact_name, contact_email, contact_phone, updated_at)
  ON events TO nasery_app;

-- ---------------------------------------------------------------------
-- 2. Client visibility (helper from 0031, SECURITY DEFINER: no recursion)
-- ---------------------------------------------------------------------
CREATE POLICY events_client_read ON events FOR SELECT
  USING (id IN (SELECT user_client_event_ids()));

CREATE POLICY event_sessions_client_read ON event_sessions FOR SELECT
  USING (event_id IN (SELECT user_client_event_ids()));

COMMIT;

-- =====================================================================
-- END 0034
-- =====================================================================
