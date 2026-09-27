-- =====================================================================
-- 0030_inquiry_matching.sql
-- NaSeRy
--
-- Apply AFTER 0029.
--
-- Purpose (B4 Inquiry; each item approved by the team 2026-09-24):
--   1. Open-posting inquiries with a client-set location range, as the paper
--      describes ("organizers ... within the client's location range"):
--      optional latitude / longitude / match_radius_km on inquiries.
--      A direct inquiry (client -> chosen organizers) leaves them NULL; an
--      open posting stores the event pin and the radius used to match.
--   2. Close an RLS gap: policy inquiry_orgs_org (FOR ALL) let any member
--      INSERT an inquiry_organizations row for their own organization into
--      ANY inquiry, then read that inquiry via inquiries_org_read. Invitations
--      are now created only through the server's controlled path; members may
--      only update their response on invitations they received.
--   3. Admin-editable setting for the auto-close window of open inquiries.
--
-- No existing column, policy, function or row is changed.
-- Rollback: 0030_inquiry_matching.down.sql
-- =====================================================================

BEGIN;

-- 1. Optional location + range for matching ----------------------------
ALTER TABLE inquiries
  ADD COLUMN latitude        numeric(9,6),
  ADD COLUMN longitude       numeric(9,6),
  ADD COLUMN match_radius_km numeric(6,1);

ALTER TABLE inquiries
  ADD CONSTRAINT chk_inquiries_latitude  CHECK (latitude  IS NULL OR latitude  BETWEEN -90  AND 90),
  ADD CONSTRAINT chk_inquiries_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
  ADD CONSTRAINT chk_inquiries_coords_pair CHECK ((latitude IS NULL) = (longitude IS NULL)),
  ADD CONSTRAINT chk_inquiries_radius CHECK (
    match_radius_km IS NULL
    OR (match_radius_km > 0 AND match_radius_km <= 500 AND latitude IS NOT NULL)
  );

COMMENT ON COLUMN inquiries.match_radius_km IS
  'Open posting only: organizers whose profile pin lies within this distance of (latitude, longitude) were invited. NULL for direct inquiries.';

-- nasery_app already holds table-level SELECT/INSERT/UPDATE/DELETE on
-- inquiries (0027), which covers the new columns.

-- 2. Invitations: no self-invites ---------------------------------------
REVOKE INSERT, UPDATE, DELETE ON inquiry_organizations FROM nasery_app;
GRANT UPDATE (status, decline_reason, responded_at, updated_at)
  ON inquiry_organizations TO nasery_app;

-- 3. Auto-close window (days) -------------------------------------------
INSERT INTO platform_settings (key, value, description)
SELECT 'inquiry_auto_close_days', '30'::jsonb,
       'Open inquiries with no accepted quotation are cancelled after this many days'
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE key = 'inquiry_auto_close_days');

COMMIT;

-- =====================================================================
-- END 0030
-- =====================================================================
