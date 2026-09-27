-- Rollback of 0042_inquiry_threads.sql (drops every thread message and read marker).
BEGIN;
DROP TABLE IF EXISTS inquiry_thread_reads;
DROP TABLE IF EXISTS inquiry_messages;
COMMIT;
