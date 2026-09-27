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
