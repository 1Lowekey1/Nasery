-- Rollback of 0046_profile_details.sql (drops the extra profile details).
-- The view goes back to its 0029 shape first (columns can't be removed by
-- CREATE OR REPLACE, so it is dropped and recreated with its grants).
BEGIN;
DROP VIEW IF EXISTS public.public_organization_profiles;
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
    op.cover_image_url,
    o.slug,
    op.latitude,
    op.longitude
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;
GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;
ALTER TABLE organization_profiles
  DROP COLUMN IF EXISTS banner_color,
  DROP COLUMN IF EXISTS instagram,
  DROP COLUMN IF EXISTS facebook,
  DROP COLUMN IF EXISTS website,
  DROP COLUMN IF EXISTS specialties,
  DROP COLUMN IF EXISTS event_tags,
  DROP COLUMN IF EXISTS service_radius_km,
  DROP COLUMN IF EXISTS years_experience;
COMMIT;
