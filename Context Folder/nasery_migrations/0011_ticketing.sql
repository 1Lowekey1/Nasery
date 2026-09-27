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
