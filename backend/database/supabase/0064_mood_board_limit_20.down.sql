-- Rollback of 0064_mood_board_limit_20.sql: back to at most 8 pictures.
-- Boards with more than 8 keep their first 8; the other pictures' files are
-- left on disk and nasery:media:prune removes them on its next run.

BEGIN;

UPDATE inquiries
   SET mood_board = (SELECT COALESCE(jsonb_agg(e ORDER BY i), '[]'::jsonb)
                       FROM jsonb_array_elements(mood_board) WITH ORDINALITY AS x(e, i) WHERE i <= 8)
 WHERE jsonb_array_length(mood_board) > 8;

ALTER TABLE inquiries DROP CONSTRAINT inquiries_mood_board_check;
ALTER TABLE inquiries ADD CONSTRAINT inquiries_mood_board_check
  CHECK (jsonb_typeof(mood_board) = 'array' AND jsonb_array_length(mood_board) <= 8);

COMMIT;
