-- =====================================================================
-- 0067_seat_rows.sql
-- NaSeRy
--
-- Apply AFTER 0066.
--
-- Purpose (pre-defense, 2026-11-25): exact seats in rows (ticket types with
-- seating_mode 'rows', 0066).
--
--   ticket_types.seat_rows
--     Optional: the seats in each row, from the stage back, e.g. [10, 12, 14]
--     for rows that widen. NULL: every row has table_size seats (as in 0066).
--     table_count stays the number of rows and table_size the longest row.
--
--   ticket_types.row_names
--     'letters' (Row A, Row B; the default, as in 0066) or 'numbers'
--     (Row 1, Row 2), to match the venue's own signs.
--
-- Both are optional and NULL for every existing ticket type, so nothing
-- changes until an organizer uses them. The existing table grants cover them.
--
-- Rollback: 0067_seat_rows.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE ticket_types
  ADD COLUMN seat_rows jsonb NULL
    CHECK (seat_rows IS NULL OR (jsonb_typeof(seat_rows) = 'array' AND jsonb_array_length(seat_rows) BETWEEN 1 AND 200)),
  ADD COLUMN row_names text NULL
    CHECK (row_names IS NULL OR row_names IN ('letters', 'numbers'));

COMMIT;
