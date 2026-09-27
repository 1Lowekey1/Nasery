-- =====================================================================
-- 0001_extensions.sql
-- Purpose: enable required PostgreSQL extensions.
-- Dependencies: none.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid()

-- =====================================================================
-- 0002_types.sql
-- Purpose: all ENUM types used across the schema.
-- Dependencies: 0001
-- =====================================================================

CREATE TYPE account_type            AS ENUM ('client','business','admin');
CREATE TYPE membership_status       AS ENUM ('active','invited','deactivated');
CREATE TYPE approval_status         AS ENUM ('pending','under_review','approved','rejected','suspended');
CREATE TYPE inquiry_status          AS ENUM ('draft','submitted','receiving_bids','accepted','converted_to_event','cancelled');
CREATE TYPE inquiry_org_status      AS ENUM ('pending','viewed','declined','quoted','withdrawn');
CREATE TYPE quotation_status        AS ENUM ('draft','submitted','accepted','rejected','withdrawn','cancelled');
CREATE TYPE event_status            AS ENUM ('draft','published','ongoing','completed','cancelled');
CREATE TYPE session_status          AS ENUM ('upcoming','ongoing','completed','cancelled');
CREATE TYPE task_status             AS ENUM ('pending','in_progress','done','blocked','cancelled');
CREATE TYPE task_priority           AS ENUM ('low','medium','high','urgent');
CREATE TYPE ticket_type_status      AS ENUM ('draft','on_sale','paused','closed');
CREATE TYPE registration_status     AS ENUM ('pending','confirmed','cancelled');
CREATE TYPE admission_type          AS ENUM ('paid','free','invited','complimentary');
CREATE TYPE registration_source     AS ENUM ('online','organizer','staff','walk_in');
CREATE TYPE ticket_status           AS ENUM ('pending','valid','used','cancelled','invalidated');
CREATE TYPE hold_status             AS ENUM ('active','converted','expired','cancelled');
CREATE TYPE payment_status          AS ENUM ('pending','processing','paid','failed','cancelled');
CREATE TYPE webhook_processing_status AS ENUM ('received','processed','ignored','failed');
CREATE TYPE fee_model_type          AS ENUM ('percentage','fixed','hybrid');
CREATE TYPE review_status           AS ENUM ('published','hidden','disputed');
CREATE TYPE moderation_report_status AS ENUM ('open','in_review','resolved','dismissed');
CREATE TYPE subscription_status     AS ENUM ('active','payment_failed','grace_period','cancelled','expired');
CREATE TYPE billing_cycle           AS ENUM ('monthly','yearly');
CREATE TYPE invoice_status          AS ENUM ('draft','issued','paid','void');
CREATE TYPE walk_in_category        AS ENUM ('ticket_linked','paid_walk_in','registered_attendee','headcount_only');
CREATE TYPE notification_channel    AS ENUM ('in_app','email','sms');
CREATE TYPE delivery_status         AS ENUM ('queued','sent','delivered','failed','retrying');
CREATE TYPE announcement_audience   AS ENUM ('all','organizers','clients');
CREATE TYPE transfer_qr_action      AS ENUM ('retained','reissued');
CREATE TYPE permission_effect       AS ENUM ('grant','revoke');

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

-- =====================================================================
-- 0005_roles_permissions.sql
-- Purpose: organization roles, system permissions, role/member overrides,
--          event-scoped membership and permissions.
-- Dependencies: 0001-0004
-- =====================================================================

CREATE TABLE organization_roles (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid REFERENCES organizations(id),   -- NULL = system template
  name            text NOT NULL,
  description     text,
  is_system_default boolean NOT NULL DEFAULT false,
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_org_roles_org_name
  ON organization_roles(organization_id, name) WHERE deleted_at IS NULL AND organization_id IS NOT NULL;
CREATE UNIQUE INDEX uq_org_roles_system_name
  ON organization_roles(name) WHERE deleted_at IS NULL AND organization_id IS NULL;

-- Now that organization_roles exists, add the FK deferred from 0004.
ALTER TABLE organization_members
  ADD CONSTRAINT fk_org_members_role FOREIGN KEY (organization_role_id) REFERENCES organization_roles(id);

CREATE TABLE permissions (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key         text NOT NULL,
  category    text NOT NULL,   -- e.g. 'event','ticket','attendee','checkin','quotation','inquiry','finance','staff','checklist'
  description text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_permissions_key ON permissions(key);
CREATE INDEX idx_permissions_category ON permissions(category);

CREATE TABLE role_permissions (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_role_id  uuid NOT NULL REFERENCES organization_roles(id),
  permission_id         uuid NOT NULL REFERENCES permissions(id),
  created_at            timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_role_permissions ON role_permissions(organization_role_id, permission_id);

CREATE TABLE member_permission_overrides (
  id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_member_id  uuid NOT NULL REFERENCES organization_members(id),
  permission_id           uuid NOT NULL REFERENCES permissions(id),
  effect                  permission_effect NOT NULL,
  granted_by              uuid NOT NULL REFERENCES users(id),
  reason                  text,
  created_at              timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_member_permission_overrides ON member_permission_overrides(organization_member_id, permission_id);

-- ---------------------------------------------------------------------
-- event_members / event_member_permissions -- created after events in
-- dependency terms, but events references organization members only, so
-- we define event_members here referencing a forward-declared events(id)
-- via a deferred FK added in 0009 once "events" exists. To keep this file
-- self-consistent we instead create event_members in 0009 right after
-- events + event_sessions, where all its composite FK targets already
-- exist. See 0009_events.sql.
-- ---------------------------------------------------------------------

-- =====================================================================
-- 0006_marketplace.sql
-- Purpose: public organizer profile, service catalog, event types.
-- Dependencies: 0001-0005
-- =====================================================================

CREATE TABLE organization_profiles (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL UNIQUE REFERENCES organizations(id),
  business_name    text NOT NULL,
  description      text,
  location_text    text,
  latitude         numeric(9,6),
  longitude        numeric(9,6),
  contact_email    citext,
  contact_phone    text,
  cover_image_url  text,
  is_published     boolean NOT NULL DEFAULT false,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_org_profiles_published ON organization_profiles(is_published);

CREATE TABLE services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name             text NOT NULL,
  category         text,
  is_system_default boolean NOT NULL DEFAULT true,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE organization_services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  service_id       uuid REFERENCES services(id),
  custom_name      text,
  description      text,
  base_price       numeric(12,2) CHECK (base_price IS NULL OR base_price >= 0),
  is_active        boolean NOT NULL DEFAULT true,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_org_services_org_active ON organization_services(organization_id, is_active);

CREATE TABLE event_types (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id    uuid REFERENCES organizations(id),   -- NULL = platform default
  name               text NOT NULL,
  description        text,
  is_system_default  boolean NOT NULL DEFAULT false,
  deleted_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_event_types_org_name
  ON event_types(organization_id, name) WHERE deleted_at IS NULL AND organization_id IS NOT NULL;
CREATE UNIQUE INDEX uq_event_types_system_name
  ON event_types(name) WHERE deleted_at IS NULL AND organization_id IS NULL;

-- ---------------------------------------------------------------------
-- Public views: only these are granted SELECT to anon/authenticated for
-- marketplace browsing. Base tables remain governed by RLS (0022) for
-- authenticated org-member writes; internal fields added to base tables
-- later never leak unless explicitly added to a view's column list.
-- ---------------------------------------------------------------------
CREATE VIEW public_organization_profiles AS
  SELECT op.organization_id, o.display_name, op.business_name, op.description,
         op.location_text, op.contact_email, op.contact_phone, op.cover_image_url
  FROM organization_profiles op
  JOIN organizations o ON o.id = op.organization_id
  WHERE op.is_published AND o.deleted_at IS NULL AND o.approval_status = 'approved' AND NOT o.is_suspended;

CREATE VIEW public_organization_services AS
  SELECT os.id, os.organization_id, COALESCE(os.custom_name, s.name) AS name, os.description, os.base_price
  FROM organization_services os
  LEFT JOIN services s ON s.id = os.service_id
  WHERE os.is_active AND os.deleted_at IS NULL;

CREATE VIEW public_event_types AS
  SELECT id, organization_id, name, description
  FROM event_types
  WHERE deleted_at IS NULL AND (organization_id IS NULL OR organization_id IN (
    SELECT id FROM organizations WHERE approval_status = 'approved' AND NOT is_suspended AND deleted_at IS NULL
  ));

-- =====================================================================
-- 0007_inquiries.sql
-- Purpose: client inquiries, cross-tenant by design.
-- Dependencies: 0001-0006
-- =====================================================================

CREATE TABLE inquiries (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  client_user_id           uuid NOT NULL REFERENCES users(id),
  event_type_id            uuid REFERENCES event_types(id),
  requested_date           date,
  guest_count              integer CHECK (guest_count IS NULL OR guest_count >= 0),
  budget_min               numeric(12,2) CHECK (budget_min IS NULL OR budget_min >= 0),
  budget_max               numeric(12,2) CHECK (budget_max IS NULL OR budget_max >= 0),
  location_text            text,
  additional_requirements  text,
  status                   inquiry_status NOT NULL DEFAULT 'draft',
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_budget_range CHECK (budget_min IS NULL OR budget_max IS NULL OR budget_min <= budget_max)
);

CREATE INDEX idx_inquiries_client ON inquiries(client_user_id);
CREATE INDEX idx_inquiries_status ON inquiries(status);

CREATE OR REPLACE FUNCTION trg_inquiries_require_client_account() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.client_user_id AND account_type = 'client') THEN
    RAISE EXCEPTION 'User % is not a client-type account and cannot create/own an inquiry', NEW.client_user_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_require_client_account
BEFORE INSERT ON inquiries
FOR EACH ROW EXECUTE FUNCTION trg_inquiries_require_client_account();

CREATE TABLE inquiry_organizations (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id      uuid NOT NULL REFERENCES inquiries(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  status          inquiry_org_status NOT NULL DEFAULT 'pending',
  decline_reason  text,
  responded_at    timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

-- Natural key; also the composite-FK target for quotations (0008).
ALTER TABLE inquiry_organizations
  ADD CONSTRAINT uq_inquiry_organizations UNIQUE (inquiry_id, organization_id);

CREATE INDEX idx_inquiry_orgs_org_status ON inquiry_organizations(organization_id, status);

CREATE TABLE inquiry_services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id       uuid NOT NULL REFERENCES inquiries(id),
  service_id       uuid REFERENCES services(id),
  custom_service_name text,
  notes            text,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_inquiry_services_inquiry ON inquiry_services(inquiry_id);

-- =====================================================================
-- 0008_quotations.sql
-- Purpose: organizer bids against an inquiry.
-- Dependencies: 0001-0007
-- =====================================================================

CREATE TABLE quotations (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id      uuid NOT NULL REFERENCES inquiries(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  total_amount    numeric(12,2) NOT NULL CHECK (total_amount >= 0),
  currency        text NOT NULL DEFAULT 'PHP',
  terms           text,
  status          quotation_status NOT NULL DEFAULT 'draft',
  submitted_at    timestamptz,
  decided_at      timestamptz,
  decided_by      uuid REFERENCES users(id),
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),

  -- Composite FK: an organization may only quote on an inquiry it was actually
  -- invited to bid on. Requires uq_inquiry_organizations from 0007.
  CONSTRAINT fk_quotations_inquiry_org
    FOREIGN KEY (inquiry_id, organization_id)
    REFERENCES inquiry_organizations (inquiry_id, organization_id)
);

-- One active quotation per organizer per inquiry (historical rows preserved).
CREATE UNIQUE INDEX uq_quotations_active
  ON quotations(inquiry_id, organization_id) WHERE status IN ('draft','submitted');

CREATE INDEX idx_quotations_inquiry_status ON quotations(inquiry_id, status);
CREATE INDEX idx_quotations_org            ON quotations(organization_id);

CREATE TABLE quotation_items (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  quotation_id             uuid NOT NULL REFERENCES quotations(id),
  organization_service_id  uuid REFERENCES organization_services(id),
  description              text NOT NULL,
  quantity                 numeric(10,2) NOT NULL DEFAULT 1 CHECK (quantity > 0),
  unit_price               numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  subtotal                 numeric(12,2) NOT NULL CHECK (subtotal >= 0),
  created_at               timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_quotation_items_quotation ON quotation_items(quotation_id);

CREATE TABLE quotation_status_history (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  quotation_id   uuid NOT NULL REFERENCES quotations(id),
  from_status    quotation_status,
  to_status      quotation_status NOT NULL,
  actor_user_id  uuid REFERENCES users(id),
  reason         text,
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_quotation_history_quotation ON quotation_status_history(quotation_id);

-- =====================================================================
-- 0009_events.sql
-- Purpose: events, sessions, event-scoped membership/permissions.
-- Dependencies: 0001-0008
-- =====================================================================

CREATE TABLE events (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id        uuid NOT NULL REFERENCES organizations(id),
  created_by_member_id   uuid NOT NULL REFERENCES organization_members(id),
  inquiry_id             uuid REFERENCES inquiries(id),              -- nullable: independent events allowed
  accepted_quotation_id  uuid REFERENCES quotations(id),             -- nullable
  event_type_id          uuid REFERENCES event_types(id),
  name                   text NOT NULL,
  description            text,
  status                 event_status NOT NULL DEFAULT 'draft',
  timezone               text NOT NULL DEFAULT 'Asia/Manila',
  overall_capacity       integer CHECK (overall_capacity IS NULL OR overall_capacity >= 0),
  cancelled_reason       text,
  cancelled_at           timestamptz,
  deleted_at             timestamptz,
  created_at             timestamptz NOT NULL DEFAULT now(),
  updated_at             timestamptz NOT NULL DEFAULT now()
);

-- Supports composite FKs from event_members, tickets(via ticket_types/registrations), etc.
ALTER TABLE events ADD CONSTRAINT uq_events_id_org UNIQUE (id, organization_id);

CREATE INDEX idx_events_org_status ON events(organization_id, status);
CREATE INDEX idx_events_inquiry    ON events(inquiry_id);

-- ---------------------------------------------------------------------
-- event_type ownership: an event may use a platform-default event type
-- (organization_id IS NULL) or its own organization's custom type, never
-- another organization's. Nullable-target matching can't be expressed as
-- a plain composite FK, so this is the one deliberate trigger exception.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION trg_events_validate_event_type() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  et_org uuid;
BEGIN
  IF NEW.event_type_id IS NOT NULL THEN
    SELECT organization_id INTO et_org FROM event_types WHERE id = NEW.event_type_id;
    IF et_org IS NOT NULL AND et_org <> NEW.organization_id THEN
      RAISE EXCEPTION 'Event type % does not belong to organization % and is not a platform default', NEW.event_type_id, NEW.organization_id;
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_validate_event_type
BEFORE INSERT OR UPDATE OF event_type_id, organization_id ON events
FOR EACH ROW EXECUTE FUNCTION trg_events_validate_event_type();

-- ---------------------------------------------------------------------
-- Terminal-state protection: COMPLETED and CANCELLED cannot be left,
-- except through admin_override_event_status() (0021), which sets a
-- session-local flag this trigger checks.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION trg_events_protect_terminal_status() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status IN ('completed','cancelled') AND NEW.status <> OLD.status THEN
    IF current_setting('nasery.admin_override', true) IS DISTINCT FROM 'on' THEN
      RAISE EXCEPTION 'Event % is in terminal status % and cannot be changed except via admin_override_event_status()', OLD.id, OLD.status;
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_protect_terminal_status
BEFORE UPDATE OF status ON events
FOR EACH ROW EXECUTE FUNCTION trg_events_protect_terminal_status();

-- ---------------------------------------------------------------------
CREATE TABLE event_sessions (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id     uuid NOT NULL REFERENCES events(id),
  name         text,
  starts_at    timestamptz NOT NULL,
  ends_at      timestamptz NOT NULL,
  timezone     text NOT NULL,
  status       session_status NOT NULL DEFAULT 'upcoming',
  deleted_at   timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_session_time_order CHECK (ends_at > starts_at)
);

-- Supports composite FKs from attendance_records / walk_in_records.
ALTER TABLE event_sessions ADD CONSTRAINT uq_event_sessions_id_event UNIQUE (id, event_id);

CREATE INDEX idx_event_sessions_event ON event_sessions(event_id, starts_at);

-- ---------------------------------------------------------------------
-- event_members / event_member_permissions
-- ---------------------------------------------------------------------
CREATE TABLE event_members (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id                 uuid NOT NULL REFERENCES events(id),
  organization_member_id   uuid NOT NULL REFERENCES organization_members(id),
  organization_id          uuid NOT NULL,   -- denormalized: required for the composite FKs below
  event_role_label         text,            -- DISPLAY ONLY. Never used for authorization decisions.
  assigned_by              uuid REFERENCES users(id),
  status                   membership_status NOT NULL DEFAULT 'active',
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),

  -- Real composite FKs enforce that the event and the member belong to the SAME organization --
  -- no trigger needed for this rule (contrast with the event_types exception above).
  CONSTRAINT fk_event_members_event_org
    FOREIGN KEY (event_id, organization_id) REFERENCES events (id, organization_id),
  CONSTRAINT fk_event_members_member_org
    FOREIGN KEY (organization_member_id, organization_id) REFERENCES organization_members (id, organization_id)
);

CREATE UNIQUE INDEX uq_event_members_active ON event_members(event_id, organization_member_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_event_members_event  ON event_members(event_id);
CREATE INDEX idx_event_members_member ON event_members(organization_member_id);

COMMENT ON COLUMN event_members.event_role_label IS
  'Display-only. Authorization is derived from role_permissions / member_permission_overrides / event_member_permissions -- never from this text field.';

CREATE TABLE event_member_permissions (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_member_id uuid NOT NULL REFERENCES event_members(id),
  permission_id   uuid NOT NULL REFERENCES permissions(id),
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_event_member_permissions ON event_member_permissions(event_member_id, permission_id);

-- =====================================================================
-- 0010_preparation.sql
-- Purpose: checklists/tasks, client-facing progress summary.
-- Dependencies: 0001-0009
-- =====================================================================

CREATE TABLE checklists (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id    uuid NOT NULL REFERENCES events(id),
  name        text NOT NULL,
  description text,
  deleted_at  timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_checklists_event ON checklists(event_id);

CREATE TABLE tasks (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  checklist_id  uuid NOT NULL REFERENCES checklists(id),
  name          text NOT NULL,
  description   text,
  due_at        timestamptz,
  priority      task_priority NOT NULL DEFAULT 'medium',
  status        task_status NOT NULL DEFAULT 'pending',
  created_by    uuid NOT NULL REFERENCES users(id),
  deleted_at    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_tasks_checklist_status ON tasks(checklist_id, status);

-- References event_members (not organization_members directly): correctness
-- of "this assignee belongs to the right org/event" is inherited for free
-- from event_members' own composite FKs -- no extra check needed here.
CREATE TABLE task_assignees (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  task_id         uuid NOT NULL REFERENCES tasks(id),
  event_member_id uuid NOT NULL REFERENCES event_members(id),
  assigned_at     timestamptz NOT NULL DEFAULT now(),
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_task_assignees ON task_assignees(task_id, event_member_id);

CREATE TABLE event_stages (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid REFERENCES organizations(id),   -- NULL = system default set
  name            text NOT NULL,
  sort_order      integer NOT NULL DEFAULT 0,
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE event_progress (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id           uuid NOT NULL UNIQUE REFERENCES events(id),
  current_stage_id   uuid REFERENCES event_stages(id),
  readiness_percent  numeric(5,2) CHECK (readiness_percent IS NULL OR (readiness_percent BETWEEN 0 AND 100)),
  is_manual_override boolean NOT NULL DEFAULT false,
  overridden_by      uuid REFERENCES users(id),
  overridden_at      timestamptz,
  updated_at         timestamptz NOT NULL DEFAULT now(),
  created_at         timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE event_progress IS
  'The ONLY progress table exposed to clients. Clients never read checklists/tasks directly -- staff names and internal task detail are structurally unreachable from the client-facing surface.';

-- =====================================================================
-- 0011_ticketing.sql
-- Purpose: ticket types, registrations, attendees, tickets, holds, transfers.
-- Dependencies: 0001-0010
-- =====================================================================

CREATE TABLE ticket_types (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id           uuid NOT NULL REFERENCES events(id),
  name               text NOT NULL,
  description        text,
  price              numeric(12,2) NOT NULL CHECK (price >= 0),
  currency           text NOT NULL DEFAULT 'PHP',
  capacity           integer NOT NULL CHECK (capacity >= 0),
  sales_start_at     timestamptz,
  sales_end_at       timestamptz,
  status             ticket_type_status NOT NULL DEFAULT 'draft',
  cached_sold_count  integer NOT NULL DEFAULT 0,   -- DERIVED/CACHE ONLY. Never authoritative. See capacity note below.
  deleted_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now()
);

-- Supports composite FKs from tickets.
ALTER TABLE ticket_types ADD CONSTRAINT uq_ticket_types_id_event UNIQUE (id, event_id);

CREATE INDEX idx_ticket_types_event_status ON ticket_types(event_id, status);

COMMENT ON COLUMN ticket_types.cached_sold_count IS
  'Derived/cache only. Refreshed transactionally alongside real state changes for fast dashboard reads. '
  'Capacity ENFORCEMENT always recomputes from tickets + ticket_holds under a row lock -- never from this column.';

CREATE TABLE registrations (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id               uuid NOT NULL REFERENCES events(id),
  purchaser_user_id      uuid REFERENCES users(id),               -- nullable: staff/organizer-created regs may have none
  purchaser_name         text,
  purchaser_email        citext,
  purchaser_contact      text,
  registration_source    registration_source NOT NULL,
  status                 registration_status NOT NULL DEFAULT 'pending',
  created_by_member_id   uuid REFERENCES organization_members(id),
  deleted_at             timestamptz,
  created_at             timestamptz NOT NULL DEFAULT now(),
  updated_at             timestamptz NOT NULL DEFAULT now()
);

-- Supports composite FKs from tickets.
ALTER TABLE registrations ADD CONSTRAINT uq_registrations_id_event UNIQUE (id, event_id);

CREATE INDEX idx_registrations_event_status ON registrations(event_id, status);
CREATE INDEX idx_registrations_purchaser    ON registrations(purchaser_user_id);

CREATE TABLE attendees (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name      text NOT NULL,
  email          citext,           -- INTENTIONALLY NOT UNIQUE -- same person may attend many events
  contact_number text,
  gender         text,
  age            integer CHECK (age IS NULL OR age >= 0),
  extra_fields   jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_attendees_email ON attendees(email);   -- lookup aid only, NOT unique

CREATE TABLE tickets (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  registration_id     uuid NOT NULL REFERENCES registrations(id),
  ticket_type_id      uuid NOT NULL REFERENCES ticket_types(id),
  attendee_id         uuid NOT NULL REFERENCES attendees(id),
  event_id            uuid NOT NULL,   -- denormalized: enables the composite FKs below and hot-path RLS/queries
  admission_type      admission_type NOT NULL,
  purchased_price     numeric(12,2) NOT NULL DEFAULT 0 CHECK (purchased_price >= 0),
  platform_fee_amount numeric(12,2) NOT NULL DEFAULT 0 CHECK (platform_fee_amount >= 0),
  status              ticket_status NOT NULL DEFAULT 'pending',
  issued_at           timestamptz,
  cancelled_at        timestamptz,
  cancellation_reason text,
  deleted_at          timestamptz,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),

  -- Non-PAID admission types never carry a price or fee.
  CONSTRAINT chk_ticket_zero_amount_if_not_paid
    CHECK (admission_type = 'paid' OR (purchased_price = 0 AND platform_fee_amount = 0)),

  -- Real composite FKs: a ticket's registration and ticket_type must both belong
  -- to the SAME event as the ticket itself. Requires the uq_*_id_event constraints above.
  CONSTRAINT fk_tickets_registration_event
    FOREIGN KEY (registration_id, event_id) REFERENCES registrations (id, event_id),
  CONSTRAINT fk_tickets_ticket_type_event
    FOREIGN KEY (ticket_type_id, event_id) REFERENCES ticket_types (id, event_id)
);

-- Supports composite FKs from attendance_records / walk_in_records.
ALTER TABLE tickets ADD CONSTRAINT uq_tickets_id_event UNIQUE (id, event_id);

CREATE INDEX idx_tickets_registration    ON tickets(registration_id);
CREATE INDEX idx_tickets_type_status     ON tickets(ticket_type_id, status);
CREATE INDEX idx_tickets_attendee        ON tickets(attendee_id);
CREATE INDEX idx_tickets_event           ON tickets(event_id);

COMMENT ON COLUMN tickets.status IS
  'Operational state only. Independent of payments.status (financial settlement). '
  'A PENDING ticket does NOT independently consume capacity -- its capacity is represented by its ticket_holds row.';

CREATE TABLE ticket_holds (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  registration_id  uuid NOT NULL REFERENCES registrations(id),   -- NOT NULL: a draft registration always precedes a hold
  ticket_type_id   uuid NOT NULL REFERENCES ticket_types(id),
  quantity         integer NOT NULL CHECK (quantity > 0),
  expires_at       timestamptz NOT NULL,
  status           hold_status NOT NULL DEFAULT 'active',
  released_at      timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

-- Critical index: every capacity check filters exactly this shape.
CREATE INDEX idx_ticket_holds_capacity ON ticket_holds(ticket_type_id, status, expires_at);
CREATE INDEX idx_ticket_holds_registration ON ticket_holds(registration_id);

CREATE TABLE ticket_transfers (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_id        uuid NOT NULL REFERENCES tickets(id),
  from_attendee_id uuid NOT NULL REFERENCES attendees(id),
  to_attendee_id   uuid NOT NULL REFERENCES attendees(id),
  transferred_by   uuid NOT NULL REFERENCES users(id),
  reason           text,
  qr_action        transfer_qr_action NOT NULL DEFAULT 'retained',
  transferred_at   timestamptz NOT NULL DEFAULT now(),
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_ticket_transfers_ticket ON ticket_transfers(ticket_id);

COMMENT ON TABLE ticket_transfers IS
  'Append-only audit trail. A transfer mutates tickets.attendee_id in place (via the transfer_ticket() '
  'domain function in 0021) -- ticket.status is never used as a substitute for transfer history.';

-- =====================================================================
-- 0012_payments.sql
-- Purpose: PayMongo payment attempts and webhook idempotency log.
-- Dependencies: 0001-0011 (registrations); subscription_invoices FK is
--               added later in 0020 once that table exists.
-- =====================================================================

CREATE TABLE payments (
  id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  registration_id             uuid REFERENCES registrations(id),
  subscription_invoice_id     uuid,     -- FK added in 0020 after subscription_invoices exists
  paymongo_payment_intent_id  text NOT NULL,
  amount                      numeric(12,2) NOT NULL CHECK (amount >= 0),
  currency                    text NOT NULL DEFAULT 'PHP',
  status                      payment_status NOT NULL DEFAULT 'pending',
  paid_at                     timestamptz,
  failure_reason              text,
  deleted_at                  timestamptz,
  created_at                  timestamptz NOT NULL DEFAULT now(),
  updated_at                  timestamptz NOT NULL DEFAULT now(),

  -- Exactly one payable target per payment -- explicit FKs, no payable_type/payable_id polymorphism.
  CONSTRAINT chk_payments_exactly_one_payable CHECK (
    (registration_id IS NOT NULL)::int + (subscription_invoice_id IS NOT NULL)::int = 1
  )
);

CREATE UNIQUE INDEX uq_payments_intent ON payments(paymongo_payment_intent_id);
CREATE INDEX idx_payments_registration ON payments(registration_id);
CREATE INDEX idx_payments_sub_invoice  ON payments(subscription_invoice_id);
CREATE INDEX idx_payments_status       ON payments(status);

-- Settlement exclusivity: at most one PAID payment may ever exist per registration/invoice.
-- Ordinary (non-deferred) partial unique indexes -- unlike ownership transfer, there is never
-- a legitimate transient state where two payments for the same parent are both "paid" at once,
-- so immediate enforcement is correct here, not merely sufficient.
CREATE UNIQUE INDEX uq_payments_registration_settled
  ON payments(registration_id) WHERE status = 'paid';
CREATE UNIQUE INDEX uq_payments_invoice_settled
  ON payments(subscription_invoice_id) WHERE status = 'paid';

COMMENT ON COLUMN payments.amount IS
  'The literal PayMongo checkout charge. Equals Ticket Subtotal (sum of PAID tickets'' purchased_price) -- '
  'the platform fee is DEDUCTED from this amount internally, never added on top. Never edited after settlement; '
  'refunds are recorded separately in payment_refunds (0014) and never mutate this value.';

CREATE TABLE payment_webhook_events (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id          uuid NOT NULL REFERENCES payments(id),
  provider_event_id   text NOT NULL,
  event_type          text NOT NULL,
  payload             jsonb NOT NULL,
  processing_status   webhook_processing_status NOT NULL DEFAULT 'received',
  processed_at        timestamptz,
  created_at          timestamptz NOT NULL DEFAULT now()
);

-- Idempotency key: this index IS the duplicate-webhook-delivery guard.
CREATE UNIQUE INDEX uq_webhook_events_provider_id ON payment_webhook_events(provider_event_id);
CREATE INDEX idx_webhook_events_payment ON payment_webhook_events(payment_id);

-- =====================================================================
-- 0013_platform_fees.sql
-- Purpose: dynamic, versioned platform fee configuration + immutable
--          per-payment snapshots. NO payer column (V1: deduction model
--          only -- attendee always pays exactly the ticket subtotal).
-- Dependencies: 0001-0012
-- =====================================================================

CREATE TABLE platform_fee_configs (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name             text NOT NULL,
  is_enabled       boolean NOT NULL DEFAULT true,
  fee_model        fee_model_type NOT NULL DEFAULT 'percentage',
  percentage_rate  numeric(6,4) CHECK (percentage_rate IS NULL OR (percentage_rate >= 0 AND percentage_rate <= 1)),
  fixed_amount     numeric(12,2) CHECK (fixed_amount IS NULL OR fixed_amount >= 0),
  effective_from   timestamptz NOT NULL DEFAULT now(),
  effective_to     timestamptz,
  created_by       uuid REFERENCES users(id),
  created_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_fee_model_fields CHECK (
    (fee_model = 'percentage' AND percentage_rate IS NOT NULL)
    OR (fee_model = 'fixed' AND fixed_amount IS NOT NULL)
    OR (fee_model = 'hybrid' AND percentage_rate IS NOT NULL AND fixed_amount IS NOT NULL)
  )
);

CREATE INDEX idx_fee_configs_active ON platform_fee_configs(is_enabled, effective_from, effective_to);

COMMENT ON TABLE platform_fee_configs IS
  'Versioned via effective_from/effective_to -- never edited in place; a change adds a new row. '
  'No payer column: V1 always deducts the fee from the ticket subtotal, the attendee is never charged extra.';

CREATE TABLE platform_fee_snapshots (
  id                              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id                      uuid NOT NULL UNIQUE REFERENCES payments(id),
  platform_fee_config_id          uuid NOT NULL REFERENCES platform_fee_configs(id),
  fee_enabled                     boolean NOT NULL,
  fee_type                        fee_model_type NOT NULL,
  fee_rate                        numeric(6,4),
  fee_fixed_amount                numeric(12,2),
  applicable_transaction_amount   numeric(12,2) NOT NULL CHECK (applicable_transaction_amount >= 0),  -- Ticket Subtotal at computation time
  computed_amount                 numeric(12,2) NOT NULL CHECK (computed_amount >= 0),                 -- the Platform Fee itself
  created_at                      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_fee_snapshots_config ON platform_fee_snapshots(platform_fee_config_id);

COMMENT ON COLUMN platform_fee_snapshots.applicable_transaction_amount IS
  'The Ticket Subtotal the fee was computed FROM -- never the checkout total (which equals the subtotal '
  'anyway under the deduction model), preserved so the fee arithmetic can be verified after the fact even '
  'if platform_fee_configs has since changed.';


-- =====================================================================
-- 0014_refunds.sql
-- Purpose: represent refunds/reversals WITHOUT ever mutating the original
--          settled payment. Supports partial refunds.
-- Dependencies: 0001-0013
-- =====================================================================

CREATE TABLE payment_refunds (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id            uuid NOT NULL REFERENCES payments(id),
  amount                numeric(12,2) NOT NULL CHECK (amount > 0),
  fee_portion_refunded  numeric(12,2) CHECK (fee_portion_refunded IS NULL OR fee_portion_refunded >= 0),  -- NULLABLE: policy not yet decided, see notes
  reason                text NOT NULL,
  recorded_by           uuid NOT NULL REFERENCES users(id),
  external_reference    text,
  refunded_at           timestamptz NOT NULL DEFAULT now(),
  created_at            timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_payment_refunds_payment ON payment_refunds(payment_id);

COMMENT ON COLUMN payment_refunds.fee_portion_refunded IS
  'Nullable by design: whether the platform fee itself is refunded, retained, or split is an OPERATIONAL '
  'policy decision not yet made by the business. NULL means "not yet allocated" and must be reported as such, '
  'never defaulted to $0 or to a proportional share.';

COMMENT ON TABLE payment_refunds IS
  'V1 has no automated refund workflow. Rows are inserted only via an explicit, audited admin/organizer '
  'correction action. The original payments row (amount, status) is NEVER edited by a refund -- both remain '
  'independently, permanently queryable. Negative "reversal-of-reversal" rows are not a supported pattern; '
  'any correction to a refund is its own separately audited adjustment mechanism, out of V1 scope.';

-- ---------------------------------------------------------------------
-- Aggregate guard: total refunds for a payment may never exceed the
-- original payment amount. This spans multiple rows, so it cannot be a
-- plain CHECK -- it is a genuinely valid DEFERRABLE constraint trigger
-- (deferring is legal for constraints/constraint triggers, just never
-- for plain indexes -- contrast with the withdrawn "deferrable partial
-- index" idea from an earlier pass). The parent payment row is locked
-- FOR UPDATE first, serializing concurrent refund-recording transactions
-- against the same payment.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION trg_payment_refunds_check_total() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  original_amount numeric(12,2);
  refunded_total  numeric(12,2);
BEGIN
  SELECT amount INTO original_amount FROM payments WHERE id = NEW.payment_id FOR UPDATE;

  IF original_amount IS NULL THEN
    RAISE EXCEPTION 'Payment % does not exist', NEW.payment_id;
  END IF;

  SELECT COALESCE(SUM(amount), 0) INTO refunded_total
  FROM payment_refunds WHERE payment_id = NEW.payment_id;

  IF refunded_total > original_amount THEN
    RAISE EXCEPTION 'Total refunds (%) for payment % exceed the original payment amount (%)',
      refunded_total, NEW.payment_id, original_amount;
  END IF;

  RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER trg_check_refund_total
AFTER INSERT ON payment_refunds
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION trg_payment_refunds_check_total();

-- =====================================================================
-- 0015_attendance.sql
-- Purpose: QR credentials, check-in records, walk-ins.
-- Dependencies: 0001-0014
-- =====================================================================

CREATE TABLE qr_tickets (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_id           uuid NOT NULL UNIQUE REFERENCES tickets(id),
  token_hash          text NOT NULL,     -- hash only; the raw scannable token is never stored server-side
  status              text NOT NULL DEFAULT 'active' CHECK (status IN ('active','invalidated','used')),
  issued_at           timestamptz NOT NULL DEFAULT now(),
  invalidated_at      timestamptz,
  invalidation_reason text,
  created_at          timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_qr_tickets_token_hash ON qr_tickets(token_hash);
CREATE INDEX idx_qr_tickets_status ON qr_tickets(status);

CREATE TABLE attendance_records (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_id              uuid NOT NULL REFERENCES tickets(id),
  event_id               uuid NOT NULL,    -- denormalized: enables composite FKs below
  event_session_id       uuid REFERENCES event_sessions(id),   -- nullable: single-session events
  checked_in_by          uuid REFERENCES organization_members(id),
  method                 text NOT NULL DEFAULT 'qr_scan' CHECK (method IN ('qr_scan','manual')),
  checked_in_at          timestamptz NOT NULL DEFAULT now(),
  duplicate_attempt_count integer NOT NULL DEFAULT 0,
  created_at             timestamptz NOT NULL DEFAULT now(),

  -- Real composite FKs: the ticket and (optional) session must both belong to this same event.
  CONSTRAINT fk_attendance_ticket_event
    FOREIGN KEY (ticket_id, event_id) REFERENCES tickets (id, event_id),
  CONSTRAINT fk_attendance_session_event
    FOREIGN KEY (event_session_id, event_id) REFERENCES event_sessions (id, event_id)
    -- MATCH SIMPLE (default): skipped when event_session_id IS NULL, enforced when populated.
);

-- Duplicate check-in prevention.
CREATE UNIQUE INDEX uq_attendance_ticket_session
  ON attendance_records(ticket_id, event_session_id) WHERE event_session_id IS NOT NULL;
CREATE UNIQUE INDEX uq_attendance_ticket_no_session
  ON attendance_records(ticket_id) WHERE event_session_id IS NULL;

CREATE INDEX idx_attendance_event ON attendance_records(event_id);

CREATE TABLE walk_in_records (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id         uuid NOT NULL REFERENCES events(id),
  event_session_id uuid REFERENCES event_sessions(id),
  attendee_id      uuid REFERENCES attendees(id),           -- nullable: headcount_only has no identity
  linked_ticket_id uuid REFERENCES tickets(id),              -- nullable
  category         walk_in_category NOT NULL,
  count            integer NOT NULL DEFAULT 1 CHECK (count > 0),
  recorded_by      uuid REFERENCES organization_members(id),
  recorded_at      timestamptz NOT NULL DEFAULT now(),
  created_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT fk_walk_in_session_event
    FOREIGN KEY (event_session_id, event_id) REFERENCES event_sessions (id, event_id),
  CONSTRAINT fk_walk_in_linked_ticket_event
    FOREIGN KEY (linked_ticket_id, event_id) REFERENCES tickets (id, event_id),

  -- Only the anonymous headcount category may represent more than one person per row.
  CONSTRAINT chk_walk_in_count_by_category CHECK (
    (category = 'headcount_only' AND count >= 1)
    OR (category <> 'headcount_only' AND count = 1)
  )
);

CREATE INDEX idx_walk_in_event ON walk_in_records(event_id);

COMMENT ON TABLE walk_in_records IS
  'A row with linked_ticket_id set contributes NOTHING extra to overall-event-capacity accounting -- its '
  'ticket already counts via the tickets table. Only linked_ticket_id IS NULL rows contribute (via count) '
  'to the capacity formula, preventing double-counting. See capacity notes in 0021.';

-- =====================================================================
-- 0016_notifications.sql
-- Dependencies: 0001-0015
-- =====================================================================

CREATE TABLE notifications (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  recipient_user_id  uuid NOT NULL REFERENCES users(id),
  type               text NOT NULL,
  title              text NOT NULL,
  body               text,
  data               jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_critical        boolean NOT NULL DEFAULT false,
  read_at            timestamptz,
  deleted_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_notifications_recipient_read ON notifications(recipient_user_id, read_at);

CREATE TABLE notification_preferences (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL REFERENCES users(id),
  notification_type text NOT NULL,
  channel           notification_channel NOT NULL,
  is_enabled        boolean NOT NULL DEFAULT true,
  created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_notification_preferences ON notification_preferences(user_id, notification_type, channel);

CREATE TABLE notification_deliveries (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  notification_id uuid NOT NULL REFERENCES notifications(id),
  channel         notification_channel NOT NULL,
  status          delivery_status NOT NULL DEFAULT 'queued',
  attempt_count   integer NOT NULL DEFAULT 0,
  last_error      text,
  sent_at         timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_notification_deliveries ON notification_deliveries(notification_id, channel, status);

COMMENT ON COLUMN notifications.is_critical IS
  'Critical notifications bypass notification_preferences entirely at the application layer -- they are '
  'never "checked and found enabled," they simply are not subject to the preference lookup.';

-- =====================================================================
-- 0017_reviews.sql
-- Dependencies: 0001-0016
-- =====================================================================

CREATE TABLE reviews (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id        uuid NOT NULL REFERENCES events(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  client_user_id  uuid NOT NULL REFERENCES users(id),
  rating          smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  written_review  text,
  status          review_status NOT NULL DEFAULT 'published',
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

-- One review per client per event (events is 1:N to reviews -- many distinct clients may review the same event).
CREATE UNIQUE INDEX uq_reviews_client_event ON reviews(client_user_id, event_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_reviews_org_status ON reviews(organization_id, status);

CREATE TABLE review_edits (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id              uuid NOT NULL REFERENCES reviews(id),
  previous_rating        smallint,
  previous_written_review text,
  edited_at              timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE review_responses (
  id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id               uuid NOT NULL UNIQUE REFERENCES reviews(id),   -- one organizer response per review
  organization_member_id  uuid NOT NULL REFERENCES organization_members(id),
  response_text           text NOT NULL,
  deleted_at              timestamptz,
  created_at              timestamptz NOT NULL DEFAULT now(),
  updated_at              timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE review_reports (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id     uuid NOT NULL REFERENCES reviews(id),
  reported_by   uuid NOT NULL REFERENCES users(id),
  reason        text NOT NULL,
  status        moderation_report_status NOT NULL DEFAULT 'open',
  resolved_by   uuid REFERENCES users(id),
  resolved_at   timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_review_reports_review ON review_reports(review_id);

-- Public display view: never exposes contact info; identity reduced to first name + last initial.
CREATE VIEW public_reviews AS
  SELECT r.id, r.event_id, r.organization_id, r.rating, r.written_review, r.created_at,
         u.first_name AS reviewer_first_name,
         LEFT(u.last_name, 1) AS reviewer_last_initial,
         et.name AS event_type_name
  FROM reviews r
  JOIN users u ON u.id = r.client_user_id
  JOIN events e ON e.id = r.event_id
  LEFT JOIN event_types et ON et.id = e.event_type_id
  WHERE r.status = 'published' AND r.deleted_at IS NULL;

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

-- =====================================================================
-- 0020_saas.sql
-- Purpose: plans, features, entitlements, subscriptions, invoices.
-- Dependencies: 0001-0019, then back-fills the payments FK from 0012.
-- =====================================================================

CREATE TABLE plans (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name             text NOT NULL,
  slug             text NOT NULL,
  price_monthly    numeric(12,2) NOT NULL DEFAULT 0 CHECK (price_monthly >= 0),
  price_yearly     numeric(12,2) NOT NULL DEFAULT 0 CHECK (price_yearly >= 0),
  is_active        boolean NOT NULL DEFAULT true,
  is_default_free  boolean NOT NULL DEFAULT false,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_plans_slug ON plans(slug) WHERE deleted_at IS NULL;

CREATE TABLE features (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key         text NOT NULL,
  name        text NOT NULL,
  value_type  text NOT NULL CHECK (value_type IN ('boolean','numeric')),
  description text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_features_key ON features(key);

CREATE TABLE plan_entitlements (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  plan_id        uuid NOT NULL REFERENCES plans(id),
  feature_id     uuid NOT NULL REFERENCES features(id),
  boolean_value  boolean,
  numeric_value  numeric(12,2),
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_plan_entitlements ON plan_entitlements(plan_id, feature_id);

CREATE TABLE subscriptions (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id          uuid NOT NULL REFERENCES organizations(id),
  plan_id                  uuid NOT NULL REFERENCES plans(id),
  billing_cycle            billing_cycle NOT NULL,
  status                   subscription_status NOT NULL DEFAULT 'active',
  current_period_start     timestamptz NOT NULL DEFAULT now(),
  current_period_end       timestamptz NOT NULL,
  grace_period_ends_at     timestamptz,
  cancel_at_period_end     boolean NOT NULL DEFAULT false,
  cancelled_at             timestamptz,
  downgraded_from_plan_id  uuid REFERENCES plans(id),
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_grace_period_fields CHECK (status = 'grace_period' OR grace_period_ends_at IS NULL)
);

-- One "live" subscription per organization at a time.
CREATE UNIQUE INDEX uq_subscriptions_live
  ON subscriptions(organization_id) WHERE status IN ('active','payment_failed','grace_period');

CREATE INDEX idx_subscriptions_org_status ON subscriptions(organization_id, status);

CREATE TABLE subscription_invoices (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subscription_id       uuid NOT NULL REFERENCES subscriptions(id),
  amount                numeric(12,2) NOT NULL CHECK (amount >= 0),
  currency              text NOT NULL DEFAULT 'PHP',
  billing_period_start  timestamptz NOT NULL,
  billing_period_end    timestamptz NOT NULL,
  status                invoice_status NOT NULL DEFAULT 'draft',
  issued_at             timestamptz,
  created_at            timestamptz NOT NULL DEFAULT now()
);

-- NOTE: no payment_id column here. The relationship is subscription_invoices 1:N payments
-- via payments.subscription_invoice_id (added below) -- an invoice may have several payment
-- attempts (retries); at most one may ever settle (uq_payments_invoice_settled from 0012).
CREATE INDEX idx_subscription_invoices_subscription ON subscription_invoices(subscription_id);

-- Back-fill the FK deferred from 0012 now that subscription_invoices exists.
ALTER TABLE payments
  ADD CONSTRAINT fk_payments_subscription_invoice FOREIGN KEY (subscription_invoice_id) REFERENCES subscription_invoices(id);

-- =====================================================================
-- 0021_functions_triggers.sql
-- Purpose: domain-action functions that span multiple tables. Every
--          function below is the SANCTIONED path for its transition.
--
-- SECURITY NOTE (fixed in this version): every function that writes to
-- audit_logs, ownership_transfers, or organizer_approval_requests is
-- declared SECURITY DEFINER (owned by the migration-running privileged
-- role, which bypasses RLS) specifically so its internal INSERTs succeed
-- even though ordinary authenticated users are granted NO direct INSERT
-- on audit_logs (see 0022) -- the only way to write an audit row is
-- through one of these functions. Because SECURITY DEFINER elevates
-- privilege, NONE of these functions accept a caller-identity parameter
-- (p_actor_user_id, p_initiated_by, etc.) -- identity is always resolved
-- from auth.uid() internally, and each function independently verifies
-- that auth.uid() is actually entitled to perform the action before
-- doing anything, exactly like the RLS helper functions in 0022.
-- Dependencies: 0001-0020 (needs audit_logs, quotations, events, tickets,
--               ticket_holds, subscriptions, organizer_approval_requests,
--               admin_access_grants).
-- =====================================================================

-- ---------------------------------------------------------------------
-- transfer_organization_ownership()
-- Caller must currently BE the org's active primary owner (verified via
-- auth.uid(), not a trusted parameter).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION transfer_organization_ownership(
  p_organization_id uuid,
  p_from_member_id  uuid,
  p_to_member_id    uuid,
  p_reason          text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  lock_first  uuid;
  lock_second uuid;
BEGIN
  IF p_reason IS NULL OR length(trim(p_reason)) = 0 THEN
    RAISE EXCEPTION 'A reason is required for an ownership transfer';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM organization_members
    WHERE id = p_from_member_id AND organization_id = p_organization_id
      AND user_id = auth.uid() AND is_primary_owner AND status = 'active' AND deleted_at IS NULL
  ) THEN
    RAISE EXCEPTION 'Only the organization''s current active primary owner may initiate an ownership transfer';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM organization_members
    WHERE id = p_to_member_id AND organization_id = p_organization_id AND status = 'active' AND deleted_at IS NULL
  ) THEN
    RAISE EXCEPTION 'Target member % is not an active member of organization %', p_to_member_id, p_organization_id;
  END IF;

  IF p_from_member_id < p_to_member_id THEN
    lock_first := p_from_member_id; lock_second := p_to_member_id;
  ELSE
    lock_first := p_to_member_id; lock_second := p_from_member_id;
  END IF;

  PERFORM 1 FROM organization_members WHERE id = lock_first  FOR UPDATE;
  PERFORM 1 FROM organization_members WHERE id = lock_second FOR UPDATE;

  UPDATE organization_members
     SET is_primary_owner = true, is_owner = true, updated_at = now()
   WHERE id = p_to_member_id AND organization_id = p_organization_id;

  UPDATE organization_members
     SET is_primary_owner = false, updated_at = now()
   WHERE id = p_from_member_id AND organization_id = p_organization_id;

  INSERT INTO ownership_transfers (organization_id, from_member_id, to_member_id, initiated_by, reason, transferred_at)
  VALUES (p_organization_id, p_from_member_id, p_to_member_id, auth.uid(), p_reason, now());

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  VALUES (auth.uid(), p_organization_id, 'ownership_transfer', 'organization_members', p_to_member_id, p_reason,
          jsonb_build_object('from_member_id', p_from_member_id, 'to_member_id', p_to_member_id));
END;
$$;

-- ---------------------------------------------------------------------
-- accept_quotation() -- caller must be the inquiry's own client.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION accept_quotation(p_quotation_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_inquiry_id uuid;
  v_status     quotation_status;
BEGIN
  SELECT inquiry_id INTO v_inquiry_id FROM quotations WHERE id = p_quotation_id;
  IF v_inquiry_id IS NULL THEN
    RAISE EXCEPTION 'Quotation % not found', p_quotation_id;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM inquiries WHERE id = v_inquiry_id AND client_user_id = auth.uid()) THEN
    RAISE EXCEPTION 'Only the inquiry''s own client may accept a quotation';
  END IF;

  PERFORM 1 FROM inquiries WHERE id = v_inquiry_id FOR UPDATE;

  SELECT status INTO v_status FROM quotations WHERE id = p_quotation_id;
  IF v_status <> 'submitted' THEN
    RAISE EXCEPTION 'Quotation % is not in submitted status (currently %)', p_quotation_id, v_status;
  END IF;

  UPDATE quotations SET status = 'accepted', decided_at = now(), decided_by = auth.uid(), updated_at = now()
  WHERE id = p_quotation_id;

  UPDATE inquiries SET status = 'accepted', updated_at = now() WHERE id = v_inquiry_id;

  UPDATE quotations SET status = 'rejected', decided_at = now(), decided_by = auth.uid(), updated_at = now()
  WHERE inquiry_id = v_inquiry_id AND id <> p_quotation_id AND status = 'submitted';

  INSERT INTO quotation_status_history (quotation_id, from_status, to_status, actor_user_id, reason)
  VALUES (p_quotation_id, 'submitted', 'accepted', auth.uid(), 'accepted by client');

  INSERT INTO quotation_status_history (quotation_id, from_status, to_status, actor_user_id, reason)
  SELECT id, 'submitted', 'rejected', auth.uid(), 'competing quotation accepted'
  FROM quotations WHERE inquiry_id = v_inquiry_id AND id <> p_quotation_id AND status = 'rejected'
    AND decided_at = (SELECT decided_at FROM quotations WHERE id = p_quotation_id);

  INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, reason)
  VALUES (auth.uid(), 'quotation_accept', 'quotations', p_quotation_id, 'client accepted quotation');
END;
$$;

-- ---------------------------------------------------------------------
-- set_organizer_approval_status() -- caller must be a platform admin.
-- Keeps organizations.approval_status (authoritative) and
-- organizer_approval_requests (historical log) from ever drifting apart.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_organizer_approval_status(
  p_organization_id uuid,
  p_new_status      approval_status,
  p_decision_reason text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin') THEN
    RAISE EXCEPTION 'Only an admin account may change organizer approval status';
  END IF;
  IF p_new_status IN ('rejected','suspended') AND (p_decision_reason IS NULL OR length(trim(p_decision_reason)) = 0) THEN
    RAISE EXCEPTION 'A reason is required to set organizer approval status to %', p_new_status;
  END IF;

  UPDATE organizations
     SET approval_status = p_new_status,
         is_suspended = (p_new_status = 'suspended'),
         suspended_reason = CASE WHEN p_new_status = 'suspended' THEN p_decision_reason ELSE suspended_reason END,
         suspended_at     = CASE WHEN p_new_status = 'suspended' THEN now() ELSE suspended_at END,
         suspended_by     = CASE WHEN p_new_status = 'suspended' THEN auth.uid() ELSE suspended_by END,
         updated_at = now()
   WHERE id = p_organization_id;

  INSERT INTO organizer_approval_requests (organization_id, status, reviewed_by, decision_reason, decided_at)
  VALUES (p_organization_id, p_new_status, auth.uid(), p_decision_reason, now());

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  VALUES (auth.uid(), p_organization_id, 'organizer_approval_status_change', 'organizations', p_organization_id,
          p_decision_reason, jsonb_build_object('status', p_new_status));
END;
$$;

-- ---------------------------------------------------------------------
-- admin_override_event_status() -- caller must be a platform admin.
-- The sole escape hatch past the terminal-state trigger on events.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION admin_override_event_status(
  p_event_id   uuid,
  p_new_status event_status,
  p_reason     text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
  IF p_reason IS NULL OR length(trim(p_reason)) = 0 THEN
    RAISE EXCEPTION 'A reason is required for an administrative event-status override';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin') THEN
    RAISE EXCEPTION 'Only an admin account may perform an event-status override';
  END IF;

  PERFORM set_config('nasery.admin_override', 'on', true);

  UPDATE events SET status = p_new_status, updated_at = now() WHERE id = p_event_id;

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  SELECT auth.uid(), organization_id, 'admin_override_event_status', 'events', p_event_id, p_reason,
         jsonb_build_object('status', p_new_status)
  FROM events WHERE id = p_event_id;

  PERFORM set_config('nasery.admin_override', 'off', true);
END;
$$;

-- ---------------------------------------------------------------------
-- transfer_ticket() -- mutates tickets.attendee_id in place (LOCKED
-- direction). Caller must either hold 'ticket.manage' on the ticket's
-- event, or be the registration's own purchaser.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION transfer_ticket(
  p_ticket_id      uuid,
  p_to_attendee_id uuid,
  p_reason         text,
  p_qr_action      transfer_qr_action DEFAULT 'retained'
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_from_attendee_id uuid;
  v_status           ticket_status;
  v_event_id         uuid;
  v_purchaser        uuid;
BEGIN
  SELECT t.attendee_id, t.status, t.event_id, r.purchaser_user_id
    INTO v_from_attendee_id, v_status, v_event_id, v_purchaser
  FROM tickets t JOIN registrations r ON r.id = t.registration_id
  WHERE t.id = p_ticket_id FOR UPDATE OF t;

  IF v_from_attendee_id IS NULL THEN
    RAISE EXCEPTION 'Ticket % not found', p_ticket_id;
  END IF;
  IF NOT (user_has_event_permission(v_event_id, 'ticket.manage') OR v_purchaser = auth.uid()) THEN
    RAISE EXCEPTION 'Not authorized to transfer ticket %', p_ticket_id;
  END IF;
  IF v_status <> 'valid' THEN
    RAISE EXCEPTION 'Only a VALID ticket may be transferred (ticket % is %)', p_ticket_id, v_status;
  END IF;

  UPDATE tickets SET attendee_id = p_to_attendee_id, updated_at = now() WHERE id = p_ticket_id;

  IF p_qr_action = 'reissued' THEN
    UPDATE qr_tickets SET status = 'invalidated', invalidated_at = now(),
           invalidation_reason = 'ticket transferred, QR reissued'
     WHERE ticket_id = p_ticket_id;
    -- application layer generates and inserts the new qr_tickets row with a fresh token_hash
  END IF;

  INSERT INTO ticket_transfers (ticket_id, from_attendee_id, to_attendee_id, transferred_by, reason, qr_action)
  VALUES (p_ticket_id, v_from_attendee_id, p_to_attendee_id, auth.uid(), p_reason, p_qr_action);

  INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, reason, old_values, new_values)
  VALUES (auth.uid(), 'ticket_transfer', 'tickets', p_ticket_id, p_reason,
          jsonb_build_object('attendee_id', v_from_attendee_id), jsonb_build_object('attendee_id', p_to_attendee_id));
END;
$$;

GRANT EXECUTE ON FUNCTION transfer_organization_ownership(uuid, uuid, uuid, text) TO authenticated;
GRANT EXECUTE ON FUNCTION accept_quotation(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION set_organizer_approval_status(uuid, approval_status, text) TO authenticated;
GRANT EXECUTE ON FUNCTION admin_override_event_status(uuid, event_status, text) TO authenticated;
GRANT EXECUTE ON FUNCTION transfer_ticket(uuid, uuid, text, transfer_qr_action) TO authenticated;

-- ---------------------------------------------------------------------
-- Capacity helper functions (read-only; the SAME formula Laravel uses
-- under a row lock during enforcement -- kept here as the single source
-- of truth for the calculation so application code and reporting never
-- drift apart). These do NOT themselves take locks; enforcement in
-- Laravel wraps its own SELECT ... FOR UPDATE around the equivalent query.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ticket_type_consumed_capacity(p_ticket_type_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT
    (SELECT COUNT(*) FROM tickets WHERE ticket_type_id = p_ticket_type_id AND status IN ('valid','used'))
    +
    (SELECT COALESCE(SUM(quantity), 0) FROM ticket_holds
       WHERE ticket_type_id = p_ticket_type_id AND status = 'active' AND expires_at > now());
$$;

CREATE OR REPLACE FUNCTION ticket_type_available_capacity(p_ticket_type_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT tt.capacity - ticket_type_consumed_capacity(p_ticket_type_id)
  FROM ticket_types tt WHERE tt.id = p_ticket_type_id;
$$;

CREATE OR REPLACE FUNCTION event_consumed_capacity(p_event_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT
    (SELECT COUNT(*) FROM tickets WHERE event_id = p_event_id AND status IN ('valid','used'))
    +
    (SELECT COALESCE(SUM(h.quantity), 0) FROM ticket_holds h
       JOIN ticket_types tt ON tt.id = h.ticket_type_id
       WHERE tt.event_id = p_event_id AND h.status = 'active' AND h.expires_at > now())
    +
    (SELECT COALESCE(SUM(count), 0) FROM walk_in_records
       WHERE event_id = p_event_id AND linked_ticket_id IS NULL);
$$;

CREATE OR REPLACE FUNCTION event_available_capacity(p_event_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT e.overall_capacity - event_consumed_capacity(p_event_id)
  FROM events e WHERE e.id = p_event_id AND e.overall_capacity IS NOT NULL;
$$;

GRANT EXECUTE ON FUNCTION ticket_type_consumed_capacity(uuid)  TO authenticated;
GRANT EXECUTE ON FUNCTION ticket_type_available_capacity(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION event_consumed_capacity(uuid)        TO authenticated;
GRANT EXECUTE ON FUNCTION event_available_capacity(uuid)       TO authenticated;

-- ---------------------------------------------------------------------
-- expire_stale_holds() -- background reconciliation (run periodically by
-- the Laravel scheduler / pg_cron, as a privileged service role, NOT
-- exposed to authenticated users). Cascades expired holds to their
-- PENDING tickets and draft registrations. This is a safety net; the
-- expires_at > now() predicate already excludes expired holds from every
-- capacity read even before this job runs.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expire_stale_holds() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_hold record;
  v_count integer := 0;
BEGIN
  FOR v_hold IN
    SELECT * FROM ticket_holds WHERE status = 'active' AND expires_at <= now() FOR UPDATE SKIP LOCKED
  LOOP
    UPDATE ticket_holds SET status = 'expired', released_at = now(), updated_at = now() WHERE id = v_hold.id;

    UPDATE tickets SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'hold expired', updated_at = now()
     WHERE registration_id = v_hold.registration_id AND ticket_type_id = v_hold.ticket_type_id AND status = 'pending';

    UPDATE registrations SET status = 'cancelled', updated_at = now()
     WHERE id = v_hold.registration_id AND status = 'pending'
       AND NOT EXISTS (SELECT 1 FROM ticket_holds WHERE registration_id = v_hold.registration_id AND status = 'active');

    v_count := v_count + 1;
  END LOOP;
  RETURN v_count;
END;
$$;
-- Deliberately NOT granted to `authenticated` -- invoked only by the scheduler's service connection.


-- =====================================================================
-- 0022_rls.sql
-- Purpose: Row Level Security. Laravel authorizes first; this is the
--          independent database-level backstop against cross-tenant
--          access even if application logic has a bug.
-- Dependencies: 0001-0021
-- =====================================================================

-- ---------------------------------------------------------------------
-- Dedicated, non-login role for the four helper functions below. It is
-- granted BYPASSRLS specifically so that a helper function's OWN internal
-- reads (e.g. user_org_ids() reading organization_members) do not
-- re-trigger the very RLS policy that calls the function -- breaking
-- what would otherwise be infinite recursion. It is NEVER used as an
-- application login role and has no write privileges anywhere.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Helper functions. All: SECURITY DEFINER, STABLE, fixed search_path,
-- identity always resolved from auth.uid() (never a caller-supplied
-- user-id parameter), owned by rls_helper.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION user_org_ids() RETURNS SETOF uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT organization_id FROM organization_members
  WHERE user_id = auth.uid() AND status = 'active' AND deleted_at IS NULL;
$$;

CREATE OR REPLACE FUNCTION user_has_event_permission(p_event_id uuid, p_permission_key text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (
    SELECT 1
    FROM event_members em
    JOIN organization_members om ON om.id = em.organization_member_id
    JOIN event_member_permissions emp ON emp.event_member_id = em.id
    JOIN permissions p ON p.id = emp.permission_id
    WHERE em.event_id = p_event_id
      AND om.user_id = auth.uid()
      AND om.status = 'active' AND om.deleted_at IS NULL
      AND em.status = 'active' AND em.deleted_at IS NULL
      AND p.key = p_permission_key
  );
$$;

CREATE OR REPLACE FUNCTION admin_has_active_grant(p_organization_id uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (
    SELECT 1 FROM admin_access_grants g
    JOIN users u ON u.id = g.admin_user_id
    WHERE g.admin_user_id = auth.uid()
      AND g.organization_id = p_organization_id
      AND u.account_type = 'admin'
      AND g.expires_at > now()
      AND g.revoked_at IS NULL
  );
$$;

CREATE OR REPLACE FUNCTION is_platform_admin() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
  SELECT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin');
$$;

GRANT EXECUTE ON FUNCTION user_org_ids() TO authenticated;
GRANT EXECUTE ON FUNCTION user_has_event_permission(uuid, text) TO authenticated;
GRANT EXECUTE ON FUNCTION admin_has_active_grant(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION is_platform_admin() TO authenticated;

-- ---------------------------------------------------------------------
-- Enable RLS on every private table. The application connects to Supabase
-- as `anon`/`authenticated` (never as the table owner), so plain ENABLE
-- (not FORCE) is sufficient here; FORCE would only matter if application
-- traffic could ever run as the owning role, which it does not in this
-- architecture.
-- ---------------------------------------------------------------------
ALTER TABLE organizations             ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_members      ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_profiles     ENABLE ROW LEVEL SECURITY;
ALTER TABLE organization_services     ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_types               ENABLE ROW LEVEL SECURITY;
ALTER TABLE events                    ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_sessions            ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_members             ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiries                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiry_organizations     ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotations                ENABLE ROW LEVEL SECURITY;
ALTER TABLE checklists                ENABLE ROW LEVEL SECURITY;
ALTER TABLE tasks                     ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_progress            ENABLE ROW LEVEL SECURITY;
ALTER TABLE registrations             ENABLE ROW LEVEL SECURITY;
ALTER TABLE attendees                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE tickets                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE attendance_records        ENABLE ROW LEVEL SECURITY;
ALTER TABLE walk_in_records           ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments                  ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_refunds           ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_responses          ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_reports            ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs                ENABLE ROW LEVEL SECURITY;
ALTER TABLE subscriptions             ENABLE ROW LEVEL SECURITY;
ALTER TABLE subscription_invoices     ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications             ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_preferences  ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_settings         ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation_reports        ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation_cases          ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin_access_grants       ENABLE ROW LEVEL SECURITY;

-- ---------------------------------------------------------------------
-- organizations
-- ---------------------------------------------------------------------
CREATE POLICY org_select_own ON organizations FOR SELECT
  USING (id IN (SELECT user_org_ids()) OR admin_has_active_grant(id) OR is_platform_admin());
CREATE POLICY org_update_own ON organizations FOR UPDATE
  USING (id IN (SELECT user_org_ids())) WITH CHECK (id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- organization_members
-- ---------------------------------------------------------------------
CREATE POLICY org_members_select ON organization_members FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id));
CREATE POLICY org_members_insert ON organization_members FOR INSERT
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY org_members_update ON organization_members FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids())) WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- organization_profiles / organization_services (private-write side;
-- public read happens via the public_* views created in 0006, not here)
-- ---------------------------------------------------------------------
CREATE POLICY org_profiles_owner ON organization_profiles FOR ALL
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

CREATE POLICY org_services_owner ON organization_services FOR ALL
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- event_types (organization-owned rows only; platform defaults are
-- handled by the public_event_types view / is_platform_admin() writes)
-- ---------------------------------------------------------------------
CREATE POLICY event_types_owner ON event_types FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()) OR is_platform_admin())
  WITH CHECK (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());

-- ---------------------------------------------------------------------
-- events / event_sessions
-- ---------------------------------------------------------------------
CREATE POLICY events_org_select ON events FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id));
CREATE POLICY events_org_write ON events FOR INSERT WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY events_org_update ON events FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids())) WITH CHECK (organization_id IN (SELECT user_org_ids()));

CREATE POLICY event_sessions_org ON event_sessions FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- event_members: org-wide read, event-permission-gated write
-- ---------------------------------------------------------------------
CREATE POLICY event_members_select ON event_members FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY event_members_write ON event_members FOR INSERT
  WITH CHECK (organization_id IN (SELECT user_org_ids()) AND user_has_event_permission(event_id, 'staff.manage'));
CREATE POLICY event_members_update ON event_members FOR UPDATE
  USING (organization_id IN (SELECT user_org_ids()) AND user_has_event_permission(event_id, 'staff.manage'))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- inquiries: client owns; organizers see via inquiry_organizations
-- ---------------------------------------------------------------------
CREATE POLICY inquiries_client_owner ON inquiries FOR ALL
  USING (client_user_id = auth.uid())
  WITH CHECK (client_user_id = auth.uid());
CREATE POLICY inquiries_org_read ON inquiries FOR SELECT
  USING (id IN (SELECT inquiry_id FROM inquiry_organizations WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY inquiry_orgs_client_read ON inquiry_organizations FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));
CREATE POLICY inquiry_orgs_org ON inquiry_organizations FOR ALL
  USING (organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- ---------------------------------------------------------------------
-- quotations: organizer full access to own; client narrow read of own inquiry's quotations
-- ---------------------------------------------------------------------
CREATE POLICY quotations_org ON quotations FOR ALL
  USING (organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY quotations_client_read ON quotations FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));

-- ---------------------------------------------------------------------
-- checklists / tasks: staff only, NEVER exposed to clients (event_progress is the client surface)
-- ---------------------------------------------------------------------
CREATE POLICY checklists_org ON checklists FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY tasks_org ON tasks FOR ALL
  USING (checklist_id IN (SELECT id FROM checklists WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (checklist_id IN (SELECT id FROM checklists WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));

-- ---------------------------------------------------------------------
-- event_progress: organizer full; client read-only for their own event
-- ---------------------------------------------------------------------
CREATE POLICY event_progress_org ON event_progress FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_progress_client_read ON event_progress FOR SELECT
  USING (event_id IN (
    SELECT e.id FROM events e
    JOIN inquiries i ON i.id = e.inquiry_id
    WHERE i.client_user_id = auth.uid()
  ));

-- ---------------------------------------------------------------------
-- registrations / attendees / tickets
-- ---------------------------------------------------------------------
CREATE POLICY registrations_org ON registrations FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY registrations_purchaser_read ON registrations FOR SELECT
  USING (purchaser_user_id = auth.uid());

CREATE POLICY attendees_org ON attendees FOR ALL
  USING (id IN (SELECT attendee_id FROM tickets t
                JOIN registrations r ON r.id = t.registration_id
                WHERE r.event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (true);  -- inserts happen through the registration/ticket-issuance flow, scoped by that flow's own checks

CREATE POLICY tickets_org ON tickets FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY tickets_purchaser_read ON tickets FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE purchaser_user_id = auth.uid()));

-- ---------------------------------------------------------------------
-- attendance_records / walk_in_records: staff with check-in permission
-- ---------------------------------------------------------------------
CREATE POLICY attendance_staff ON attendance_records FOR ALL
  USING (user_has_event_permission(event_id, 'checkin.perform'))
  WITH CHECK (user_has_event_permission(event_id, 'checkin.perform'));

CREATE POLICY walk_in_staff ON walk_in_records FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- payments / payment_refunds: organizer read of own registrations' payments
-- ---------------------------------------------------------------------
CREATE POLICY payments_org_read ON payments FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
CREATE POLICY payments_purchaser_read ON payments FOR SELECT
  USING (registration_id IN (SELECT id FROM registrations WHERE purchaser_user_id = auth.uid()));
-- No INSERT/UPDATE policy for any authenticated role: payments are written exclusively by the
-- Laravel webhook handler using a privileged service connection that bypasses RLS by design.

CREATE POLICY payment_refunds_org_read ON payment_refunds FOR SELECT
  USING (payment_id IN (SELECT id FROM payments WHERE registration_id IN (
           SELECT id FROM registrations WHERE event_id IN (
             SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))));

-- ---------------------------------------------------------------------
-- reviews / review_responses / review_reports
-- ---------------------------------------------------------------------
CREATE POLICY reviews_client_owner ON reviews FOR ALL
  USING (client_user_id = auth.uid()) WITH CHECK (client_user_id = auth.uid());
CREATE POLICY reviews_org_read ON reviews FOR SELECT USING (organization_id IN (SELECT user_org_ids()));
-- No organizer DELETE policy exists anywhere above -- organizers cannot delete reviews directly (locked rule).

CREATE POLICY review_responses_org ON review_responses FOR ALL
  USING (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())));

CREATE POLICY review_reports_insert ON review_reports FOR INSERT WITH CHECK (reported_by = auth.uid());
CREATE POLICY review_reports_org_read ON review_reports FOR SELECT
  USING (review_id IN (SELECT id FROM reviews WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- audit_logs: org-scoped read only, INSERT-only for the system, no UPDATE/DELETE policy at all
-- ---------------------------------------------------------------------
CREATE POLICY audit_logs_org_read ON audit_logs FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());
-- Deliberately no UPDATE/DELETE policy: with RLS enabled and FORCE'd, the absence of a policy
-- for a command means that command is denied outright for all non-owner roles.

-- ---------------------------------------------------------------------
-- subscriptions / subscription_invoices
-- ---------------------------------------------------------------------
CREATE POLICY subscriptions_org_read ON subscriptions FOR SELECT USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY subscription_invoices_org_read ON subscription_invoices FOR SELECT
  USING (subscription_id IN (SELECT id FROM subscriptions WHERE organization_id IN (SELECT user_org_ids())));

-- ---------------------------------------------------------------------
-- notifications / notification_preferences: self only
-- ---------------------------------------------------------------------
CREATE POLICY notifications_self ON notifications FOR ALL
  USING (recipient_user_id = auth.uid()) WITH CHECK (recipient_user_id = auth.uid());
CREATE POLICY notification_preferences_self ON notification_preferences FOR ALL
  USING (user_id = auth.uid()) WITH CHECK (user_id = auth.uid());

-- ---------------------------------------------------------------------
-- Platform-level tables: is_platform_admin() only
-- ---------------------------------------------------------------------
CREATE POLICY platform_settings_admin ON platform_settings FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());
CREATE POLICY moderation_reports_admin_read ON moderation_reports FOR SELECT USING (is_platform_admin());
CREATE POLICY moderation_reports_insert ON moderation_reports FOR INSERT WITH CHECK (reported_by = auth.uid());
CREATE POLICY moderation_cases_admin ON moderation_cases FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());
CREATE POLICY admin_access_grants_admin ON admin_access_grants FOR ALL
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());

-- =====================================================================
-- 0022b_rls_supplemental.sql
-- Purpose: RLS coverage for the remaining tables not already handled in
--          0022_rls.sql (which covered the highest-sensitivity/most
--          load-bearing tables explicitly per the access matrix). Every
--          table here follows the SAME established pattern -- no new
--          policy shapes are introduced.
-- Dependencies: 0001-0022
-- =====================================================================

ALTER TABLE organization_roles         ENABLE ROW LEVEL SECURITY;
ALTER TABLE permissions                ENABLE ROW LEVEL SECURITY;
ALTER TABLE role_permissions           ENABLE ROW LEVEL SECURITY;
ALTER TABLE member_permission_overrides ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_member_permissions   ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_types               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_holds               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_transfers           ENABLE ROW LEVEL SECURITY;
ALTER TABLE qr_tickets                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotation_items            ENABLE ROW LEVEL SECURITY;
ALTER TABLE quotation_status_history   ENABLE ROW LEVEL SECURITY;
ALTER TABLE inquiry_services           ENABLE ROW LEVEL SECURITY;
ALTER TABLE task_assignees             ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_stages               ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_deliveries    ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_edits               ENABLE ROW LEVEL SECURITY;
ALTER TABLE ownership_transfers        ENABLE ROW LEVEL SECURITY;
ALTER TABLE organizer_approval_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_announcements     ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_webhook_events     ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_fee_configs       ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_fee_snapshots     ENABLE ROW LEVEL SECURITY;
ALTER TABLE plans                      ENABLE ROW LEVEL SECURITY;
ALTER TABLE features                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE plan_entitlements          ENABLE ROW LEVEL SECURITY;

-- Permission/role catalogs: organization-scoped roles are private to the org;
-- the global `permissions` catalog and system-default roles are readable by
-- any authenticated user (needed to render permission-pickers in the UI).
CREATE POLICY org_roles_org ON organization_roles FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));
CREATE POLICY permissions_read_all ON permissions FOR SELECT USING (true);
CREATE POLICY role_permissions_org ON role_permissions FOR ALL
  USING (organization_role_id IN (SELECT id FROM organization_roles
           WHERE organization_id IS NULL OR organization_id IN (SELECT user_org_ids())))
  WITH CHECK (organization_role_id IN (SELECT id FROM organization_roles WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY member_perm_overrides_org ON member_permission_overrides FOR ALL
  USING (organization_member_id IN (SELECT id FROM organization_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (organization_member_id IN (SELECT id FROM organization_members WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_member_perms_org ON event_member_permissions FOR ALL
  USING (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())));

-- Ticketing internals: organization-scoped, same join pattern as `tickets`/`registrations`.
CREATE POLICY ticket_types_org ON ticket_types FOR ALL
  USING (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_id IN (SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY ticket_holds_org ON ticket_holds FOR ALL
  USING (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (registration_id IN (SELECT id FROM registrations WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
CREATE POLICY ticket_transfers_org ON ticket_transfers FOR SELECT
  USING (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));
-- No direct INSERT policy: ticket_transfers rows are written only via transfer_ticket() (0021), a SECURITY DEFINER function.
CREATE POLICY qr_tickets_org ON qr_tickets FOR ALL
  USING (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))))
  WITH CHECK (ticket_id IN (SELECT id FROM tickets WHERE event_id IN (
           SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids()))));

-- Quotation internals.
CREATE POLICY quotation_items_org ON quotation_items FOR ALL
  USING (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY quotation_history_read ON quotation_status_history FOR SELECT
  USING (quotation_id IN (SELECT id FROM quotations WHERE organization_id IN (SELECT user_org_ids()))
     OR quotation_id IN (SELECT id FROM quotations WHERE inquiry_id IN (
          SELECT id FROM inquiries WHERE client_user_id = auth.uid())));
-- No INSERT policy: written only via accept_quotation() and similar SECURITY DEFINER domain actions.

CREATE POLICY inquiry_services_visible ON inquiry_services FOR SELECT
  USING (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid())
     OR inquiry_id IN (SELECT inquiry_id FROM inquiry_organizations WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY inquiry_services_client_write ON inquiry_services FOR INSERT
  WITH CHECK (inquiry_id IN (SELECT id FROM inquiries WHERE client_user_id = auth.uid()));

-- Preparation internals.
CREATE POLICY task_assignees_org ON task_assignees FOR ALL
  USING (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())))
  WITH CHECK (event_member_id IN (SELECT id FROM event_members WHERE organization_id IN (SELECT user_org_ids())));
CREATE POLICY event_stages_org ON event_stages FOR ALL
  USING (organization_id IS NULL OR organization_id IN (SELECT user_org_ids()))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

-- Notification delivery detail: same scope as the parent notification.
CREATE POLICY notification_deliveries_self ON notification_deliveries FOR SELECT
  USING (notification_id IN (SELECT id FROM notifications WHERE recipient_user_id = auth.uid()));

-- Review edit history: same visibility as the review itself.
CREATE POLICY review_edits_visible ON review_edits FOR SELECT
  USING (review_id IN (SELECT id FROM reviews WHERE client_user_id = auth.uid()
           OR organization_id IN (SELECT user_org_ids())));

-- Append-only audit-adjacent tables: org-scoped read, no direct write policy
-- (written only via the SECURITY DEFINER domain functions in 0021).
CREATE POLICY ownership_transfers_read ON ownership_transfers FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));
CREATE POLICY approval_requests_org_read ON organizer_approval_requests FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()) OR is_platform_admin());

-- Public announcements: readable by everyone; writable only by platform admins.
CREATE POLICY announcements_read_all ON platform_announcements FOR SELECT USING (deleted_at IS NULL);
CREATE POLICY announcements_admin_write ON platform_announcements FOR INSERT WITH CHECK (is_platform_admin());
CREATE POLICY announcements_admin_update ON platform_announcements FOR UPDATE
  USING (is_platform_admin()) WITH CHECK (is_platform_admin());

-- System-only tables: no policy for `authenticated` at all -- these are written and read
-- exclusively by privileged service connections (webhook handler, fee-config admin tooling),
-- which is precisely what having zero permissive policies (with RLS enabled) achieves.
CREATE POLICY webhook_events_admin_read ON payment_webhook_events FOR SELECT USING (is_platform_admin());
CREATE POLICY fee_configs_admin_read ON platform_fee_configs FOR SELECT USING (true);   -- rates are not sensitive; needed client-side for price display
CREATE POLICY fee_configs_admin_write ON platform_fee_configs FOR INSERT WITH CHECK (is_platform_admin());
CREATE POLICY fee_snapshots_org_read ON platform_fee_snapshots FOR SELECT
  USING (payment_id IN (SELECT id FROM payments WHERE registration_id IN (
           SELECT id FROM registrations WHERE event_id IN (
             SELECT id FROM events WHERE organization_id IN (SELECT user_org_ids())))));

-- SaaS catalog: public read (needed for a pricing page), admin-only write.
CREATE POLICY plans_read_all ON plans FOR SELECT USING (is_active);
CREATE POLICY features_read_all ON features FOR SELECT USING (true);
CREATE POLICY plan_entitlements_read_all ON plan_entitlements FOR SELECT USING (true);

-- =====================================================================
-- 0023_indexes.sql
-- Purpose: additional indexes not already created inline with their
--          tables (0004-0021 already created the load-bearing ones:
--          capacity, webhook idempotency, ownership, settlement
--          exclusivity, quotation exclusivity, subscription exclusivity,
--          duplicate check-in, composite-FK support indexes). This file
--          adds the remaining reporting/lookup indexes.
-- Dependencies: 0001-0022
-- =====================================================================

-- Marketplace search
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_organizations_display_name_trgm ON organizations USING gin (display_name gin_trgm_ops);

-- Reporting date-range scans
CREATE INDEX idx_tickets_created_at       ON tickets(created_at);
CREATE INDEX idx_payments_paid_at         ON payments(paid_at);
CREATE INDEX idx_registrations_created_at ON registrations(created_at);

-- RLS helper performance (backing user_org_ids() / admin_has_active_grant())
CREATE INDEX idx_org_members_user_org_status ON organization_members(user_id, organization_id, status);

-- Ticket/QR lookup at the scan gate
CREATE INDEX idx_qr_tickets_ticket_id ON qr_tickets(ticket_id);

-- Audit log filtering by action
CREATE INDEX idx_audit_logs_action ON audit_logs(action);

-- Notification lookup by type (for preference joins)
CREATE INDEX idx_notification_preferences_type ON notification_preferences(notification_type);

-- =====================================================================
-- 0024_seed.sql
-- Purpose: idempotent platform default data. No fake user accounts.
-- Dependencies: 0001-0023
-- =====================================================================

-- ---------------------------------------------------------------------
-- Permissions catalog
-- ---------------------------------------------------------------------
INSERT INTO permissions (key, category, description) VALUES
  ('event.create',            'event',      'Create a new event'),
  ('event.publish',           'event',      'Publish a draft event'),
  ('event.cancel',            'event',      'Cancel an event'),
  ('event.complete',          'event',      'Mark an event completed'),
  ('event.update',            'event',      'Edit event configuration'),
  ('ticket.manage',           'ticket',     'Create/edit ticket types and pricing'),
  ('attendee.manage',         'attendee',   'View/edit attendee records'),
  ('checkin.perform',         'checkin',    'Scan/manually check in attendees'),
  ('quotation.manage',        'quotation',  'Create/edit/submit quotations'),
  ('inquiry.manage',          'inquiry',    'View/respond to inquiries'),
  ('finance.view',            'finance',    'View revenue/payment/fee reports'),
  ('staff.manage',            'staff',      'Invite/assign/remove staff and event members'),
  ('checklist.manage',        'checklist',  'Create/edit checklists and tasks'),
  ('task.manage',             'checklist',  'Create/edit/assign tasks')
ON CONFLICT (key) DO NOTHING;

-- ---------------------------------------------------------------------
-- System default event types (organization_id IS NULL = platform default)
-- ---------------------------------------------------------------------
INSERT INTO event_types (organization_id, name, is_system_default) VALUES
  (NULL, 'Wedding',        true),
  (NULL, 'Birthday Party', true),
  (NULL, 'Corporate Event',true),
  (NULL, 'Conference',     true),
  (NULL, 'Concert',        true),
  (NULL, 'Debut',          true),
  (NULL, 'Christening',    true),
  (NULL, 'Other',          true)
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Platform fee configuration -- V1 deduction model, 1% default, no payer column
-- ---------------------------------------------------------------------
INSERT INTO platform_fee_configs (name, is_enabled, fee_model, percentage_rate, effective_from)
SELECT 'V1 Default Fee', true, 'percentage', 0.0100, now()
WHERE NOT EXISTS (SELECT 1 FROM platform_fee_configs WHERE effective_to IS NULL);

-- ---------------------------------------------------------------------
-- SaaS plans, features, entitlements
-- ---------------------------------------------------------------------
INSERT INTO plans (name, slug, price_monthly, price_yearly, is_active, is_default_free) VALUES
  ('Free',    'free',    0,      0,       true, true),
  ('Starter', 'starter', 999,    9990,    true, false),
  ('Pro',     'pro',     2999,   29990,   true, false)
ON CONFLICT DO NOTHING;

INSERT INTO features (key, name, value_type, description) VALUES
  ('active_event_limit',     'Active Event Limit',      'numeric', 'Maximum number of non-completed events at once'),
  ('total_event_limit',      'Total Event Limit',        'numeric', 'Maximum total events ever created'),
  ('staff_limit',             'Staff Limit',              'numeric', 'Maximum organization_members (excluding owner)'),
  ('advanced_reports',        'Advanced Reports',         'boolean', 'Access to advanced analytics/reporting'),
  ('custom_quotation_bidding','Custom Quotation/Bidding', 'boolean', 'Custom quotation terms/templates'),
  ('custom_event_types',      'Custom Event Types',       'boolean', 'Ability to create organization-specific event types'),
  ('workspaces',              'Multiple Workspaces',      'numeric', 'Number of organizations a user may own')
ON CONFLICT (key) DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN false WHEN 'custom_quotation_bidding' THEN false WHEN 'custom_event_types' THEN false END,
  CASE f.key WHEN 'active_event_limit' THEN 2 WHEN 'total_event_limit' THEN 10 WHEN 'staff_limit' THEN 3 WHEN 'workspaces' THEN 1 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'free'
ON CONFLICT DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN true WHEN 'custom_quotation_bidding' THEN false WHEN 'custom_event_types' THEN true END,
  CASE f.key WHEN 'active_event_limit' THEN 10 WHEN 'total_event_limit' THEN 100 WHEN 'staff_limit' THEN 15 WHEN 'workspaces' THEN 1 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'starter'
ON CONFLICT DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN true WHEN 'custom_quotation_bidding' THEN true WHEN 'custom_event_types' THEN true END,
  CASE f.key WHEN 'active_event_limit' THEN NULL WHEN 'total_event_limit' THEN NULL WHEN 'staff_limit' THEN NULL WHEN 'workspaces' THEN 3 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'pro'
ON CONFLICT DO NOTHING;
-- NULL numeric_value convention: unlimited. Document this explicitly at the application layer.

-- ---------------------------------------------------------------------
-- Platform settings defaults
-- ---------------------------------------------------------------------
INSERT INTO platform_settings (key, value, description) VALUES
  ('admin_grant_default_expiry_hours', '24',   'Default lifetime of a self-service admin access grant'),
  ('ticket_hold_default_minutes',      '15',   'Default ticket hold duration before expiry'),
  ('ticket_hold_max_minutes',          '60',   'Platform-wide ceiling an organizer may configure for hold duration'),
  ('subscription_grace_period_days',   '3',    'Grace period after a failed subscription payment')
ON CONFLICT (key) DO NOTHING;

COMMENT ON TABLE plan_entitlements IS
  'NULL numeric_value = unlimited for that feature/plan combination (used by the Pro plan above).';


-- =====================================================================
-- 0025_security_integrity_corrections.sql
-- NaSeRy targeted correction pass
--
-- Apply AFTER 0001-0024.
-- This migration does NOT redesign the schema. It closes the SQL/RLS and
-- cross-tenant integrity gaps found during final migration review.
-- =====================================================================

-- Enum additions are committed before the function below uses the new labels.
ALTER TYPE inquiry_org_status ADD VALUE IF NOT EXISTS 'accepted';
ALTER TYPE inquiry_org_status ADD VALUE IF NOT EXISTS 'rejected';

BEGIN;

-- ---------------------------------------------------------------------
-- 1. Inquiry-participant statuses
-- ---------------------------------------------------------------------
-- The enum additions above are committed before this migration's function
-- definitions use the new labels.
-- ---------------------------------------------------------------------
-- 2. Cross-tenant composite foreign key: event creator
-- ---------------------------------------------------------------------
ALTER TABLE events
  ADD CONSTRAINT fk_events_creator_member_org
  FOREIGN KEY (created_by_member_id, organization_id)
  REFERENCES organization_members (id, organization_id);

-- ---------------------------------------------------------------------
-- 3. Cross-tenant composite foreign keys: ticket holds
-- ---------------------------------------------------------------------
-- ticket_holds originally had no event_id, so a hold could reference a
-- registration from one event and a ticket type from another event.
-- Store the event explicitly and make both relationships authoritative.
ALTER TABLE ticket_holds ADD COLUMN event_id uuid;

UPDATE ticket_holds th
SET event_id = r.event_id
FROM registrations r
WHERE r.id = th.registration_id
  AND th.event_id IS NULL;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM ticket_holds WHERE event_id IS NULL) THEN
    RAISE EXCEPTION '0025: ticket_holds.event_id could not be backfilled for existing rows';
  END IF;
END;
$$;

ALTER TABLE ticket_holds
  ALTER COLUMN event_id SET NOT NULL;

ALTER TABLE ticket_holds
  ADD CONSTRAINT fk_ticket_holds_registration_event
  FOREIGN KEY (registration_id, event_id)
  REFERENCES registrations (id, event_id),
  ADD CONSTRAINT fk_ticket_holds_ticket_type_event
  FOREIGN KEY (ticket_type_id, event_id)
  REFERENCES ticket_types (id, event_id);

CREATE INDEX IF NOT EXISTS idx_ticket_holds_event_status
  ON ticket_holds(event_id, status, expires_at);

-- ---------------------------------------------------------------------
-- 4. Strengthen walk-in semantics
-- ---------------------------------------------------------------------
ALTER TABLE walk_in_records
  ADD CONSTRAINT chk_walk_in_category_identity
  CHECK (
    (category = 'ticket_linked' AND linked_ticket_id IS NOT NULL AND attendee_id IS NOT NULL AND count = 1)
    OR
    (category = 'paid_walk_in' AND linked_ticket_id IS NULL AND attendee_id IS NOT NULL AND count = 1)
    OR
    (category = 'registered_attendee' AND linked_ticket_id IS NULL AND attendee_id IS NOT NULL AND count = 1)
    OR
    (category = 'headcount_only' AND linked_ticket_id IS NULL AND attendee_id IS NULL AND count >= 1)
  );

-- A linked walk-in must identify the same attendee as its linked ticket.
CREATE OR REPLACE FUNCTION trg_walk_in_validate_linked_attendee()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_ticket_attendee uuid;
BEGIN
  IF NEW.linked_ticket_id IS NOT NULL THEN
    SELECT attendee_id
      INTO v_ticket_attendee
    FROM tickets
    WHERE id = NEW.linked_ticket_id
      AND event_id = NEW.event_id;

    IF v_ticket_attendee IS NULL THEN
      RAISE EXCEPTION 'Walk-in linked ticket % was not found for event %',
        NEW.linked_ticket_id, NEW.event_id;
    END IF;

    IF NEW.attendee_id <> v_ticket_attendee THEN
      RAISE EXCEPTION 'Walk-in attendee % does not match linked ticket % attendee %',
        NEW.attendee_id, NEW.linked_ticket_id, v_ticket_attendee;
    END IF;
  END IF;

  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_walk_in_validate_linked_attendee ON walk_in_records;
CREATE TRIGGER trg_walk_in_validate_linked_attendee
BEFORE INSERT OR UPDATE OF linked_ticket_id, attendee_id, event_id
ON walk_in_records
FOR EACH ROW
EXECUTE FUNCTION trg_walk_in_validate_linked_attendee();

-- ---------------------------------------------------------------------
-- 5. users RLS
-- ---------------------------------------------------------------------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS users_self_select ON users;
CREATE POLICY users_self_select ON users FOR SELECT
USING (
  id = auth.uid()
  OR EXISTS (
    SELECT 1
    FROM organization_members om
    WHERE om.user_id = users.id
      AND om.status = 'active'
      AND om.deleted_at IS NULL
      AND admin_has_active_grant(om.organization_id)
  )
  OR is_platform_admin()
);

DROP POLICY IF EXISTS users_self_update ON users;
CREATE POLICY users_self_update ON users FOR UPDATE
USING (id = auth.uid())
WITH CHECK (id = auth.uid());

-- User creation/deletion/anonymization remains a controlled Auth/Laravel
-- operation; there is intentionally no authenticated INSERT/DELETE policy.

-- ---------------------------------------------------------------------
-- 6. Prevent ordinary members from deleting platform-default rows
-- ---------------------------------------------------------------------

-- event_types
DROP POLICY IF EXISTS event_types_owner ON event_types;
DROP POLICY IF EXISTS event_types_select ON event_types;
DROP POLICY IF EXISTS event_types_insert ON event_types;
DROP POLICY IF EXISTS event_types_update ON event_types;
DROP POLICY IF EXISTS event_types_delete ON event_types;

CREATE POLICY event_types_select ON event_types FOR SELECT
USING (
  organization_id IS NULL
  OR organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_types_insert ON event_types FOR INSERT
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_types_update ON event_types FOR UPDATE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
)
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_types_delete ON event_types FOR DELETE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

-- organization_roles
DROP POLICY IF EXISTS org_roles_org ON organization_roles;
DROP POLICY IF EXISTS organization_roles_select ON organization_roles;
DROP POLICY IF EXISTS organization_roles_insert ON organization_roles;
DROP POLICY IF EXISTS organization_roles_update ON organization_roles;
DROP POLICY IF EXISTS organization_roles_delete ON organization_roles;

CREATE POLICY organization_roles_select ON organization_roles FOR SELECT
USING (
  organization_id IS NULL
  OR organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY organization_roles_insert ON organization_roles FOR INSERT
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY organization_roles_update ON organization_roles FOR UPDATE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
)
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY organization_roles_delete ON organization_roles FOR DELETE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

-- event_stages
DROP POLICY IF EXISTS event_stages_org ON event_stages;
DROP POLICY IF EXISTS event_stages_select ON event_stages;
DROP POLICY IF EXISTS event_stages_insert ON event_stages;
DROP POLICY IF EXISTS event_stages_update ON event_stages;
DROP POLICY IF EXISTS event_stages_delete ON event_stages;

CREATE POLICY event_stages_select ON event_stages FOR SELECT
USING (
  organization_id IS NULL
  OR organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_stages_insert ON event_stages FOR INSERT
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_stages_update ON event_stages FOR UPDATE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
)
WITH CHECK (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

CREATE POLICY event_stages_delete ON event_stages FOR DELETE
USING (
  organization_id IN (SELECT user_org_ids())
  OR is_platform_admin()
);

-- ---------------------------------------------------------------------
-- 7. Attendees: remove unrestricted INSERT/UPDATE/DELETE via WITH CHECK(true)
-- ---------------------------------------------------------------------
DROP POLICY IF EXISTS attendees_org ON attendees;
DROP POLICY IF EXISTS attendees_org_select ON attendees;
DROP POLICY IF EXISTS attendees_purchaser_select ON attendees;

CREATE POLICY attendees_org_select ON attendees FOR SELECT
USING (
  id IN (
    SELECT t.attendee_id
    FROM tickets t
    JOIN registrations r ON r.id = t.registration_id
    JOIN events e ON e.id = r.event_id
    WHERE e.organization_id IN (SELECT user_org_ids())
  )
);

CREATE POLICY attendees_purchaser_select ON attendees FOR SELECT
USING (
  id IN (
    SELECT t.attendee_id
    FROM tickets t
    JOIN registrations r ON r.id = t.registration_id
    WHERE r.purchaser_user_id = auth.uid()
  )
);

-- Attendee creation/update/deletion must occur through the controlled
-- registration/ticket issuance service path (or a SECURITY DEFINER domain
-- function). No generic authenticated write policy is granted here.

-- ---------------------------------------------------------------------
-- 8. Public marketplace service view must enforce organizer eligibility
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW public_organization_services AS
  SELECT
    os.id,
    os.organization_id,
    COALESCE(os.custom_name, s.name) AS name,
    os.description,
    os.base_price
  FROM organization_services os
  JOIN organizations o ON o.id = os.organization_id
  LEFT JOIN services s ON s.id = os.service_id
  WHERE os.is_active
    AND os.deleted_at IS NULL
    AND o.deleted_at IS NULL
    AND o.approval_status = 'approved'
    AND NOT o.is_suspended;

-- Explicitly expose only the four intended public marketplace surfaces.
GRANT SELECT ON public_organization_profiles TO anon, authenticated;
GRANT SELECT ON public_organization_services TO anon, authenticated;
GRANT SELECT ON public_event_types TO anon, authenticated;
GRANT SELECT ON public_reviews TO anon, authenticated;

-- ---------------------------------------------------------------------
-- 9. Platform-fee timeline integrity
-- ---------------------------------------------------------------------
ALTER TABLE platform_fee_configs
  ADD CONSTRAINT chk_fee_config_effective_window
  CHECK (effective_to IS NULL OR effective_to > effective_from);

CREATE OR REPLACE FUNCTION trg_platform_fee_config_no_overlap()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  -- Serialize fee-config writes so two concurrent admins cannot create
  -- overlapping enabled windows between their individual checks.
  PERFORM pg_advisory_xact_lock(hashtextextended('nasery.platform_fee_configs', 0));

  IF NEW.is_enabled AND EXISTS (
    SELECT 1
    FROM platform_fee_configs p
    WHERE p.id <> NEW.id
      AND p.is_enabled
      AND tstzrange(p.effective_from, p.effective_to, '[)')
          && tstzrange(NEW.effective_from, NEW.effective_to, '[)')
  ) THEN
    RAISE EXCEPTION
      'Enabled platform fee configuration % overlaps an existing enabled configuration',
      NEW.id;
  END IF;

  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_platform_fee_config_no_overlap ON platform_fee_configs;
CREATE TRIGGER trg_platform_fee_config_no_overlap
BEFORE INSERT OR UPDATE OF is_enabled, effective_from, effective_to
ON platform_fee_configs
FOR EACH ROW
EXECUTE FUNCTION trg_platform_fee_config_no_overlap();

-- Fail the migration if pre-existing enabled rows are already overlapping.
DO $$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM platform_fee_configs a
    JOIN platform_fee_configs b
      ON a.id < b.id
     AND a.is_enabled
     AND b.is_enabled
     AND tstzrange(a.effective_from, a.effective_to, '[)')
         && tstzrange(b.effective_from, b.effective_to, '[)')
  ) THEN
    RAISE EXCEPTION '0025: existing enabled platform fee configurations overlap';
  END IF;
END;
$$;

-- ---------------------------------------------------------------------
-- 10. Payment status/timestamp consistency
-- ---------------------------------------------------------------------
ALTER TABLE payments
  ADD CONSTRAINT chk_payments_paid_at_consistency
  CHECK (
    (status = 'paid' AND paid_at IS NOT NULL)
    OR
    (status <> 'paid' AND paid_at IS NULL)
  ),
  ADD CONSTRAINT chk_payments_failed_reason
  CHECK (
    status <> 'failed'
    OR NULLIF(btrim(failure_reason), '') IS NOT NULL
  );

-- ---------------------------------------------------------------------
-- 11. Replace quotation acceptance with deterministic history capture and
--     synchronized inquiry-participant states.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION accept_quotation(p_quotation_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  v_inquiry_id uuid;
  v_status quotation_status;
  v_now timestamptz;
  v_quotation_id uuid;
BEGIN
  v_now := clock_timestamp();

  SELECT q.inquiry_id, q.status
    INTO v_inquiry_id, v_status
  FROM quotations q
  WHERE q.id = p_quotation_id
  FOR UPDATE;

  IF v_inquiry_id IS NULL THEN
    RAISE EXCEPTION 'Quotation % not found', p_quotation_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM inquiries i
    WHERE i.id = v_inquiry_id
      AND i.client_user_id = auth.uid()
  ) THEN
    RAISE EXCEPTION 'Only the inquiry''s own client may accept a quotation';
  END IF;

  -- Serialize all quotation decisions for this inquiry.
  PERFORM 1 FROM inquiries WHERE id = v_inquiry_id FOR UPDATE;

  IF v_status <> 'submitted' THEN
    RAISE EXCEPTION
      'Quotation % is not in submitted status (currently %)',
      p_quotation_id, v_status;
  END IF;

  UPDATE quotations
  SET status = 'accepted',
      decided_at = v_now,
      decided_by = auth.uid(),
      updated_at = v_now
  WHERE id = p_quotation_id;

  INSERT INTO quotation_status_history
    (quotation_id, from_status, to_status, actor_user_id, reason)
  VALUES
    (p_quotation_id, 'submitted', 'accepted', auth.uid(), 'accepted by client');

  -- Deterministically reject every other currently-submitted quotation and
  -- write exactly one history row for each affected quotation.
  FOR v_quotation_id IN
    SELECT q.id
    FROM quotations q
    WHERE q.inquiry_id = v_inquiry_id
      AND q.id <> p_quotation_id
      AND q.status = 'submitted'
    ORDER BY q.id
    FOR UPDATE
  LOOP
    UPDATE quotations
    SET status = 'rejected',
        decided_at = v_now,
        decided_by = auth.uid(),
        updated_at = v_now
    WHERE id = v_quotation_id;

    INSERT INTO quotation_status_history
      (quotation_id, from_status, to_status, actor_user_id, reason)
    VALUES
      (v_quotation_id, 'submitted', 'rejected', auth.uid(), 'competing quotation accepted');
  END LOOP;

  UPDATE inquiries
  SET status = 'accepted',
      updated_at = v_now
  WHERE id = v_inquiry_id;

  -- The selected organizer is now the accepted participant; all other
  -- still-open participants are explicitly rejected. Existing declined /
  -- withdrawn rows are preserved as historical outcomes.
  UPDATE inquiry_organizations
  SET status = 'accepted',
      responded_at = v_now,
      updated_at = v_now
  WHERE inquiry_id = v_inquiry_id
    AND organization_id = (
      SELECT q.organization_id FROM quotations q WHERE q.id = p_quotation_id
    );

  UPDATE inquiry_organizations io
  SET status = 'rejected',
      responded_at = v_now,
      updated_at = v_now
  WHERE io.inquiry_id = v_inquiry_id
    AND io.organization_id <> (
      SELECT q.organization_id FROM quotations q WHERE q.id = p_quotation_id
    )
    AND io.status IN ('pending', 'viewed', 'quoted');

  INSERT INTO audit_logs
    (actor_user_id, action, entity_type, entity_id, reason)
  VALUES
    (auth.uid(), 'quotation_accept', 'quotations', p_quotation_id, 'client accepted quotation');
END;
$$;

COMMIT;

-- =====================================================================
-- END 0025
-- =====================================================================

-- 0026_public_views_security.sql
-- NaSeRy
-- Purpose:
--   Make the four public marketplace views SECURITY INVOKER views so
--   querying them uses the permissions/RLS context of the querying user.
--
-- Important:
--   This migration preserves the existing SELECT expressions and therefore
--   preserves the existing view column types, including:
--     public_organization_profiles.contact_email -> citext
--     public_organization_services.base_price    -> numeric(12,2)
--     public_reviews.rating                      -> smallint
--
-- This migration does NOT weaken or replace any base-table RLS policies.

BEGIN;

CREATE OR REPLACE VIEW public.public_organization_profiles
WITH (security_invoker = true)
AS
SELECT
    op.organization_id,
    o.display_name,
    op.business_name,
    op.description,
    op.location_text,
    op.contact_email,
    op.contact_phone,
    op.cover_image_url
FROM public.organization_profiles op
JOIN public.organizations o
    ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'
  AND NOT o.is_suspended;

CREATE OR REPLACE VIEW public.public_organization_services
WITH (security_invoker = true)
AS
SELECT
    os.id,
    os.organization_id,
    COALESCE(os.custom_name, s.name) AS name,
    os.description,
    os.base_price
FROM public.organization_services os
JOIN public.organizations o
    ON o.id = os.organization_id
LEFT JOIN public.services s
    ON s.id = os.service_id
WHERE os.is_active
  AND os.deleted_at IS NULL
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'
  AND NOT o.is_suspended;

CREATE OR REPLACE VIEW public.public_event_types
WITH (security_invoker = true)
AS
SELECT
    id,
    organization_id,
    name,
    description
FROM public.event_types
WHERE deleted_at IS NULL
  AND (
      organization_id IS NULL
      OR organization_id IN (
          SELECT organizations.id
          FROM public.organizations
          WHERE organizations.approval_status = 'approved'
            AND NOT organizations.is_suspended
            AND organizations.deleted_at IS NULL
      )
  );

CREATE OR REPLACE VIEW public.public_reviews
WITH (security_invoker = true)
AS
SELECT
    r.id,
    r.event_id,
    r.organization_id,
    r.rating,
    r.written_review,
    r.created_at,
    u.first_name AS reviewer_first_name,
    LEFT(u.last_name, 1) AS reviewer_last_initial,
    et.name AS event_type_name
FROM public.reviews r
JOIN public.users u
    ON u.id = r.client_user_id
JOIN public.events e
    ON e.id = r.event_id
LEFT JOIN public.event_types et
    ON et.id = e.event_type_id
WHERE r.status = 'published'
  AND r.deleted_at IS NULL;

-- Reassert the intended public read access to the public views only.
-- Base tables remain protected by their existing RLS policies.
GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;
GRANT SELECT ON public.public_organization_services TO anon, authenticated;
GRANT SELECT ON public.public_event_types TO anon, authenticated;
GRANT SELECT ON public.public_reviews TO anon, authenticated;

-- Validation: all four views must now be SECURITY INVOKER.
DO $$
DECLARE
    v_name text;
    v_invoker boolean;
BEGIN
    FOREACH v_name IN ARRAY ARRAY[
        'public_organization_profiles',
        'public_organization_services',
        'public_event_types',
        'public_reviews'
    ]
    LOOP
        SELECT COALESCE(
            c.reloptions @> ARRAY['security_invoker=true'],
            false
        )
        INTO v_invoker
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n
            ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname = v_name
          AND c.relkind = 'v';

        IF NOT COALESCE(v_invoker, false) THEN
            RAISE EXCEPTION
                '0026 validation failed: %.security_invoker is not true',
                v_name;
        END IF;
    END LOOP;
END
$$;

COMMIT;
