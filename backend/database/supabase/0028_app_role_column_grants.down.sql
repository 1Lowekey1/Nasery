-- Rollback for 0028: restore the table-wide grants from 0027.
BEGIN;

REVOKE UPDATE ON users FROM nasery_app;
GRANT UPDATE ON users TO nasery_app;

REVOKE UPDATE ON organizations FROM nasery_app;
GRANT UPDATE ON organizations TO nasery_app;

REVOKE INSERT, UPDATE ON organization_members FROM nasery_app;
GRANT INSERT, UPDATE ON organization_members TO nasery_app;

COMMIT;
