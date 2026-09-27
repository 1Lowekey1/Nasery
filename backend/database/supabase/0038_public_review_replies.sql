-- =====================================================================
-- 0038_public_review_replies.sql
-- NaSeRy
--
-- Apply AFTER 0037.
--
-- Purpose (B16 Reviews, user decision): show the organizer's reply next to
-- each published review on the public marketplace profile, and mark
-- reviews the client edited. The marketplace reads only the public views
-- (B3), so the reply is added to public_reviews:
--   response_text   the organizer's reply (deleted replies excluded)
--   responded_at    when it was last written
--   is_edited       the client changed the review after posting
-- Existing columns, their order, the row filter (published, not deleted),
-- security_invoker and the grants are unchanged; new columns are appended.
--
-- Rollback: 0038_public_review_replies.down.sql (restores the 0026 view)
-- =====================================================================

BEGIN;

CREATE OR REPLACE VIEW public.public_reviews
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
    et.name AS event_type_name,
    rr.response_text,
    rr.updated_at AS responded_at,
    EXISTS (SELECT 1 FROM public.review_edits re WHERE re.review_id = r.id) AS is_edited
FROM public.reviews r
JOIN public.users u
    ON u.id = r.client_user_id
JOIN public.events e
    ON e.id = r.event_id
LEFT JOIN public.event_types et
    ON et.id = e.event_type_id
LEFT JOIN public.review_responses rr
    ON rr.review_id = r.id AND rr.deleted_at IS NULL
WHERE r.status = 'published'
  AND r.deleted_at IS NULL;

GRANT SELECT ON public.public_reviews TO anon, authenticated;

COMMIT;

-- =====================================================================
-- END 0038
-- =====================================================================
