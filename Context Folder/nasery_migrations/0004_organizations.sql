-- =====================================================================
-- 0004_organizations.sql
-- Purpose: tenant root, membership, and the ownership-guarantee mechanism.
-- Dependencies: 0001-0003
-- Contains: organizations, organization_members (with generated-column
--           ownership marker + deferrable UNIQUE constraint), ownership_transfers
-- =====================================================================

CREATE TABLE organizations (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  legal_name       text NOT NULL,
  display_name     text NOT NULL,
  slug             text NOT NULL,
  approval_status  approval_status NOT NULL DEFAULT 'pending',
  is_suspended     boolean NOT NULL DEFAULT false,
  suspended_reason text,
  suspended_at     timestamptz,
  suspended_by     uuid REFERENCES users(id),
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_suspension_fields CHECK (
    (is_suspended = false) OR (suspended_reason IS NOT NULL AND suspended_by IS NOT NULL AND suspended_at IS NOT NULL)
  )
);

CREATE UNIQUE INDEX uq_organizations_slug ON organizations(slug) WHERE deleted_at IS NULL;
CREATE INDEX idx_organizations_approval_status ON organizations(approval_status);
CREATE INDEX idx_organizations_is_suspended    ON organizations(is_suspended);

-- Supports composite FKs from event_members -> organizations(id, organization_id)-shaped targets
-- (used indirectly: event_members references organization_members(id, organization_id) and
--  events(id, organization_id); this table's own (id) PK is sufficient for direct FKs to it).

-- ---------------------------------------------------------------------
-- organization_members
-- ---------------------------------------------------------------------
CREATE TABLE organization_members (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id       uuid NOT NULL REFERENCES organizations(id),
  user_id               uuid NOT NULL REFERENCES users(id),
  organization_role_id  uuid,                       -- FK added in 0005 after organization_roles exists
  is_owner              boolean NOT NULL DEFAULT false,
  is_primary_owner      boolean NOT NULL DEFAULT false,
  status                membership_status NOT NULL DEFAULT 'active',
  invited_by            uuid REFERENCES users(id),
  joined_at             timestamptz NOT NULL DEFAULT now(),
  deactivated_at        timestamptz,
  deleted_at            timestamptz,
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),

  -- Generated marker: non-null ONLY for a row that is the org's active primary owner.
  -- This is what makes a genuine, whole-table, DEFERRABLE unique constraint possible --
  -- a partial (WHERE-clause) unique INDEX cannot be made deferrable in PostgreSQL, so
  -- the "partial" condition is baked into this generated column instead, and the
  -- uniqueness constraint below applies to the whole table with no WHERE clause.
  primary_owner_marker  uuid GENERATED ALWAYS AS (
    CASE WHEN is_primary_owner AND status = 'active' AND deleted_at IS NULL
         THEN organization_id END
  ) STORED
);

-- "At most one active primary owner per organization" -- checked at COMMIT, not per statement,
-- so an ownership-transfer transaction may transiently hold two qualifying rows mid-transaction.
ALTER TABLE organization_members
  ADD CONSTRAINT uq_org_members_one_primary_owner
  UNIQUE (primary_owner_marker) DEFERRABLE INITIALLY DEFERRED;

-- One active membership per (org, user).
CREATE UNIQUE INDEX uq_org_members_org_user_active
  ON organization_members(organization_id, user_id) WHERE deleted_at IS NULL;

-- Supports composite FKs from event_members.
ALTER TABLE organization_members
  ADD CONSTRAINT uq_org_members_id_org UNIQUE (id, organization_id);

CREATE INDEX idx_org_members_org_status ON organization_members(organization_id, status);
CREATE INDEX idx_org_members_user       ON organization_members(user_id);

COMMENT ON COLUMN organization_members.primary_owner_marker IS
  'Generated. NULL unless this row is the organization''s active primary owner. Backs the deferrable one-owner-per-org constraint.';

-- ---------------------------------------------------------------------
-- Guarantee "at least one" active primary owner (the deferred UNIQUE
-- constraint above only guarantees "at most one"). Fires on UPDATE/DELETE
-- of a row that currently IS the active primary owner.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION trg_org_members_protect_sole_owner() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  other_owner_exists boolean;
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.is_primary_owner AND OLD.status = 'active' AND OLD.deleted_at IS NULL THEN
      SELECT EXISTS (
        SELECT 1 FROM organization_members
        WHERE organization_id = OLD.organization_id
          AND id <> OLD.id
          AND is_primary_owner AND status = 'active' AND deleted_at IS NULL
      ) INTO other_owner_exists;
      IF NOT other_owner_exists THEN
        RAISE EXCEPTION 'Cannot remove the sole primary owner of organization %; transfer ownership first', OLD.organization_id;
      END IF;
    END IF;
    RETURN OLD;
  END IF;

  IF OLD.is_primary_owner AND OLD.status = 'active' AND OLD.deleted_at IS NULL
     AND NOT (NEW.is_primary_owner AND NEW.status = 'active' AND NEW.deleted_at IS NULL) THEN
    SELECT EXISTS (
      SELECT 1 FROM organization_members
      WHERE organization_id = OLD.organization_id
        AND id <> OLD.id
        AND is_primary_owner AND status = 'active' AND deleted_at IS NULL
    ) INTO other_owner_exists;
    IF NOT other_owner_exists THEN
      RAISE EXCEPTION 'Cannot deactivate/demote the sole primary owner of organization %; transfer ownership first', OLD.organization_id;
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_protect_sole_owner
BEFORE UPDATE OR DELETE ON organization_members
FOR EACH ROW EXECUTE FUNCTION trg_org_members_protect_sole_owner();

-- ---------------------------------------------------------------------
-- Enforce that only account_type = 'business' users can be members.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION trg_org_members_require_business_account() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND account_type = 'business') THEN
    RAISE EXCEPTION 'User % is not a business-type account and cannot be an organization member', NEW.user_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_require_business_account
BEFORE INSERT ON organization_members
FOR EACH ROW EXECUTE FUNCTION trg_org_members_require_business_account();

-- ---------------------------------------------------------------------
-- ownership_transfers -- append-only audit trail
-- ---------------------------------------------------------------------
CREATE TABLE ownership_transfers (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  from_member_id  uuid REFERENCES organization_members(id),   -- nullable: initial owner assignment
  to_member_id    uuid NOT NULL REFERENCES organization_members(id),
  initiated_by    uuid NOT NULL REFERENCES users(id),
  reason          text NOT NULL,
  transferred_at  timestamptz NOT NULL DEFAULT now(),
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_ownership_transfers_org ON ownership_transfers(organization_id, transferred_at);

-- NOTE: transfer_organization_ownership() -- the ONLY sanctioned code path for
-- changing is_primary_owner -- is defined in 0021_functions_triggers.sql, because
-- it writes to audit_logs (created in 0019) and must not forward-reference a
-- table that doesn't exist yet at this point in the migration order.
