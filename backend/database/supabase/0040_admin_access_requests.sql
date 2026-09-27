-- =====================================================================
-- 0040_admin_access_requests.sql
-- NaSeRy
--
-- Apply AFTER 0038. (0039 was not adopted; the number is left unused.)
--
-- Purpose (B17 Admin; project decision 2026-09-25):
--   0018 locked admin access as "single-admin self-service". The team
--   changed that: an admin may look inside an organization only with the
--   organization's CONSENT.
--     - An admin REQUESTS access with a reason; the owner approves or
--       refuses.
--     - Or the owner INVITES NaSeRy support (e.g. to debug an issue) and an
--       admin accepts.
--   Only an approved/accepted request writes the real admin_access_grants
--   row (granted_by = the owner who agreed, the column 0018 reserved for
--   dual control), so a pending request never opens anything:
--   admin_has_active_grant() keeps reading admin_access_grants only.
--
--   Writes happen only in Laravel's logged system context after its own
--   checks (owner for decisions, admin for requests). Reads under RLS:
--     - platform admins
--     - members of the organization
--
-- Rollback: 0040_admin_access_requests.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE admin_access_requests (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  initiated_by     text NOT NULL CHECK (initiated_by IN ('admin', 'organizer')),
  -- The admin who asked, or (for an organizer invite) the admin who accepted.
  admin_user_id    uuid REFERENCES users(id),
  requested_by     uuid NOT NULL REFERENCES users(id),
  reason           text NOT NULL CHECK (char_length(trim(reason)) >= 5 AND char_length(reason) <= 1000),
  duration_hours   integer NOT NULL DEFAULT 24 CHECK (duration_hours BETWEEN 1 AND 72),
  status           text NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending', 'approved', 'declined', 'cancelled', 'expired')),
  decided_by       uuid REFERENCES users(id),
  decided_at       timestamptz,
  decision_note    text CHECK (decision_note IS NULL OR char_length(decision_note) <= 1000),
  grant_id         uuid REFERENCES admin_access_grants(id),
  -- A request nobody answers lapses.
  expires_at       timestamptz NOT NULL DEFAULT (now() + interval '7 days'),
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_access_request_admin CHECK (initiated_by = 'organizer' OR admin_user_id IS NOT NULL),
  CONSTRAINT chk_access_request_approved CHECK (
    status <> 'approved' OR (grant_id IS NOT NULL AND admin_user_id IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL)
  )
);

-- One open request per admin per organization, and one open support invite per organization.
CREATE UNIQUE INDEX uq_access_requests_admin_pending ON admin_access_requests (organization_id, admin_user_id)
  WHERE status = 'pending' AND initiated_by = 'admin';
CREATE UNIQUE INDEX uq_access_requests_invite_pending ON admin_access_requests (organization_id)
  WHERE status = 'pending' AND initiated_by = 'organizer';
CREATE INDEX idx_access_requests_org ON admin_access_requests (organization_id, created_at DESC);
CREATE INDEX idx_access_requests_status ON admin_access_requests (status, created_at DESC);

ALTER TABLE admin_access_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY access_requests_admin_read ON admin_access_requests FOR SELECT
  USING (is_platform_admin());

CREATE POLICY access_requests_org_read ON admin_access_requests FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

GRANT SELECT ON admin_access_requests TO nasery_app;

COMMENT ON TABLE admin_access_requests IS
  'Consent workflow for admin access (B17). Only an approved request creates an admin_access_grants row.';

COMMIT;

-- =====================================================================
-- END 0040
-- =====================================================================
