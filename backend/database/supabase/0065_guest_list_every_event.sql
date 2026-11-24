-- =====================================================================
-- 0065_guest_list_every_event.sql
-- NaSeRy
--
-- Apply AFTER 0064.
--
-- Purpose (adviser consultation, 2026-11-24): every event has a "Guest list"
-- tier for invited guests, public events included (before, only private
-- events got one, from the event editor). It is free and closed, so it is
-- never on sale; the event's overall capacity still limits every ticket.
-- New events get it when they are created (TierManager::ensureGuestList);
-- this adds it to existing open events that don't have one.
--
-- Rollback: 0065_guest_list_every_event.down.sql
-- =====================================================================

BEGIN;

INSERT INTO ticket_types (event_id, name, description, price, currency, capacity, status, sort_order)
SELECT e.id, 'Guest list', 'Invited guests (never on sale).', 0, 'PHP', GREATEST(1, COALESCE(e.overall_capacity, 100)), 'closed', 999
  FROM events e
 WHERE e.deleted_at IS NULL
   AND e.status IN ('draft', 'published', 'ongoing')
   AND NOT EXISTS (SELECT 1 FROM ticket_types t
                    WHERE t.event_id = e.id AND t.deleted_at IS NULL
                      AND lower(trim(t.name)) = 'guest list' AND t.price = 0);

COMMIT;
