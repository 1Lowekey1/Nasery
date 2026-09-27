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
