-- =====================================================================
-- 0045_event_activities.sql
-- NaSeRy
--
-- Apply AFTER 0044.
--
-- Purpose (activity timeline, 2026-09-30; the redesigned Activity Timeline
-- page): organizers write the event's program / run-of-show as activities
-- (title, category, tag, person in charge, time, status). The page shows
-- them together with NaSeRy's automatic event history (0010 timeline).
--
--   event_activities   one activity; soft-deleted
--
--   Rows are written only by Laravel's logged system context after its own
--   permission checks. Reads under RLS: members of the organization.
--
-- Rollback: 0045_event_activities.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE event_activities (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id         uuid NOT NULL REFERENCES events(id),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  title            text NOT NULL CHECK (char_length(trim(title)) BETWEEN 1 AND 200),
  description      text CHECK (description IS NULL OR char_length(description) <= 2000),
  category         text CHECK (category IS NULL OR char_length(category) <= 60),
  tag              text CHECK (tag IS NULL OR char_length(tag) <= 60),
  person           text CHECK (person IS NULL OR char_length(person) <= 120),
  status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'in_progress', 'completed')),
  activity_at      timestamptz,
  created_by       uuid NOT NULL REFERENCES users(id),
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_event_activities_event ON event_activities (event_id, activity_at) WHERE deleted_at IS NULL;
CREATE INDEX idx_event_activities_org ON event_activities (organization_id) WHERE deleted_at IS NULL;

ALTER TABLE event_activities ENABLE ROW LEVEL SECURITY;

CREATE POLICY event_activities_member_read ON event_activities FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

GRANT SELECT ON event_activities TO nasery_app;

COMMENT ON TABLE event_activities IS
  'Organizer-written event program items. Written only by the Laravel system context.';

COMMIT;

-- =====================================================================
-- END 0045
-- =====================================================================
