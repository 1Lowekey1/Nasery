-- Rollback of 0067_seat_rows.sql.
-- Drops the per-row seat counts and the row naming choice. Rows go back to
-- table_count rows of table_size seats named A, B, C; seats already given
-- (tickets.seat_label) stay on the tickets.

BEGIN;

ALTER TABLE ticket_types
  DROP COLUMN IF EXISTS seat_rows,
  DROP COLUMN IF EXISTS row_names;

COMMIT;
