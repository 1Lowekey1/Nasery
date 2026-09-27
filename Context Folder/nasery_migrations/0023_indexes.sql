-- =====================================================================
-- 0023_indexes.sql
-- Purpose: additional indexes not already created inline with their
--          tables (0004-0021 already created the load-bearing ones:
--          capacity, webhook idempotency, ownership, settlement
--          exclusivity, quotation exclusivity, subscription exclusivity,
--          duplicate check-in, composite-FK support indexes). This file
--          adds the remaining reporting/lookup indexes.
-- Dependencies: 0001-0022
-- =====================================================================

-- Marketplace search
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_organizations_display_name_trgm ON organizations USING gin (display_name gin_trgm_ops);

-- Reporting date-range scans
CREATE INDEX idx_tickets_created_at       ON tickets(created_at);
CREATE INDEX idx_payments_paid_at         ON payments(paid_at);
CREATE INDEX idx_registrations_created_at ON registrations(created_at);

-- RLS helper performance (backing user_org_ids() / admin_has_active_grant())
CREATE INDEX idx_org_members_user_org_status ON organization_members(user_id, organization_id, status);

-- Ticket/QR lookup at the scan gate
CREATE INDEX idx_qr_tickets_ticket_id ON qr_tickets(ticket_id);

-- Audit log filtering by action
CREATE INDEX idx_audit_logs_action ON audit_logs(action);

-- Notification lookup by type (for preference joins)
CREATE INDEX idx_notification_preferences_type ON notification_preferences(notification_type);
