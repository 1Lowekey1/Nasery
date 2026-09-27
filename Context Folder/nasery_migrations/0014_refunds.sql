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
