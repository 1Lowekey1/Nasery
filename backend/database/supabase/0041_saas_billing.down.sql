-- Rollback of 0041_saas_billing.sql.
-- PostgreSQL cannot drop an enum value, so 'incomplete' stays in the type;
-- any rows using it become 'expired' (never paid) and the three limits go.
BEGIN;
UPDATE subscriptions SET status = 'expired', updated_at = now() WHERE status = 'incomplete';
DELETE FROM plan_entitlements WHERE feature_id IN (
  SELECT id FROM features WHERE key IN ('analytics_history_months', 'custom_event_type_limit', 'price_option_limit'));
DELETE FROM features WHERE key IN ('analytics_history_months', 'custom_event_type_limit', 'price_option_limit');
COMMIT;
