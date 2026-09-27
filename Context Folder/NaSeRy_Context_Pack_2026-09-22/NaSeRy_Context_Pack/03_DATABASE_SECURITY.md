# NaSeRy — Database & Security

## Database authority
Supabase PostgreSQL is the data store. Laravel is the business-logic layer. PostgreSQL constraints/RLS are the integrity/security backstop.

## Migration sequence
0001_extensions
0002_types
0003_users
0004_organizations
0005_roles_permissions
0006_marketplace
0007_inquiries
0008_quotations
0009_events
0010_preparation
0011_ticketing
0012_payments
0013_platform_fees
0014_refunds
0015_attendance
0016_notifications
0017_reviews
0018_admin
0019_audit
0020_saas
0021_functions_triggers
0022_rls
0022b_rls_supplemental
0023_indexes
0024_seed
0025_security_integrity_corrections
0026_public_views_security_final

## Current database status
0022 RLS was corrected by removing the failed rls_helper role approach. 0022b, 0023, 0024, 0025 completed. Final 0026 completed successfully. Supabase Advisor SECURITY DEFINER warnings for the four public views were resolved.

0025 must not be casually rerun.
Older 0026 v3/v4/v5 variants are obsolete. Use 0026_public_views_security_final.sql.

## Final 0026
The four public views use SECURITY INVOKER:
- public_organization_profiles
- public_organization_services
- public_event_types
- public_reviews
The migration preserves existing expressions/types, grants SELECT to anon/authenticated on the views, and does not weaken base-table RLS.

## Major domains
Identity: users, organizations, organization_members, organization_roles, permissions, role_permissions, member_permission_overrides, event_members, event_member_permissions.
Marketplace: organization_profiles, services, organization_services, event_types.
Inquiry/quotation: inquiries, inquiry_organizations, quotations, quotation_items, quotation_status_history.
Events: events, event_sessions.
Preparation: checklists, tasks, task_assignees, event_stages, event_progress.
Ticketing: ticket_types, registrations, attendees, tickets, ticket_holds, ticket_transfers, qr_tickets.
Attendance: attendance_records, walk_in_records.
Payments: payments, payment_webhook_events, platform_fee_configs, platform_fee_snapshots, payment_refunds.
Notifications: notifications, notification_preferences, notification_deliveries.
Reviews: reviews, review_responses, review_reports, review_edits.
Admin: moderation_reports, moderation_cases, platform_settings, platform_announcements, admin_access_grants, organizer_approval_requests.
Audit: audit_logs.
SaaS: plans, features, plan_entitlements, subscriptions, subscription_invoices.

## Critical integrity
Composite foreign keys are used wherever possible for same-event/same-organization integrity. The nullable platform/default event-type ownership rule is the documented trigger exception.
Capacity, quotation acceptance, ownership transfer, and payment settlement are transactional invariants.
Secrets must never be logged/committed/exposed.
