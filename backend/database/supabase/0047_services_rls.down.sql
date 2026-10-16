-- Rollback of 0047_services_rls.sql. Only for a database built from the SQL
-- files; on Supabase RLS was already enabled before 0047, so don't run this there.
BEGIN;
ALTER TABLE services DISABLE ROW LEVEL SECURITY;
COMMIT;
