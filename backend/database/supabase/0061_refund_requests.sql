-- =====================================================================
-- 0061_refund_requests.sql
-- NaSeRy
--
-- Apply AFTER 0060.
--
-- Purpose (mock defense, 2026-11): a buyer can ask for a refund of a paid
-- ticket purchase from My Tickets. The organizer refunds it (the existing
-- payment refund, 0014) or declines it with a note.
--
--   refund_requests   one request per payment at a time
--     status          pending | refunded | declined | withdrawn
--
--   Writes happen only in Laravel's logged system context after its own
--   checks. Reads under RLS: platform admins and the organization's members.
--
-- Rollback: 0061_refund_requests.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE refund_requests (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id       uuid NOT NULL REFERENCES payments(id),
  registration_id  uuid NOT NULL REFERENCES registrations(id),
  event_id         uuid NOT NULL REFERENCES events(id),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  requested_by     uuid NOT NULL REFERENCES users(id),
  reason           text NOT NULL CHECK (char_length(btrim(reason)) BETWEEN 3 AND 500),
  status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'refunded', 'declined', 'withdrawn')),
  response_note    text CHECK (response_note IS NULL OR char_length(response_note) <= 500),
  decided_by       uuid REFERENCES users(id),
  decided_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now()
);

-- One open request per payment.
CREATE UNIQUE INDEX uq_refund_requests_pending ON refund_requests (payment_id) WHERE status = 'pending';
CREATE INDEX idx_refund_requests_event ON refund_requests (event_id, created_at DESC);

ALTER TABLE refund_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY refund_requests_admin_read ON refund_requests FOR SELECT
  USING (is_platform_admin());

CREATE POLICY refund_requests_org_read ON refund_requests FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON refund_requests FROM PUBLIC, anon, authenticated;
GRANT SELECT ON refund_requests TO nasery_app;

COMMENT ON TABLE refund_requests IS
  'A buyer asking for a refund of a paid purchase: the organizer refunds or declines it.';

COMMIT;

-- =====================================================================
-- END 0061
-- =====================================================================
