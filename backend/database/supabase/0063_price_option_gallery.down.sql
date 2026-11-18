-- Rollback of 0063_price_option_gallery.sql.
-- Puts each item's first picture back in image_path and drops the gallery.
-- The other pictures' files are left on disk; nasery:media:prune removes
-- them on its next run.

BEGIN;

UPDATE organization_price_options
   SET image_path = images->>0
 WHERE jsonb_array_length(images) > 0;

-- The view must go back to its 0062 shape before the column can be dropped.
DROP VIEW public.public_organization_price_options;
CREATE VIEW public.public_organization_price_options
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
    po.image_path
FROM public.organization_price_options po
  JOIN public.organizations o ON o.id = po.organization_id
  JOIN public.organization_profiles op ON op.organization_id = po.organization_id
  LEFT JOIN public.organization_services os ON os.id = po.organization_service_id
WHERE po.is_active AND po.deleted_at IS NULL AND op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::public.approval_status AND NOT o.is_suspended
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));
GRANT SELECT ON public.public_organization_price_options TO anon, authenticated;

ALTER TABLE organization_price_options DROP COLUMN IF EXISTS images;

COMMIT;
