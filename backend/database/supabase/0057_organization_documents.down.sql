-- Rollback of 0057_organization_documents.sql. The uploaded files stay in
-- storage/app/documents until removed by hand.
BEGIN;

DROP TABLE IF EXISTS organization_documents;

COMMIT;
