-- =====================================================================
-- 0054_event_type_suggestions.sql
-- NaSeRy
--
-- Apply AFTER 0053.
--
-- Purpose (user, 2026-11-01): organizers suggest new standard event types
-- (e.g. "Gender Reveal") instead of only picking from NaSeRy's list. Laravel
-- first catches exact matches, likely typos/duplicates and repeat
-- suggestions; what's left waits here for a platform admin, who approves it
-- (it becomes a standard event type), merges it into an existing type, or
-- rejects it.
--
--   event_type_suggestions   one organization's suggestion
--     name           as the organizer wrote it (cleaned up)
--     name_key       lower-case, single-spaced: groups the same suggestion
--                    from several organizers
--     status         pending | approved | merged | rejected
--     event_type_id  the standard type it became (approved) or was merged into
--
--   Writes happen only in Laravel's logged system context after its own
--   checks (organization manager to suggest, platform admin to decide).
--   Reads under RLS: platform admins and members of the organization.
--
-- Rollback: 0054_event_type_suggestions.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE event_type_suggestions (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  suggested_by     uuid NOT NULL REFERENCES users(id),
  name             text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 2 AND 80),
  name_key         text NOT NULL CHECK (char_length(name_key) BETWEEN 2 AND 80),
  status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'merged', 'rejected')),
  event_type_id    uuid REFERENCES event_types(id),
  decided_by       uuid REFERENCES users(id),
  decided_at       timestamptz,
  decision_note    text CHECK (decision_note IS NULL OR char_length(decision_note) <= 500),
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_suggestion_decided CHECK (status = 'pending' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
  CONSTRAINT chk_suggestion_type CHECK (status NOT IN ('approved', 'merged') OR event_type_id IS NOT NULL)
);

-- One open suggestion of the same name per organization.
CREATE UNIQUE INDEX uq_event_type_suggestions_pending ON event_type_suggestions (organization_id, name_key) WHERE status = 'pending';
CREATE INDEX idx_event_type_suggestions_status ON event_type_suggestions (status, name_key, created_at);
CREATE INDEX idx_event_type_suggestions_org ON event_type_suggestions (organization_id, created_at DESC);

ALTER TABLE event_type_suggestions ENABLE ROW LEVEL SECURITY;

CREATE POLICY event_type_suggestions_admin_read ON event_type_suggestions FOR SELECT
  USING (is_platform_admin());

CREATE POLICY event_type_suggestions_org_read ON event_type_suggestions FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON event_type_suggestions FROM PUBLIC, anon, authenticated;
GRANT SELECT ON event_type_suggestions TO nasery_app;

COMMENT ON TABLE event_type_suggestions IS
  'Organizer suggestions for new standard event types, decided by platform admins (approve / merge / reject).';

COMMIT;

-- =====================================================================
-- END 0054
-- =====================================================================
