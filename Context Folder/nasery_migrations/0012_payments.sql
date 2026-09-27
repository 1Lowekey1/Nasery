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
