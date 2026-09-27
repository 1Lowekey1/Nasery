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
