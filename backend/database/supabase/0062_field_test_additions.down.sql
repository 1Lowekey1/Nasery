-- Rollback of 0062_field_test_additions.sql.
-- Drops the new columns (VAT-free event types, price list item inclusions and
-- pictures, request mood boards). Uploaded picture files are left on disk;
-- nasery:media:prune removes them on its next run.

BEGIN;

-- The view must go back to its 0048 shape before its columns can be dropped.
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
    po.max_quantity
FROM public.organization_price_options po
  JOIN public.organizations o ON o.id = po.organization_id
  JOIN public.organization_profiles op ON op.organization_id = po.organization_id
  LEFT JOIN public.organization_services os ON os.id = po.organization_service_id
WHERE po.is_active AND po.deleted_at IS NULL AND op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::public.approval_status AND NOT o.is_suspended
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));
GRANT SELECT ON public.public_organization_price_options TO anon, authenticated;

ALTER TABLE inquiries DROP COLUMN IF EXISTS mood_board;
ALTER TABLE organization_price_options DROP COLUMN IF EXISTS image_path, DROP COLUMN IF EXISTS includes;
ALTER TABLE organization_profiles DROP COLUMN IF EXISTS vat_exempt_event_types;

COMMIT;
