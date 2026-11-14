-- =====================================================================
-- 0060_booking_cancellations.sql
-- NaSeRy
--
-- Apply AFTER 0059.
--
-- Purpose (user, 2026-11, "option 2"): backing out after an offer is accepted.
-- Either side asks to cancel with a reason. The other side agrees or keeps the
-- booking. No reply by respond_by counts as agreeing (the wait is the admin's
-- cancellation_grace_hours setting).
--
--   booking_cancellations   one request
--     requested_by_side   client | organizer
--     status              pending | agreed | auto_agreed | declined | withdrawn
--
--   Writes happen only in Laravel's logged system context after its own
--   checks. Reads under RLS: platform admins and the organization's members.
--
-- Rollback: 0060_booking_cancellations.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE booking_cancellations (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id         uuid NOT NULL REFERENCES inquiries(id),
  organization_id    uuid NOT NULL REFERENCES organizations(id),
  requested_by       uuid NOT NULL REFERENCES users(id),
  requested_by_side  text NOT NULL CHECK (requested_by_side IN ('client', 'organizer')),
  reason             text NOT NULL CHECK (char_length(btrim(reason)) BETWEEN 3 AND 500),
  status             text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'agreed', 'auto_agreed', 'declined', 'withdrawn')),
  respond_by         timestamptz NOT NULL,
  responded_by       uuid REFERENCES users(id),
  response_note      text CHECK (response_note IS NULL OR char_length(response_note) <= 500),
  decided_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now()
);

-- One open request per booking.
CREATE UNIQUE INDEX uq_booking_cancellations_pending ON booking_cancellations (inquiry_id) WHERE status = 'pending';
CREATE INDEX idx_booking_cancellations_inquiry ON booking_cancellations (inquiry_id, created_at DESC);
CREATE INDEX idx_booking_cancellations_due ON booking_cancellations (respond_by) WHERE status = 'pending';

ALTER TABLE booking_cancellations ENABLE ROW LEVEL SECURITY;

CREATE POLICY booking_cancellations_admin_read ON booking_cancellations FOR SELECT
  USING (is_platform_admin());

CREATE POLICY booking_cancellations_org_read ON booking_cancellations FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON booking_cancellations FROM PUBLIC, anon, authenticated;
GRANT SELECT ON booking_cancellations TO nasery_app;

COMMENT ON TABLE booking_cancellations IS
  'Requests to cancel an accepted booking: the other side agrees, declines, or lets the time limit pass.';

COMMIT;

-- =====================================================================
-- END 0060
-- =====================================================================
