-- Rollback of 0065_guest_list_every_event.sql: removes the automatic "Guest
-- list" tiers that no one has been added to yet (tiers with tickets or holds
-- stay, so no guest loses a ticket).

BEGIN;

UPDATE ticket_types t
   SET deleted_at = now(), updated_at = now()
 WHERE t.deleted_at IS NULL
   AND t.description = 'Invited guests (never on sale).'
   AND NOT EXISTS (SELECT 1 FROM tickets k WHERE k.ticket_type_id = t.id)
   AND NOT EXISTS (SELECT 1 FROM ticket_holds h WHERE h.ticket_type_id = t.id);

COMMIT;
