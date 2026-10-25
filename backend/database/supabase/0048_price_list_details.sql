-- =====================================================================
-- 0048_price_list_details.sql
-- NaSeRy
--
-- Apply AFTER 0047.
--
-- Purpose (detailed organizer calculator, 2026-10-16): organizers' price
-- lists and the client estimate get more detail.
--
--   organization_price_options
--     pricing_unit   + 'per_hour', 'per_day', 'per_unit' (was flat | per_guest)
--     unit_label     what one unit is, for per_unit (e.g. "table", "photographer")
--     min_quantity   the least a client can pick (per_hour/per_day/per_unit)
--     max_quantity   the most a client can pick
--   organization_profiles (estimate settings shown with every estimate)
--     estimate_service_charge_percent   0–30 %
--     estimate_tax_percent              0–20 % (e.g. 12 % VAT)
--     estimate_note                     e.g. "Final price confirmed after a site visit."
--
-- public_organization_price_options and public_organization_profiles append
-- the new columns (CREATE OR REPLACE VIEW may only append; existing columns,
-- row filters, security_invoker and grants are unchanged). Existing options
-- keep their values (flat / per_guest, no quantity limits). The existing
-- table grants cover the new columns.
--
-- Rollback: 0048_price_list_details.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE organization_price_options DROP CONSTRAINT organization_price_options_pricing_unit_check;
ALTER TABLE organization_price_options
  ADD CONSTRAINT organization_price_options_pricing_unit_check
    CHECK (pricing_unit IN ('flat', 'per_guest', 'per_hour', 'per_day', 'per_unit')),
  ADD COLUMN unit_label   text CHECK (unit_label IS NULL OR char_length(btrim(unit_label)) BETWEEN 1 AND 30),
  ADD COLUMN min_quantity integer CHECK (min_quantity IS NULL OR min_quantity BETWEEN 1 AND 100000),
  ADD COLUMN max_quantity integer CHECK (max_quantity IS NULL OR max_quantity BETWEEN 1 AND 100000),
  ADD CONSTRAINT organization_price_options_quantity_range_check
    CHECK (min_quantity IS NULL OR max_quantity IS NULL OR max_quantity >= min_quantity);

ALTER TABLE organization_profiles
  ADD COLUMN estimate_service_charge_percent numeric(5,2) CHECK (estimate_service_charge_percent IS NULL OR estimate_service_charge_percent BETWEEN 0 AND 30),
  ADD COLUMN estimate_tax_percent            numeric(5,2) CHECK (estimate_tax_percent IS NULL OR estimate_tax_percent BETWEEN 0 AND 20),
  ADD COLUMN estimate_note                   text CHECK (estimate_note IS NULL OR char_length(estimate_note) <= 500);

CREATE OR REPLACE VIEW public_organization_price_options
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
    po.sort_order,
    po.unit_label,
    po.min_quantity,
    po.max_quantity
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

CREATE OR REPLACE VIEW public.public_organization_profiles
WITH (security_invoker = true)
AS
SELECT
    op.organization_id,
    o.display_name,
    op.business_name,
    op.description,
    op.location_text,
    op.contact_email,
    op.contact_phone,
    op.cover_image_url,
    o.slug,
    op.latitude,
    op.longitude,
    op.years_experience,
    op.service_radius_km,
    op.event_tags,
    op.specialties,
    op.website,
    op.facebook,
    op.instagram,
    op.banner_color,
    op.estimate_service_charge_percent,
    op.estimate_tax_percent,
    op.estimate_note
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;

COMMIT;

-- =====================================================================
-- END 0048
-- =====================================================================
