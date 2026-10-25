-- =====================================================================
-- 0043_organization_payouts.sql
-- NaSeRy
--
-- Apply AFTER 0042.
--
-- Purpose (organizer payouts, 2026-09-29): online ticket payments land in
-- NaSeRy's PayMongo account. Each organizer's share (ticket sales minus
-- refunds to buyers minus NaSeRy's platform fee) is paid out by NaSeRy
-- (bank transfer, GCash, Maya...). Admins record every payout here so the
-- organizer sees "paid out" and "still to be paid".
--
--   organization_payouts   one row per transfer; a mistaken row is voided
--                          (never deleted), keeping the history intact
--
--   Rows are written only by Laravel's logged system context (NaSeRy
--   admins). Reads under RLS: members of the organization (Laravel also
--   requires the finance.view permission).
--
-- Rollback: 0043_organization_payouts.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE organization_payouts (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  amount           numeric(12,2) NOT NULL CHECK (amount > 0),
  method           text NOT NULL CHECK (method IN ('bank_transfer', 'gcash', 'maya', 'other')),
  reference        text NOT NULL CHECK (char_length(trim(reference)) BETWEEN 1 AND 100),
  note             text CHECK (note IS NULL OR char_length(note) <= 500),
  paid_at          timestamptz NOT NULL,
  recorded_by      uuid NOT NULL REFERENCES users(id),
  voided_at        timestamptz,
  voided_by        uuid REFERENCES users(id),
  void_reason      text CHECK (void_reason IS NULL OR char_length(void_reason) <= 500),
  created_at       timestamptz NOT NULL DEFAULT now(),
  CHECK ((voided_at IS NULL) = (void_reason IS NULL))
);

CREATE INDEX idx_organization_payouts_org ON organization_payouts (organization_id, paid_at DESC);

ALTER TABLE organization_payouts ENABLE ROW LEVEL SECURITY;

CREATE POLICY organization_payouts_member_read ON organization_payouts FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

GRANT SELECT ON organization_payouts TO nasery_app;

COMMENT ON TABLE organization_payouts IS
  'Transfers of the organizer''s ticket share by NaSeRy. Written only by the Laravel system context; voided, never deleted.';

COMMIT;

-- =====================================================================
-- END 0043
-- =====================================================================
