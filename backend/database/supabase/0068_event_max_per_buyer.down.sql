-- Rollback of 0068_event_max_per_buyer.sql: every event goes back to 10 tickets per order.
BEGIN;

REVOKE UPDATE (max_per_buyer) ON events FROM nasery_app;
ALTER TABLE events DROP COLUMN IF EXISTS max_per_buyer;

COMMIT;
