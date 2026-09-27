-- =====================================================================
-- 0003_users.sql
-- Purpose: identity table. Single users table for all actor types.
-- Dependencies: 0001, 0002
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE users (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email             citext NOT NULL,
  password_hash     text,                      -- nullable: Supabase Auth may own credential storage instead
  first_name        text NOT NULL,
  middle_name       text,
  last_name         text NOT NULL,
  phone             text,
  account_type      account_type NOT NULL,
  status            text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','deactivated')),
  email_verified_at timestamptz,
  deleted_at        timestamptz,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);

-- citext (enabled above) gives case-insensitive email comparison.
CREATE UNIQUE INDEX uq_users_email_active ON users(email) WHERE deleted_at IS NULL;
CREATE INDEX idx_users_status       ON users(status);
CREATE INDEX idx_users_account_type ON users(account_type);

COMMENT ON COLUMN users.account_type IS
  'Platform-level account category only. Does NOT determine organization role/permissions -- see organization_members / role_permissions / member_permission_overrides for that.';
