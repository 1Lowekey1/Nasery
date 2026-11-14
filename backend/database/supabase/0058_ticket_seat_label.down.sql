-- Rollback of 0058_ticket_seat_label.sql. Drops the labels organizers entered.
BEGIN;

ALTER TABLE tickets DROP COLUMN IF EXISTS seat_label;

COMMIT;
