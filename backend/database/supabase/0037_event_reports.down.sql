-- Rollback of 0037_event_reports.sql (drops every shared report version).
BEGIN;
DROP TABLE IF EXISTS event_reports;
COMMIT;
