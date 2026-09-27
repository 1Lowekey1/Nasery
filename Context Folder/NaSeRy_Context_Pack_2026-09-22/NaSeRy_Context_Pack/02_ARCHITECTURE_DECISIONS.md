# NaSeRy — Locked Architecture Decisions

## Identity
One users table. Account distinction is membership/permission based. Client and organizer identities are separate. No separate client/organizer/staff/admin identity tables.

## Organization
Membership-based ownership. No organization_owners table. One active primary owner; co-owners allowed. Ownership transfer is transactional and audited. Historical staff remain attributable.

## Authorization
Order:
1. active organization membership
2. role permissions
3. member permission overrides
4. event membership/event permissions for event-scoped actions
event_role_label is display-only and never an authorization rule.

Laravel is primary authorization. PostgreSQL RLS is the non-bypassable backstop.

## Organizer approval
organizations.approval_status is authoritative current state. organizer_approval_requests is workflow/history, not an independent current-state authority.

## Marketplace
Public marketplace is exposed through controlled views:
public_organization_profiles
public_organization_services
public_event_types
public_reviews
Do not broadly grant anonymous access to base tables.

## Inquiry/quotation
One active quotation per organizer/inquiry. Acceptance locks the inquiry and rejects competing submitted quotations transactionally. Acceptance does not auto-create an event.

## Events
DRAFT → PUBLISHED → ONGOING → COMPLETED; non-terminal states can be CANCELLED. COMPLETED is terminal. Event configuration changes use audit_logs.

## Ticketing/capacity
Registrations are the purchase grouping. Admission type and registration source are independent. Capacity is derived and locked transactionally; cached_sold_count is never authoritative.

## Payments
payments stores individual PayMongo attempts. Each attempt has its own PayMongo payment intent/reference. At most one PAID payment per registration/invoice. Terminal payment states are not reset to PENDING. provider_event_id is unique for webhook idempotency.

## Fees/refunds
Fee is deducted from ticket subtotal, not added to customer checkout. Fee snapshots are immutable historical records. Refunds are separate append-only records and do not mutate original payment state.

## Communications
Notifications only in V1; no internal messaging/chat.

## Backend foundation
Laravel 13.19.0; direct PostgreSQL; Sanctum; /api/v1; risk-based rate limiting; standardized API responses; multi-layer validation; transactions; critical-operation idempotency; webhook authority; restricted CORS; CSRF; separate app/audit logging; queues; automated tests; local/staging/production.
