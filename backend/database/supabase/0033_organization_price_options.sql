-- =====================================================================
-- 0033_organization_price_options.sql
-- NaSeRy
--
-- Apply AFTER 0032.
--
-- Purpose (B5, paper: "public-facing quotation calculator on each
-- organizer's profile page"):
--   Organizers keep their own price list, since prices differ per organizer
--   and per partner (e.g. two catering partners at different per-guest
--   rates). Clients pick options on the public profile, see an estimate, and
--   carry their picks into an inquiry; organizers can build quotation lines
--   from the same list. Estimates are not binding (paper: final pricing is
--   agreed externally).
--
--   * organization_price_options: an option, optionally grouped under one of
--     the organization's services; priced flat or per guest.
--   * public_organization_price_options: active options of LISTED
--     organizers only (published, approved, not suspended, not deleted).
--     security_invoker like the other public views; read by Laravel in its
--     logged system context, so no anon/authenticated grant.
--
-- Nothing existing is altered. Rollback: 0033_organization_price_options.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE organization_price_options (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id          uuid NOT NULL REFERENCES organizations(id),
  organization_service_id  uuid REFERENCES organization_services(id),
  name                     text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 2 AND 120),
  description              text CHECK (description IS NULL OR char_length(description) <= 500),
  pricing_unit             text NOT NULL DEFAULT 'flat' CHECK (pricing_unit IN ('flat', 'per_guest')),
  price                    numeric(12,2) NOT NULL CHECK (price >= 0),
  min_guests               integer CHECK (min_guests IS NULL OR min_guests >= 1),
  is_active                boolean NOT NULL DEFAULT true,
  sort_order               integer NOT NULL DEFAULT 0,
  deleted_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_price_options_org ON organization_price_options(organization_id, is_active) WHERE deleted_at IS NULL;
CREATE INDEX idx_price_options_service ON organization_price_options(organization_service_id);

-- A grouped option must use a service of the same organization.
CREATE OR REPLACE FUNCTION check_price_option_service() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF NEW.organization_service_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM organization_services
    WHERE id = NEW.organization_service_id AND organization_id = NEW.organization_id
  ) THEN
    RAISE EXCEPTION 'Price option service must belong to the same organization'
      USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_price_option_service
  BEFORE INSERT OR UPDATE OF organization_id, organization_service_id ON organization_price_options
  FOR EACH ROW EXECUTE FUNCTION check_price_option_service();

-- ---------------------------------------------------------------------
-- RLS + grants (same shape as organization_services)
-- ---------------------------------------------------------------------
ALTER TABLE organization_price_options ENABLE ROW LEVEL SECURITY;

CREATE POLICY price_options_org ON organization_price_options FOR ALL
  USING (organization_id IN (SELECT user_org_ids()) OR admin_has_active_grant(organization_id))
  WITH CHECK (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON organization_price_options FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON organization_price_options TO nasery_app;
REVOKE EXECUTE ON FUNCTION check_price_option_service() FROM PUBLIC, anon, authenticated;

-- ---------------------------------------------------------------------
-- Public surface: listed organizers' active options
-- ---------------------------------------------------------------------
CREATE VIEW public_organization_price_options
WITH (security_invoker = true)
AS
SELECT
    po.id,
    po.organization_id,
    po.organization_service_id,
    po.name,
    po.description,
    po.pricing_unit,
    po.price,
    po.min_guests,
    po.sort_order
FROM organization_price_options po
JOIN organizations o ON o.id = po.organization_id
JOIN organization_profiles op ON op.organization_id = po.organization_id
LEFT JOIN organization_services os ON os.id = po.organization_service_id
WHERE po.is_active
  AND po.deleted_at IS NULL
  AND op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended
  -- an option grouped under a hidden service is hidden too
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));

REVOKE ALL ON public_organization_price_options FROM PUBLIC, anon, authenticated;

COMMIT;

-- =====================================================================
-- END 0033
-- =====================================================================
