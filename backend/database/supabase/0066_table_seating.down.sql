-- Rollback of 0066_table_seating.sql.
-- Drops the seating choice settings (tables/spaces or rows) and the floor plan picture column. Seats
-- already given (tickets.seat_label) stay on the tickets. Uploaded floor plan
-- files are left on disk; nasery:media:prune removes them on its next run.

BEGIN;

ALTER TABLE ticket_types
  DROP CONSTRAINT IF EXISTS ticket_types_tables_all_or_none,
  DROP COLUMN IF EXISTS seating_mode,
  DROP COLUMN IF EXISTS table_label,
  DROP COLUMN IF EXISTS table_count,
  DROP COLUMN IF EXISTS table_size;

ALTER TABLE events
  DROP COLUMN IF EXISTS floor_plan_path;

COMMIT;
