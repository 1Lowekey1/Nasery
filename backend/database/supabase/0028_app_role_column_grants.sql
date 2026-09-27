-- =====================================================================
-- 0028_app_role_column_grants.sql
-- NaSeRy
--
-- Apply AFTER 0027.
--
-- Purpose:
--   RLS decides WHICH ROWS nasery_app may touch, not which COLUMNS. With the
--   table-wide UPDATE grants from 0027 a member could, at the database
--   level, approve or un-suspend their own organization, and a user could
--   change their own account_type or status. Laravel never does this; this
--   migration makes the backstop hold even if Laravel had a bug.
--
-- Rule: authority/status columns change only through the sanctioned
-- SECURITY DEFINER functions (0021: set_organizer_approval_status,
-- transfer_organization_ownership) or Laravel's explicit, logged system
-- context after its own authorization checks.
--
-- This migration does NOT alter any table, column, constraint, policy,
-- function or row. Rollback: 0028_app_role_column_grants.down.sql
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- users: a user may edit their own names, phone and password only.
-- Not: email, account_type, status, email_verified_at, deleted_at.
-- ---------------------------------------------------------------------
REVOKE UPDATE ON users FROM nasery_app;
GRANT UPDATE (first_name, middle_name, last_name, phone, password_hash, updated_at)
  ON users TO nasery_app;

-- ---------------------------------------------------------------------
-- organizations: members may edit names and slug only.
-- Not: approval_status, is_suspended, suspended_*, deleted_at.
-- ---------------------------------------------------------------------
REVOKE UPDATE ON organizations FROM nasery_app;
GRANT UPDATE (legal_name, display_name, slug, updated_at)
  ON organizations TO nasery_app;

-- ---------------------------------------------------------------------
-- organization_members: role/status/removal may be managed (Laravel
-- checks staff.manage / ownership first). Ownership flags, the member's
-- user and organization can never be set through ordinary writes.
-- ---------------------------------------------------------------------
REVOKE INSERT, UPDATE ON organization_members FROM nasery_app;
GRANT INSERT (id, organization_id, user_id, organization_role_id, status, invited_by, joined_at, created_at, updated_at)
  ON organization_members TO nasery_app;
GRANT UPDATE (organization_role_id, status, deactivated_at, deleted_at, updated_at)
  ON organization_members TO nasery_app;

COMMIT;

-- =====================================================================
-- END 0028
-- =====================================================================
