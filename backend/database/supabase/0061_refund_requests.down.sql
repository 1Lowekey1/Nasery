-- Rollback of 0061_refund_requests.sql. Refunds already made stay made (payment_refunds).
BEGIN;

DROP TABLE IF EXISTS refund_requests;

COMMIT;
