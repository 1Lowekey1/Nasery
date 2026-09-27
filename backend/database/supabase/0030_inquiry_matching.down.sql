-- Rollback for 0030. Dropping the columns discards stored inquiry pins/radii.
BEGIN;

DELETE FROM platform_settings WHERE key = 'inquiry_auto_close_days';

REVOKE UPDATE ON inquiry_organizations FROM nasery_app;
GRANT INSERT, UPDATE, DELETE ON inquiry_organizations TO nasery_app;

ALTER TABLE inquiries
  DROP CONSTRAINT IF EXISTS chk_inquiries_radius,
  DROP CONSTRAINT IF EXISTS chk_inquiries_coords_pair,
  DROP CONSTRAINT IF EXISTS chk_inquiries_longitude,
  DROP CONSTRAINT IF EXISTS chk_inquiries_latitude,
  DROP COLUMN IF EXISTS match_radius_km,
  DROP COLUMN IF EXISTS longitude,
  DROP COLUMN IF EXISTS latitude;

COMMIT;
