-- =====================================================================
-- 0036_ticket_tiers_public_events.sql
-- NaSeRy
--
-- Apply AFTER 0035.
--
-- Purpose (B9 Ticketing):
--   1. Ticket tiers get the paper's "access zone name and benefits" and a
--      display order.
--   2. Public surfaces for public events, read by Laravel in its logged
--      system context like the B3 marketplace (never base tables):
--        public_events        published/ongoing PUBLIC events of approved,
--                             non-suspended organizers, with their dates
--        public_ticket_types  on-sale tiers of those events
--      security_invoker, no anon/authenticated grant.
--
-- Nothing existing changes. Rollback: 0036_ticket_tiers_public_events.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE ticket_types
  ADD COLUMN zone_name  text,
  ADD COLUMN benefits   jsonb NOT NULL DEFAULT '[]'::jsonb,
  ADD COLUMN sort_order integer NOT NULL DEFAULT 0,
  ADD CONSTRAINT chk_ticket_types_zone_len CHECK (zone_name IS NULL OR char_length(zone_name) <= 80),
  ADD CONSTRAINT chk_ticket_types_benefits CHECK (jsonb_typeof(benefits) = 'array' AND jsonb_array_length(benefits) <= 12),
  ADD CONSTRAINT chk_ticket_types_sales_window CHECK (sales_end_at IS NULL OR sales_start_at IS NULL OR sales_end_at > sales_start_at);

CREATE VIEW public_events
WITH (security_invoker = true)
AS
SELECT
    e.id,
    e.organization_id,
    o.display_name AS organizer_name,
    o.slug         AS organizer_slug,
    e.name,
    e.description,
    et.name        AS event_type,
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
LEFT JOIN LATERAL (
    SELECT min(es.starts_at) AS starts_at, max(es.ends_at) AS ends_at
    FROM event_sessions es
    WHERE es.event_id = e.id AND es.deleted_at IS NULL AND es.status <> 'cancelled'
) s ON true
WHERE e.visibility = 'public'
  AND e.status IN ('published', 'ongoing')
  AND e.deleted_at IS NULL
  AND o.deleted_at IS NULL
  AND o.approval_status = 'approved'::approval_status
  AND NOT o.is_suspended;

CREATE VIEW public_ticket_types
WITH (security_invoker = true)
AS
SELECT
    tt.id,
    tt.event_id,
    tt.name,
    tt.description,
    tt.zone_name,
    tt.benefits,
    tt.price,
    tt.currency,
    tt.capacity,
    tt.sales_start_at,
    tt.sales_end_at,
    tt.sort_order
FROM ticket_types tt
JOIN public_events pe ON pe.id = tt.event_id
WHERE tt.status = 'on_sale'
  AND tt.deleted_at IS NULL;

REVOKE ALL ON public_events, public_ticket_types FROM PUBLIC, anon, authenticated;

COMMIT;

-- =====================================================================
-- END 0036
-- =====================================================================
