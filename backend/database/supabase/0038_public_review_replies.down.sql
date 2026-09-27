-- Rollback of 0038: restore public_reviews as defined in 0026 (no reply columns).
-- Columns can't be dropped with CREATE OR REPLACE, so the view is recreated.
BEGIN;

DROP VIEW public.public_reviews;

CREATE VIEW public.public_reviews
WITH (security_invoker = true)
AS
SELECT
    r.id,
    r.event_id,
    r.organization_id,
    r.rating,
    r.written_review,
    r.created_at,
    u.first_name AS reviewer_first_name,
    LEFT(u.last_name, 1) AS reviewer_last_initial,
    et.name AS event_type_name
FROM public.reviews r
JOIN public.users u
    ON u.id = r.client_user_id
JOIN public.events e
    ON e.id = r.event_id
LEFT JOIN public.event_types et
    ON et.id = e.event_type_id
WHERE r.status = 'published'
  AND r.deleted_at IS NULL;

GRANT SELECT ON public.public_reviews TO anon, authenticated;

COMMIT;
