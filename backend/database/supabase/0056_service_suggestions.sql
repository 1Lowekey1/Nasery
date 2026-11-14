-- =====================================================================
-- 0056_service_suggestions.sql
-- NaSeRy
--
-- Apply AFTER 0055.
--
-- Purpose (user, 2026-11-02): the same as event types (0054) for the service
-- catalog. An organizer adds a service that isn't in NaSeRy's catalog once:
-- it is their own (custom) service right away, and it waits here for a
-- platform admin, who approves it (it becomes a catalog service), merges it
-- into an existing one, or rejects it (it stays the organizer's own).
-- Laravel first catches exact matches, likely typos/duplicates and repeat
-- suggestions.
--
--   service_suggestions   one organization's suggestion
--     name        as the organizer wrote it (cleaned up)
--     name_key    lower-case, single-spaced: groups the same suggestion
--                 from several organizers
--     status      pending | approved | merged | rejected
--     service_id  the catalog service it became (approved) or was merged into
--
--   Writes happen only in Laravel's logged system context after its own
--   checks (organization manager to suggest, platform admin to decide).
--   Reads under RLS: platform admins and members of the organization.
--
-- Rollback: 0056_service_suggestions.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE service_suggestions (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  suggested_by     uuid NOT NULL REFERENCES users(id),
  name             text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 2 AND 80),
  name_key         text NOT NULL CHECK (char_length(name_key) BETWEEN 2 AND 80),
  status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'merged', 'rejected')),
  service_id       uuid REFERENCES services(id),
  decided_by       uuid REFERENCES users(id),
  decided_at       timestamptz,
  decision_note    text CHECK (decision_note IS NULL OR char_length(decision_note) <= 500),
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_service_suggestion_decided CHECK (status = 'pending' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
  CONSTRAINT chk_service_suggestion_service CHECK (status NOT IN ('approved', 'merged') OR service_id IS NOT NULL)
);

-- One open suggestion of the same name per organization.
CREATE UNIQUE INDEX uq_service_suggestions_pending ON service_suggestions (organization_id, name_key) WHERE status = 'pending';
CREATE INDEX idx_service_suggestions_status ON service_suggestions (status, name_key, created_at);
CREATE INDEX idx_service_suggestions_org ON service_suggestions (organization_id, created_at DESC);

ALTER TABLE service_suggestions ENABLE ROW LEVEL SECURITY;

CREATE POLICY service_suggestions_admin_read ON service_suggestions FOR SELECT
  USING (is_platform_admin());

CREATE POLICY service_suggestions_org_read ON service_suggestions FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON service_suggestions FROM PUBLIC, anon, authenticated;
GRANT SELECT ON service_suggestions TO nasery_app;

COMMENT ON TABLE service_suggestions IS
  'Organizer suggestions for new catalog services, decided by platform admins (approve / merge / reject).';

COMMIT;

-- =====================================================================
-- END 0056
-- =====================================================================
