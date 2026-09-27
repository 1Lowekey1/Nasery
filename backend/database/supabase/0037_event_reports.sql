-- =====================================================================
-- 0037_event_reports.sql
-- NaSeRy
--
-- Apply AFTER 0036.
--
-- Purpose (B14 Reporting; paper: "Organizers review and approve the
-- reports before sending them to clients"):
--   The post-event attendance report is computed live for the organizer.
--   When they share it with the client, the exact figures are frozen here
--   as a numbered version, so the client always sees what was approved
--   even if check-ins are corrected later. Sharing again adds a version.
--
--   Rows are immutable (no UPDATE/DELETE grant); they are written only by
--   Laravel's logged system context. Reads under RLS:
--     - members of the event's organization
--     - the client whose inquiry became this event
--
-- Rollback: 0037_event_reports.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE event_reports (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          uuid NOT NULL,
  organization_id   uuid NOT NULL,
  version           integer NOT NULL CHECK (version >= 1),
  snapshot          jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
  note              text CHECK (note IS NULL OR char_length(note) <= 2000),
  includes_revenue  boolean NOT NULL DEFAULT false,
  shared_by         uuid NOT NULL REFERENCES users(id),
  shared_at         timestamptz NOT NULL DEFAULT now(),
  created_at        timestamptz NOT NULL DEFAULT now(),

  -- The organization must be the event's own organization.
  CONSTRAINT fk_event_reports_event_org FOREIGN KEY (event_id, organization_id) REFERENCES events (id, organization_id),
  CONSTRAINT uq_event_reports_version UNIQUE (event_id, version)
);

CREATE INDEX idx_event_reports_org ON event_reports (organization_id, shared_at DESC);

ALTER TABLE event_reports ENABLE ROW LEVEL SECURITY;

CREATE POLICY event_reports_org_read ON event_reports FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

CREATE POLICY event_reports_client_read ON event_reports FOR SELECT
  USING (event_id IN (SELECT user_client_event_ids()));

GRANT SELECT ON event_reports TO nasery_app;

COMMENT ON TABLE event_reports IS
  'Post-event reports shared with the client (B14). Immutable versions; the live report is computed, not stored.';

COMMIT;

-- =====================================================================
-- END 0037
-- =====================================================================
