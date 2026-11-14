-- =====================================================================
-- 0057_organization_documents.sql
-- NaSeRy
--
-- Apply AFTER 0056.
--
-- Purpose (advisor, 2026-11): new organizers upload their proof of business
-- (permit, DTI/SEC, BIR, valid ID) in NaSeRy instead of e-mailing it, and the
-- admin reviews it on the application page.
--
--   organization_documents   one uploaded file
--     doc_type       business_permit | registration | bir | valid_id | other
--     original_name  the file's name when uploaded (shown, never used as a path)
--     stored_path    <organization id>/<uuid>.<ext> under the private documents
--                    folder, outside the public web folder and never sent to
--                    the browser
--
--   Writes happen only in Laravel's logged system context after its own
--   checks (organization owner). Reads under RLS: platform admins and
--   members of the organization; Laravel narrows that to owners and admins.
--
-- Rollback: 0057_organization_documents.down.sql
-- =====================================================================

BEGIN;

CREATE TABLE organization_documents (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id  uuid NOT NULL REFERENCES organizations(id),
  uploaded_by      uuid NOT NULL REFERENCES users(id),
  doc_type         text NOT NULL CHECK (doc_type IN ('business_permit', 'registration', 'bir', 'valid_id', 'other')),
  original_name    text NOT NULL CHECK (char_length(btrim(original_name)) BETWEEN 1 AND 200),
  stored_path      text NOT NULL UNIQUE,
  mime_type        text NOT NULL CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png')),
  size_bytes       bigint NOT NULL CHECK (size_bytes > 0),
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_organization_documents_org ON organization_documents (organization_id, created_at DESC);

ALTER TABLE organization_documents ENABLE ROW LEVEL SECURITY;

CREATE POLICY organization_documents_admin_read ON organization_documents FOR SELECT
  USING (is_platform_admin());

CREATE POLICY organization_documents_org_read ON organization_documents FOR SELECT
  USING (organization_id IN (SELECT user_org_ids()));

REVOKE ALL ON organization_documents FROM PUBLIC, anon, authenticated;
GRANT SELECT ON organization_documents TO nasery_app;

COMMENT ON TABLE organization_documents IS
  'Proof of business uploaded by organizers for their approval. Files live in a private folder, see stored_path.';

COMMIT;

-- =====================================================================
-- END 0057
-- =====================================================================
