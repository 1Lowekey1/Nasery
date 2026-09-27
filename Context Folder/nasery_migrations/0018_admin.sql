-- =====================================================================
-- 0018_admin.sql
-- Purpose: organizer approval history, moderation, platform settings,
--          admin access grants (V1 policy: single-admin self-service,
--          mandatory reason, 24h default expiry, fully audited).
-- Dependencies: 0001-0017
-- =====================================================================

CREATE TABLE organizer_approval_requests (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  status          approval_status NOT NULL DEFAULT 'pending',
  reviewed_by     uuid REFERENCES users(id),
  decision_reason text,
  submitted_at    timestamptz NOT NULL DEFAULT now(),
  decided_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_approval_requests_org_status ON organizer_approval_requests(organization_id, status);

COMMENT ON TABLE organizer_approval_requests IS
  'Historical/workflow LOG only. organizations.approval_status is the sole authoritative current state -- '
  'both must be written together by the same domain action (see set_organizer_approval_status() in 0021).';

CREATE TABLE moderation_reports (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  reportable_type text NOT NULL,     -- deliberate, scoped polymorphism exception (low-volume, genuinely cross-entity)
  reportable_id   uuid NOT NULL,
  reported_by     uuid NOT NULL REFERENCES users(id),
  reason          text NOT NULL,
  status          moderation_report_status NOT NULL DEFAULT 'open',
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_moderation_reports_target ON moderation_reports(reportable_type, reportable_id);

CREATE TABLE moderation_cases (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subject_type text NOT NULL,
  subject_id   uuid NOT NULL,
  opened_by    uuid NOT NULL REFERENCES users(id),
  status       moderation_report_status NOT NULL DEFAULT 'open',
  resolution   text,
  action_taken text,
  resolved_at  timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE platform_settings (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key         text NOT NULL,
  value       jsonb NOT NULL,
  description text,
  updated_by  uuid REFERENCES users(id),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_platform_settings_key ON platform_settings(key);

CREATE TABLE platform_announcements (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title      text NOT NULL,
  body       text NOT NULL,
  audience   announcement_audience NOT NULL DEFAULT 'all',
  starts_at  timestamptz NOT NULL DEFAULT now(),
  ends_at    timestamptz,
  created_by uuid REFERENCES users(id),
  deleted_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- admin_access_grants -- V1 policy locked: single-admin self-service,
-- mandatory reason, default 24h expiry, fully audited, no dual-control.
-- ---------------------------------------------------------------------
CREATE TABLE admin_access_grants (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  admin_user_id   uuid NOT NULL REFERENCES users(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  reason          text NOT NULL CHECK (length(trim(reason)) > 0),
  granted_by      uuid REFERENCES users(id),   -- nullable: self-grant in V1; reserved for future dual-control
  starts_at       timestamptz NOT NULL DEFAULT now(),
  expires_at      timestamptz NOT NULL DEFAULT (now() + interval '24 hours'),
  revoked_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_admin_grants_lookup ON admin_access_grants(admin_user_id, organization_id, expires_at);

CREATE OR REPLACE FUNCTION trg_admin_access_grants_require_admin() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.admin_user_id AND account_type = 'admin') THEN
    RAISE EXCEPTION 'User % is not an admin-type account and cannot hold an access grant', NEW.admin_user_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_require_admin_account
BEFORE INSERT ON admin_access_grants
FOR EACH ROW EXECUTE FUNCTION trg_admin_access_grants_require_admin();
