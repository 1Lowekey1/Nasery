-- =====================================================================
-- 0050_uploaded_images.down.sql — rollback of 0050_uploaded_images.sql
--
-- Views first (they reference the columns), back to their 0049 shape, then
-- the columns. Uploaded files in storage/app/media are NOT deleted here.
-- =====================================================================

BEGIN;

DROP VIEW public.public_organization_profiles;
CREATE VIEW public.public_organization_profiles WITH (security_invoker = true) AS
 SELECT op.organization_id,
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
  WHERE op.is_published AND o.deleted_at IS NULL AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended;

DROP VIEW public.public_events;
CREATE VIEW public.public_events WITH (security_invoker = true) AS
 SELECT e.id,
    e.organization_id,
    o.display_name AS organizer_name,
    o.slug AS organizer_slug,
    e.name,
    e.description,
    et.name AS event_type,
    e.status,
    e.timezone,
    e.venue_name,
    e.venue_address,
    e.latitude,
    e.longitude,
    s.starts_at,
    s.ends_at
   FROM events e
     JOIN organizations o ON o.id = e.organization_id
     LEFT JOIN event_types et ON et.id = e.event_type_id
     LEFT JOIN LATERAL ( SELECT min(es.starts_at) AS starts_at,
            max(es.ends_at) AS ends_at
           FROM event_sessions es
          WHERE es.event_id = e.id AND es.deleted_at IS NULL AND es.status <> 'cancelled'::session_status) s ON true
  WHERE e.visibility = 'public'::text AND (e.status = ANY (ARRAY['published'::event_status, 'ongoing'::event_status])) AND e.deleted_at IS NULL AND o.deleted_at IS NULL AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended;

-- The grants they had before 0050 (public_events had none beyond the owner).
GRANT SELECT ON public.public_organization_profiles TO anon, authenticated;

ALTER TABLE events DROP COLUMN cover_path;
ALTER TABLE organization_profiles DROP COLUMN cover_path, DROP COLUMN logo_path;
ALTER TABLE users DROP COLUMN avatar_path;

COMMIT;
