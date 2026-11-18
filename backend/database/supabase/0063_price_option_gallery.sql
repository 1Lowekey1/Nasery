-- =====================================================================
-- 0063_price_option_gallery.sql
-- NaSeRy
--
-- Apply AFTER 0062.
--
-- Purpose (organizer field test follow-up, 2026-11-18):
--
--   organization_price_options.images
--     Up to 10 pictures per price list item, so clients see examples of
--     the organizer's work before asking for a quotation. A JSON array of
--     paths ("price-options/<uuid>.webp"); the first one is the cover.
--
-- 0062's single picture (image_path) moves into the list, and image_path
-- is cleared (the column stays, unused, so the rollback can restore it).
--
-- public_organization_price_options appends images (CREATE OR REPLACE VIEW
-- may only append; filters, security_invoker and grants are unchanged).
-- The existing table grants cover the new column.
--
-- Rollback: 0063_price_option_gallery.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE organization_price_options
  ADD COLUMN images jsonb NOT NULL DEFAULT '[]'
    CHECK (jsonb_typeof(images) = 'array' AND jsonb_array_length(images) <= 10);

UPDATE organization_price_options
   SET images = jsonb_build_array(image_path), image_path = NULL
 WHERE image_path IS NOT NULL;

CREATE OR REPLACE VIEW public.public_organization_price_options
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
    po.max_quantity,
    po.includes,
    po.image_path,
    po.images
FROM public.organization_price_options po
  JOIN public.organizations o ON o.id = po.organization_id
  JOIN public.organization_profiles op ON op.organization_id = po.organization_id
  LEFT JOIN public.organization_services os ON os.id = po.organization_service_id
WHERE po.is_active AND po.deleted_at IS NULL AND op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::public.approval_status AND NOT o.is_suspended
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));

COMMIT;
