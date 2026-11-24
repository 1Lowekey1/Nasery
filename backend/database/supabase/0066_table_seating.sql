-- =====================================================================
-- 0066_table_seating.sql
-- NaSeRy
--
-- Apply AFTER 0065.
--
-- Purpose (pre-defense, 2026-11-24): buyers may choose where they sit.
--
--   ticket_types.seating_mode / table_label / table_count / table_size
--     Optional, per ticket type (all NULL = off, the default: first come,
--     first served). Two layouts:
--       'tables': tables or spaces, e.g. "VIP Table" x 10 of 8 seats, or
--                 "Booth" x 30 of 1: tickets get "VIP Table 3", "Booth 12".
--       'rows':   rows of numbered seats, e.g. 10 rows x 20 seats: tickets
--                 get "Row C, Seat 7" (table_count = rows, table_size =
--                 seats per row; table_label is just "Row").
--     The seat goes in tickets.seat_label (0058): the buyer's choice or the
--     next free place. The capacity follows (count x size), set by the app.
--
--   events.floor_plan_path
--     Optional picture of the venue layout ("floor-plans/<uuid>.webp"),
--     shown above the table choice. Without one, the page draws a simple
--     layout itself.
--
-- Nothing else changes; the public views are untouched (the app reads the
-- new columns in system context). The existing table grants cover them.
--
-- Rollback: 0066_table_seating.down.sql
-- =====================================================================

BEGIN;

ALTER TABLE ticket_types
  ADD COLUMN seating_mode text NULL CHECK (seating_mode IS NULL OR seating_mode IN ('tables', 'rows')),
  ADD COLUMN table_label text NULL CHECK (table_label IS NULL OR char_length(table_label) BETWEEN 1 AND 30),
  ADD COLUMN table_count integer NULL CHECK (table_count IS NULL OR table_count BETWEEN 1 AND 200),
  ADD COLUMN table_size integer NULL CHECK (table_size IS NULL OR table_size BETWEEN 1 AND 100),
  ADD CONSTRAINT ticket_types_tables_all_or_none CHECK (
    (seating_mode IS NULL AND table_label IS NULL AND table_count IS NULL AND table_size IS NULL)
    OR (seating_mode IS NOT NULL AND table_label IS NOT NULL AND table_count IS NOT NULL AND table_size IS NOT NULL));

ALTER TABLE events
  ADD COLUMN floor_plan_path text NULL;

COMMIT;
