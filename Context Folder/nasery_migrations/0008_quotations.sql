-- =====================================================================
-- 0008_quotations.sql
-- Purpose: organizer bids against an inquiry.
-- Dependencies: 0001-0007
-- =====================================================================

CREATE TABLE quotations (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inquiry_id      uuid NOT NULL REFERENCES inquiries(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  total_amount    numeric(12,2) NOT NULL CHECK (total_amount >= 0),
  currency        text NOT NULL DEFAULT 'PHP',
  terms           text,
  status          quotation_status NOT NULL DEFAULT 'draft',
  submitted_at    timestamptz,
  decided_at      timestamptz,
  decided_by      uuid REFERENCES users(id),
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),

  -- Composite FK: an organization may only quote on an inquiry it was actually
  -- invited to bid on. Requires uq_inquiry_organizations from 0007.
  CONSTRAINT fk_quotations_inquiry_org
    FOREIGN KEY (inquiry_id, organization_id)
    REFERENCES inquiry_organizations (inquiry_id, organization_id)
);

-- One active quotation per organizer per inquiry (historical rows preserved).
CREATE UNIQUE INDEX uq_quotations_active
  ON quotations(inquiry_id, organization_id) WHERE status IN ('draft','submitted');

CREATE INDEX idx_quotations_inquiry_status ON quotations(inquiry_id, status);
CREATE INDEX idx_quotations_org            ON quotations(organization_id);

CREATE TABLE quotation_items (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  quotation_id             uuid NOT NULL REFERENCES quotations(id),
  organization_service_id  uuid REFERENCES organization_services(id),
  description              text NOT NULL,
  quantity                 numeric(10,2) NOT NULL DEFAULT 1 CHECK (quantity > 0),
  unit_price               numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  subtotal                 numeric(12,2) NOT NULL CHECK (subtotal >= 0),
  created_at               timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_quotation_items_quotation ON quotation_items(quotation_id);

CREATE TABLE quotation_status_history (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  quotation_id   uuid NOT NULL REFERENCES quotations(id),
  from_status    quotation_status,
  to_status      quotation_status NOT NULL,
  actor_user_id  uuid REFERENCES users(id),
  reason         text,
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_quotation_history_quotation ON quotation_status_history(quotation_id);
