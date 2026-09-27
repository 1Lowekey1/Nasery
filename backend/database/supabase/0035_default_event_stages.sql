-- =====================================================================
-- 0035_default_event_stages.sql
-- NaSeRy
--
-- Apply AFTER 0034.
--
-- Purpose (B7 Preparation): seed the platform's default preparation stages
-- (organization_id NULL = system default set). The client-facing milestone
-- view (event_progress.current_stage_id) moves through them automatically:
--   Planning   event is a draft
--   Confirmed  event is published, preparation under way
--   Ready      published and readiness has reached 100%
--   Event day  event is ongoing
--   Completed  event is completed
-- Laravel identifies them by name; sort_order gives the display order.
--
-- Data only; no table, policy or grant changes. Idempotent.
-- Rollback: 0035_default_event_stages.down.sql
-- =====================================================================

BEGIN;

INSERT INTO event_stages (organization_id, name, sort_order)
SELECT NULL, v.name, v.sort_order
FROM (VALUES
  ('Planning', 10),
  ('Confirmed', 20),
  ('Ready', 30),
  ('Event day', 40),
  ('Completed', 50)
) AS v(name, sort_order)
WHERE NOT EXISTS (
  SELECT 1 FROM event_stages s
  WHERE s.organization_id IS NULL AND s.name = v.name AND s.deleted_at IS NULL
);

COMMIT;

-- =====================================================================
-- END 0035
-- =====================================================================
