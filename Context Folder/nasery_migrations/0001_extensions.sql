-- =====================================================================
-- 0001_extensions.sql
-- Purpose: enable required PostgreSQL extensions.
-- Dependencies: none.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid()
