-- =====================================================================
-- 0020_saas.sql
-- Purpose: plans, features, entitlements, subscriptions, invoices.
-- Dependencies: 0001-0019, then back-fills the payments FK from 0012.
-- =====================================================================

CREATE TABLE plans (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name             text NOT NULL,
  slug             text NOT NULL,
  price_monthly    numeric(12,2) NOT NULL DEFAULT 0 CHECK (price_monthly >= 0),
  price_yearly     numeric(12,2) NOT NULL DEFAULT 0 CHECK (price_yearly >= 0),
  is_active        boolean NOT NULL DEFAULT true,
  is_default_free  boolean NOT NULL DEFAULT false,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_plans_slug ON plans(slug) WHERE deleted_at IS NULL;

CREATE TABLE features (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key         text NOT NULL,
  name        text NOT NULL,
  value_type  text NOT NULL CHECK (value_type IN ('boolean','numeric')),
  description text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_features_key ON features(key);

CREATE TABLE plan_entitlements (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  plan_id        uuid NOT NULL REFERENCES plans(id),
  feature_id     uuid NOT NULL REFERENCES features(id),
  boolean_value  boolean,
  numeric_value  numeric(12,2),
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_plan_entitlements ON plan_entitlements(plan_id, feature_id);

CREATE TABLE subscriptions (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id          uuid NOT NULL REFERENCES organizations(id),
  plan_id                  uuid NOT NULL REFERENCES plans(id),
  billing_cycle            billing_cycle NOT NULL,
  status                   subscription_status NOT NULL DEFAULT 'active',
  current_period_start     timestamptz NOT NULL DEFAULT now(),
  current_period_end       timestamptz NOT NULL,
  grace_period_ends_at     timestamptz,
  cancel_at_period_end     boolean NOT NULL DEFAULT false,
  cancelled_at             timestamptz,
  downgraded_from_plan_id  uuid REFERENCES plans(id),
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_grace_period_fields CHECK (status = 'grace_period' OR grace_period_ends_at IS NULL)
);

-- One "live" subscription per organization at a time.
CREATE UNIQUE INDEX uq_subscriptions_live
  ON subscriptions(organization_id) WHERE status IN ('active','payment_failed','grace_period');

CREATE INDEX idx_subscriptions_org_status ON subscriptions(organization_id, status);

CREATE TABLE subscription_invoices (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subscription_id       uuid NOT NULL REFERENCES subscriptions(id),
  amount                numeric(12,2) NOT NULL CHECK (amount >= 0),
  currency              text NOT NULL DEFAULT 'PHP',
  billing_period_start  timestamptz NOT NULL,
  billing_period_end    timestamptz NOT NULL,
  status                invoice_status NOT NULL DEFAULT 'draft',
  issued_at             timestamptz,
  created_at            timestamptz NOT NULL DEFAULT now()
);

-- NOTE: no payment_id column here. The relationship is subscription_invoices 1:N payments
-- via payments.subscription_invoice_id (added below) -- an invoice may have several payment
-- attempts (retries); at most one may ever settle (uq_payments_invoice_settled from 0012).
CREATE INDEX idx_subscription_invoices_subscription ON subscription_invoices(subscription_id);

-- Back-fill the FK deferred from 0012 now that subscription_invoices exists.
ALTER TABLE payments
  ADD CONSTRAINT fk_payments_subscription_invoice FOREIGN KEY (subscription_invoice_id) REFERENCES subscription_invoices(id);
