-- =====================================================================
-- 0019_audit.sql
-- Purpose: immutable, platform-wide audit trail.
-- Dependencies: 0001-0018
-- =====================================================================

CREATE TABLE audit_logs (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_user_id   uuid REFERENCES users(id),          -- nullable: system-initiated actions
  organization_id uuid REFERENCES organizations(id),  -- nullable: platform-level actions
  action          text NOT NULL,
  entity_type     text NOT NULL,
  entity_id       uuid,
  old_values      jsonb,
  new_values      jsonb,
  reason          text,
  ip_address      inet,
  user_agent      text,
  request_id      text,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_logs_org_created  ON audit_logs(organization_id, created_at);
CREATE INDEX idx_audit_logs_entity       ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_actor        ON audit_logs(actor_user_id);

-- Immutability: no UPDATE/DELETE path is granted to any application role (enforced via
-- GRANT/REVOKE in 0022 -- only INSERT and SELECT are ever granted on this table).
COMMENT ON TABLE audit_logs IS
  'Append-only. No row is ever updated or deleted, including on soft-deletion or anonymization '
  'of the business records it references -- audit history must survive its subjects.';
