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
