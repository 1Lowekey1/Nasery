-- Rollback of 0048_price_list_details.sql. Views go back to their 0046 / 0033
-- shapes first (CREATE OR REPLACE can't drop columns, so they are recreated
-- with their grants). Options using the new units become 'flat'.
BEGIN;

DROP VIEW IF EXISTS public_organization_price_options;
CREATE VIEW public_organization_price_options
WITH (security_invoker = true)
AS
SELECT po.id, po.organization_id, po.organization_service_id, po.name, po.description,
       po.pricing_unit, po.price, po.min_guests, po.sort_order
FROM organization_price_options po
JOIN organizations o ON o.id = po.organization_id
JOIN organization_profiles op ON op.organization_id = po.organization_id
LEFT JOIN organization_services os ON os.id = po.organization_service_id
WHERE po.is_active AND po.deleted_at IS NULL AND op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));
REVOKE ALL ON public_organization_price_options FROM PUBLIC, anon, authenticated;

DROP VIEW IF EXISTS public.public_organization_profiles;
CREATE VIEW public.public_organization_profiles
WITH (security_invoker = true)
AS
SELECT op.organization_id, o.display_name, op.business_name, op.description, op.location_text,
       op.contact_email, op.contact_phone, op.cover_image_url, o.slug, op.latitude, op.longitude,
       op.years_experience, op.service_radius_km, op.event_tags, op.specialties, op.website,
       op.facebook, op.instagram, op.banner_color
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended;
GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;

ALTER TABLE organization_profiles
  DROP COLUMN IF EXISTS estimate_note,
  DROP COLUMN IF EXISTS estimate_tax_percent,
  DROP COLUMN IF EXISTS estimate_service_charge_percent;

UPDATE organization_price_options SET pricing_unit = 'flat' WHERE pricing_unit NOT IN ('flat', 'per_guest');
ALTER TABLE organization_price_options
  DROP CONSTRAINT IF EXISTS organization_price_options_quantity_range_check,
  DROP COLUMN IF EXISTS max_quantity,
  DROP COLUMN IF EXISTS min_quantity,
  DROP COLUMN IF EXISTS unit_label,
  DROP CONSTRAINT IF EXISTS organization_price_options_pricing_unit_check;
ALTER TABLE organization_price_options
  ADD CONSTRAINT organization_price_options_pricing_unit_check CHECK (pricing_unit IN ('flat', 'per_guest'));

COMMIT;
