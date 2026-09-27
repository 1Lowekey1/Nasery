-- =====================================================================
-- 0007_inquiries.sql
-- Purpose: client inquiries, cross-tenant by design.
-- Dependencies: 0001-0006
-- =====================================================================

CREATE TABLE inquiries (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  client_user_id           uuid NOT NULL REFERENCES users(id),
  event_type_id            uuid REFERENCES event_types(id),
  requested_date           date,
  guest_count              integer CHECK (guest_count IS NULL OR guest_count >= 0),
  budget_min               numeric(12,2) CHECK (budget_min IS NULL OR budget_min >= 0),
  budget_max               numeric(12,2) CHECK (budget_max IS NULL OR budget_max >= 0),
  location_text            text,
  additional_requirements  text,
  status                   inquiry_status NOT NULL DEFAULT 'draft',
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT chk_budget_range CHECK (budget_min IS NULL OR budget_max IS NULL OR budget_min <= budget_max)
);

CREATE INDEX idx_inquiries_client ON inquiries(client_user_id);
CREATE INDEX idx_inquiries_status ON inquiries(status);

CREATE OR REPLACE FUNCTION trg_inquiries_require_client_account() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.client_user_id AND account_type = 'client') THEN
    RAISE EXCEPTION 'User % is not a client-type account and cannot create/own an inquiry', NEW.client_user_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_require_client_account
BEFORE INSERT ON inquiries
FOR EACH ROW EXECUTE FUNCTION trg_inquiries_require_client_account();

CREATE TABLE inquiry_organizations (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id      uuid NOT NULL REFERENCES inquiries(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  status          inquiry_org_status NOT NULL DEFAULT 'pending',
  decline_reason  text,
  responded_at    timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

-- Natural key; also the composite-FK target for quotations (0008).
ALTER TABLE inquiry_organizations
  ADD CONSTRAINT uq_inquiry_organizations UNIQUE (inquiry_id, organization_id);

CREATE INDEX idx_inquiry_orgs_org_status ON inquiry_organizations(organization_id, status);

CREATE TABLE inquiry_services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id       uuid NOT NULL REFERENCES inquiries(id),
  service_id       uuid REFERENCES services(id),
  custom_service_name text,
  notes            text,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_inquiry_services_inquiry ON inquiry_services(inquiry_id);
