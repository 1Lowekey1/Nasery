-- Rollback of 0040_admin_access_requests.sql (drops the request history;
-- grants already created stay in admin_access_grants and expire normally).
BEGIN;
DROP TABLE IF EXISTS admin_access_requests;
COMMIT;
