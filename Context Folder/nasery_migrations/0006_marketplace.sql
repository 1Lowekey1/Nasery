-- =====================================================================
-- 0006_marketplace.sql
-- Purpose: public organizer profile, service catalog, event types.
-- Dependencies: 0001-0005
-- =====================================================================

CREATE TABLE organization_profiles (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL UNIQUE REFERENCES organizations(id),
  business_name    text NOT NULL,
  description      text,
  location_text    text,
  latitude         numeric(9,6),
  longitude        numeric(9,6),
  contact_email    citext,
  contact_phone    text,
  cover_image_url  text,
  is_published     boolean NOT NULL DEFAULT false,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_org_profiles_published ON organization_profiles(is_published);

CREATE TABLE services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name             text NOT NULL,
  category         text,
  is_system_default boolean NOT NULL DEFAULT true,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE organization_services (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  service_id       uuid REFERENCES services(id),
  custom_name      text,
  description      text,
  base_price       numeric(12,2) CHECK (base_price IS NULL OR base_price >= 0),
  is_active        boolean NOT NULL DEFAULT true,
  deleted_at       timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_org_services_org_active ON organization_services(organization_id, is_active);

CREATE TABLE event_types (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id    uuid REFERENCES organizations(id),   -- NULL = platform default
  name               text NOT NULL,
  description        text,
  is_system_default  boolean NOT NULL DEFAULT false,
  deleted_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_event_types_org_name
  ON event_types(organization_id, name) WHERE deleted_at IS NULL AND organization_id IS NOT NULL;
CREATE UNIQUE INDEX uq_event_types_system_name
  ON event_types(name) WHERE deleted_at IS NULL AND organization_id IS NULL;

-- ---------------------------------------------------------------------
-- Public views: only these are granted SELECT to anon/authenticated for
-- marketplace browsing. Base tables remain governed by RLS (0022) for
-- authenticated org-member writes; internal fields added to base tables
-- later never leak unless explicitly added to a view's column list.
-- ---------------------------------------------------------------------
CREATE VIEW public_organization_profiles AS
  SELECT op.organization_id, o.display_name, op.business_name, op.description,
         op.location_text, op.contact_email, op.contact_phone, op.cover_image_url
  FROM organization_profiles op
  JOIN organizations o ON o.id = op.organization_id
  WHERE op.is_published AND o.deleted_at IS NULL AND o.approval_status = 'approved' AND NOT o.is_suspended;

CREATE VIEW public_organization_services AS
  SELECT os.id, os.organization_id, COALESCE(os.custom_name, s.name) AS name, os.description, os.base_price
  FROM organization_services os
  LEFT JOIN services s ON s.id = os.service_id
  WHERE os.is_active AND os.deleted_at IS NULL;

CREATE VIEW public_event_types AS
  SELECT id, organization_id, name, description
  FROM event_types
  WHERE deleted_at IS NULL AND (organization_id IS NULL OR organization_id IN (
    SELECT id FROM organizations WHERE approval_status = 'approved' AND NOT is_suspended AND deleted_at IS NULL
  ));
