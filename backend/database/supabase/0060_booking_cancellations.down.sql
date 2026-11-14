-- Rollback of 0060_booking_cancellations.sql. Bookings already cancelled stay cancelled.
BEGIN;

DROP TABLE IF EXISTS booking_cancellations;

COMMIT;
