-- =====================================================================
-- 0029_public_profiles_view_marketplace.sql
-- NaSeRy
--
-- Apply AFTER 0028.
--
-- Purpose:
--   The marketplace (B3) needs friendly organizer URLs and "near me"
--   distance search, like the prototype. Append slug, latitude and
--   longitude to public_organization_profiles.
--
-- Unchanged: the existing 8 columns (names, order, types), the row filter
-- (published + approved + not suspended + not deleted), security_invoker
-- (0026) and grants. CREATE OR REPLACE VIEW may only append columns, which
-- is exactly what this does. No table, policy, function or row changes.
-- Rollback: 0029_public_profiles_view_marketplace.down.sql
-- =====================================================================

BEGIN;

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
    op.longitude
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;

GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;

COMMIT;
