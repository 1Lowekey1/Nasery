-- =====================================================================
-- 0046_profile_details.sql
-- NaSeRy
--
-- Apply AFTER 0045.
--
-- Purpose (redesigned business profile panel, 2026-09-30): the organizer's
-- profile gains the details the new UI edits and the public organizer
-- page shows:
--
--   years_experience   e.g. "3–5 years"
--   service_radius_km  how far they travel (1–200 km)
--   event_tags         the kinds of events they handle (JSON array of names)
--   specialties        what they're known for (JSON array of names)
--   website / facebook / instagram
--   banner_color       their page color (#rrggbb)
--
-- public_organization_profiles appends the same columns (CREATE OR REPLACE
-- VIEW may only append; the existing columns, row filter, security_invoker
-- and grants are unchanged). The existing organization_profiles policies
-- and grants cover the new columns.
--
-- Rollback: 0046_profile_details.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE organization_profiles
  ADD COLUMN years_experience  text CHECK (years_experience IS NULL OR char_length(years_experience) <= 30),
  ADD COLUMN service_radius_km smallint CHECK (service_radius_km IS NULL OR service_radius_km BETWEEN 1 AND 200),
  ADD COLUMN event_tags        jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(event_tags) = 'array' AND jsonb_array_length(event_tags) <= 30),
  ADD COLUMN specialties       jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(specialties) = 'array' AND jsonb_array_length(specialties) <= 30),
  ADD COLUMN website           text CHECK (website IS NULL OR char_length(website) <= 255),
  ADD COLUMN facebook          text CHECK (facebook IS NULL OR char_length(facebook) <= 255),
  ADD COLUMN instagram         text CHECK (instagram IS NULL OR char_length(instagram) <= 255),
  ADD COLUMN banner_color      text CHECK (banner_color IS NULL OR banner_color ~ '^#[0-9A-Fa-f]{6}$');

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
    op.banner_color
FROM organization_profiles op
JOIN organizations o ON o.id = op.organization_id
WHERE op.is_published
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;

GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;

COMMIT;

-- =====================================================================
-- END 0046
-- =====================================================================
