-- Rollback for 0029: restore the 0026 definition of public_organization_profiles
-- (columns can't be removed with CREATE OR REPLACE, so drop and recreate).
BEGIN;

DROP VIEW public.public_organization_profiles;

CREATE VIEW public.public_organization_profiles
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
    op.cover_image_url
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;

GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;

COMMIT;
