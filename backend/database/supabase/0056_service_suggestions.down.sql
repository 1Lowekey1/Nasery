-- Rollback of 0056_service_suggestions.sql. Services already approved from
-- suggestions stay (they are ordinary catalog services), and organizers'
-- services that were linked to them keep that link.
BEGIN;

DROP TABLE IF EXISTS service_suggestions;

COMMIT;
