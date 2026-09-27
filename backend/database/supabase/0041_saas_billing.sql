-- =====================================================================
-- 0041_saas_billing.sql
-- NaSeRy
--
-- Apply AFTER 0040.
--
-- Purpose (B18 SaaS billing):
--   1. subscription_status gains 'incomplete': a checkout for a paid plan
--      creates the subscription + invoice + payment rows BEFORE the money
--      arrives (payments must reference an invoice, and an invoice a
--      subscription). 'incomplete' is not "live" (uq_subscriptions_live
--      ignores it), so the organization keeps its current plan until the
--      payment settles.
--   2. Three numeric plan limits (team decision 2026-09-26: keep useful
--      features on Free, only limit the extras). NULL = unlimited (0020).
--        analytics_history_months  Free 3,  Starter 12,   Pro 24
--        custom_event_type_limit   Free 2,  Starter NULL, Pro NULL
--        price_option_limit        Free 5,  Starter 20,   Pro NULL
--      Data only; idempotent.
--
-- Rollback: 0041_saas_billing.down.sql (the enum value cannot be dropped
-- in PostgreSQL; the rollback turns 'incomplete' rows into 'expired').
-- =====================================================================

ALTER TYPE subscription_status ADD VALUE IF NOT EXISTS 'incomplete';

BEGIN;

INSERT INTO features (key, name, value_type, description) VALUES
  ('analytics_history_months', 'Analytics History',   'numeric', 'How many months back the analytics trends can go'),
  ('custom_event_type_limit',  'Custom Event Types',  'numeric', 'How many organization-made event types'),
  ('price_option_limit',       'Price List Options',  'numeric', 'How many options in the public quotation calculator')
ON CONFLICT (key) DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id, NULL,
  CASE f.key
    WHEN 'analytics_history_months' THEN CASE p.slug WHEN 'free' THEN 3 WHEN 'starter' THEN 12 ELSE 24 END
    WHEN 'custom_event_type_limit'  THEN CASE p.slug WHEN 'free' THEN 2 ELSE NULL END
    WHEN 'price_option_limit'       THEN CASE p.slug WHEN 'free' THEN 5 WHEN 'starter' THEN 20 ELSE NULL END
  END
FROM plans p CROSS JOIN features f
WHERE p.slug IN ('free', 'starter', 'pro')
  AND f.key IN ('analytics_history_months', 'custom_event_type_limit', 'price_option_limit')
ON CONFLICT (plan_id, feature_id) DO NOTHING;

COMMIT;

-- =====================================================================
-- END 0041
-- =====================================================================
