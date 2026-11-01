-- Rollback of 0055_event_agreed_amount.sql: won value goes back to the accepted quotation's total.
BEGIN;

REVOKE UPDATE (agreed_amount, agreed_amount_note) ON events FROM nasery_app;
ALTER TABLE events DROP COLUMN IF EXISTS agreed_amount_note, DROP COLUMN IF EXISTS agreed_amount;

COMMIT;
