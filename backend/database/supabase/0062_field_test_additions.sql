-- =====================================================================
-- 0062_field_test_additions.sql
-- NaSeRy
--
-- Apply AFTER 0061.
--
-- Purpose (organizer consultation and survey, 2026-11-18):
--
--   organization_profiles.vat_exempt_event_types
--     Event types the organizer does NOT charge VAT for (e.g. weddings,
--     where they cover it themselves). A JSON array of event type ids.
--     Empty (the default) = VAT on every event, as before.
--
--   organization_price_options.includes
--     What a price list item includes, as short lines (e.g. "Bridal car",
--     "2 photographers"). A JSON array of strings, at most 20.
--   organization_price_options.image_path
--     A picture of the item, so clients see what they're choosing. Same
--     storage as the other uploaded pictures (ImageStore, WebP).
--
--   inquiries.mood_board
--     Pictures the client adds to their request (mood board, colour
--     palette), shown to the organizers who get the request and, once
--     booked, on the event while the team prepares. A JSON array of
--     {"path": "mood-boards/<uuid>.webp", "caption": "..."}, at most 8.
--
-- public_organization_price_options appends includes and image_path
-- (CREATE OR REPLACE VIEW may only append; filters, security_invoker and
-- grants are unchanged). Existing rows get the empty defaults, so nothing
-- changes until organizers and clients use the new fields. The existing
-- table grants cover the new columns.
--
-- Rollback: 0062_field_test_additions.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE organization_profiles
  ADD COLUMN vat_exempt_event_types jsonb NOT NULL DEFAULT '[]'
    CHECK (jsonb_typeof(vat_exempt_event_types) = 'array' AND jsonb_array_length(vat_exempt_event_types) <= 50);

ALTER TABLE organization_price_options
  ADD COLUMN includes jsonb NOT NULL DEFAULT '[]'
    CHECK (jsonb_typeof(includes) = 'array' AND jsonb_array_length(includes) <= 20),
  ADD COLUMN image_path text
    CHECK (image_path IS NULL OR image_path ~ '^price-options/[0-9a-f-]{36}\.webp$');

ALTER TABLE inquiries
  ADD COLUMN mood_board jsonb NOT NULL DEFAULT '[]'
    CHECK (jsonb_typeof(mood_board) = 'array' AND jsonb_array_length(mood_board) <= 8);

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
    po.image_path
FROM public.organization_price_options po
  JOIN public.organizations o ON o.id = po.organization_id
  JOIN public.organization_profiles op ON op.organization_id = po.organization_id
  LEFT JOIN public.organization_services os ON os.id = po.organization_service_id
WHERE po.is_active AND po.deleted_at IS NULL AND op.is_published AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::public.approval_status AND NOT o.is_suspended
  AND (po.organization_service_id IS NULL OR (os.is_active AND os.deleted_at IS NULL));

COMMIT;
