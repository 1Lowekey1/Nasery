-- =====================================================================
-- 0042_inquiry_threads.sql
-- NaSeRy
--
-- Apply AFTER 0041.
--
-- Purpose (inquiry & bidding threads, 2026-09-26; paper: clients "may
-- communicate with organizers ... before selecting a winning bid"):
--   One private text thread per inquiry participant
--   (inquiry_organizations row) between the client and that organizer.
--     inquiry_messages      the messages (text only, 1-2000 chars, no edits)
--     inquiry_thread_reads  per-user "read up to" marker for unread badges
--
--   Rows are written only by Laravel's logged system context after its own
--   checks (the client of the inquiry; organizer members who handle
--   inquiries or quotations; sending is closed once the thread is over).
--   Reads under RLS:
--     - the inquiry's client
--     - members of the invited organization
--   NaSeRy admins have no read policy: threads are private.
--
-- Rollback: 0042_inquiry_threads.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE inquiry_messages (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_organization_id  uuid NOT NULL REFERENCES inquiry_organizations(id),
  sender_user_id           uuid NOT NULL REFERENCES users(id),
  sender_side              text NOT NULL CHECK (sender_side IN ('client', 'organizer')),
  body                     text NOT NULL CHECK (char_length(trim(body)) BETWEEN 1 AND 2000),
  created_at               timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_inquiry_messages_thread ON inquiry_messages (inquiry_organization_id, created_at);

CREATE TABLE inquiry_thread_reads (
  inquiry_organization_id  uuid NOT NULL REFERENCES inquiry_organizations(id),
  user_id                  uuid NOT NULL REFERENCES users(id),
  last_read_at             timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (inquiry_organization_id, user_id)
);

ALTER TABLE inquiry_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiry_thread_reads ENABLE ROW LEVEL SECURITY;

CREATE POLICY inquiry_messages_client_read ON inquiry_messages FOR SELECT
  USING (inquiry_organization_id IN (
    SELECT id FROM inquiry_organizations WHERE inquiry_id IN (SELECT user_client_inquiry_ids())));

CREATE POLICY inquiry_messages_org_read ON inquiry_messages FOR SELECT
  USING (inquiry_organization_id IN (
    SELECT id FROM inquiry_organizations WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY inquiry_thread_reads_own ON inquiry_thread_reads FOR SELECT
  USING (user_id = auth.uid());

GRANT SELECT ON inquiry_messages, inquiry_thread_reads TO nasery_app;

COMMENT ON TABLE inquiry_messages IS
  'Private client-organizer thread per inquiry participant. Written only by the Laravel system context.';

COMMIT;

-- =====================================================================
-- END 0042
-- =====================================================================
