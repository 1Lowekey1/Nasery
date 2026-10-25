-- =====================================================================
-- 0050_uploaded_images.sql
-- NaSeRy
--
-- Apply AFTER 0049.
--
-- Purpose (image uploads, 2026-10-19): people upload pictures instead of
-- pasting links. The files live on the server (storage/app/media, outside the
-- public web folder); these columns hold their relative paths, e.g.
-- 'avatars/01a1…​.webp'. Every file was decoded and re-encoded by the server
-- (App\Services\Media\ImageStore), so no original upload is ever stored.
--
--   users.avatar_path                    profile photo (512×512)
--   organization_profiles.logo_path      business logo (512×512)
--   organization_profiles.cover_path     business cover (1600×600); the older
--                                        cover_image_url link stays as fallback
--   events.cover_path                    event cover (1600×900)
--
-- public_organization_profiles and public_events append logo_path/cover_path
-- (CREATE OR REPLACE VIEW may only append; filters, security_invoker and
-- grants are unchanged). All columns are nullable: existing rows unchanged.
-- Paths are checked to look like '<folder>/<uuid>.webp' so nothing else (a
-- URL, a path with ..) can be stored.
--
-- Rollback: 0050_uploaded_images.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE users
  ADD COLUMN avatar_path text CHECK (avatar_path IS NULL OR avatar_path ~ '^avatars/[0-9a-f-]{36}\.webp$');

ALTER TABLE organization_profiles
  ADD COLUMN logo_path  text CHECK (logo_path  IS NULL OR logo_path  ~ '^logos/[0-9a-f-]{36}\.webp$'),
  ADD COLUMN cover_path text CHECK (cover_path IS NULL OR cover_path ~ '^covers/[0-9a-f-]{36}\.webp$');

ALTER TABLE events
  ADD COLUMN cover_path text CHECK (cover_path IS NULL OR cover_path ~ '^event-covers/[0-9a-f-]{36}\.webp$');

CREATE OR REPLACE VIEW public.public_organization_profiles AS
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
    op.estimate_note,
    op.logo_path,
    op.cover_path
   FROM organization_profiles op
     JOIN organizations o ON o.id = op.organization_id
  WHERE op.is_published AND o.deleted_at IS NULL AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended;

CREATE OR REPLACE VIEW public.public_events AS
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
    s.ends_at,
    e.cover_path
   FROM events e
     JOIN organizations o ON o.id = e.organization_id
     LEFT JOIN event_types et ON et.id = e.event_type_id
     LEFT JOIN LATERAL ( SELECT min(es.starts_at) AS starts_at,
            max(es.ends_at) AS ends_at
           FROM event_sessions es
          WHERE es.event_id = e.id AND es.deleted_at IS NULL AND es.status <> 'cancelled'::session_status) s ON true
  WHERE e.visibility = 'public'::text AND (e.status = ANY (ARRAY['published'::event_status, 'ongoing'::event_status])) AND e.deleted_at IS NULL AND o.deleted_at IS NULL AND o.approval_status = 'approved'::approval_status AND NOT o.is_suspended;

COMMIT;
