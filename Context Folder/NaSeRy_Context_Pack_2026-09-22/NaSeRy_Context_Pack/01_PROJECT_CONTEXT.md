# NaSeRy — Project Context

## Product
NaSeRy is a multi-tenant event ticketing and management SaaS for event organizers and clients. Working thesis title: “NaSeRy, A Smart Web-Based Event Ticketing and Management System with Automated QR Check-In and Dynamic Seating Allocation.” QR is a feature, not necessarily the headline.

PWA: Nuxt. Native iOS/Android are out of scope.

## Users
- Organizer/business account
- Staff/team member
- Client/inquirer
- Attendee
- Platform Admin/Super Admin

Client and organizer are separate account types. Staff are organizer-created members with role/event/task permissions. Attendees can use public registration/tickets without a general platform account.

## Multi-tenancy
Organizations/workspaces use membership-based ownership:
- one primary owner
- zero+ co-owners
- staff/members
- roles, permissions, overrides, event-scoped permissions

Public data only is broadly visible. Private operational data is tenant-isolated.

## Scope
Organizer: dashboard, events/sessions, readiness/checklists, staff, public profile/directory, services/event types, inquiries, quotations/bidding, tickets/invitations, capacity, seating, guests, walk-ins, QR check-in, reports, revenue/platform-fee reporting, notifications, reviews, subscriptions.
Client: browse marketplace, inquiries, quotations, accept/decline/cancel where applicable, client-facing event progress, reviews.
Admin: organizer approval, moderation, settings, announcements, metrics, controlled tenant access, audit.

## V1 boundaries
No internal organizer-client chat. Contracts and detailed organizer-client finances occur externally. No organizer expense/net-profit accounting. No AI/analytics storage subsystem. No native apps.

## Inquiry/quotation
Client inquiries may target multiple organizers. One active quotation per organizer/inquiry. Acceptance is transactional and does not automatically create an event. Contract signing remains external. Decline/withdraw/cancel actions use reasons where applicable.

## Events
Event progress exposed to clients is controlled through event_progress; clients do not receive internal task/checklist details.

## Ticketing
Use “registration”, not “order”. A registration may contain multiple tickets.
Admission types: PAID, FREE, INVITED, COMPLIMENTARY.
Registration sources: ONLINE, ORGANIZER, STAFF, WALK_IN.
Ticket lifecycle: PENDING → VALID → USED; VALID → CANCELLED/INVALIDATED.
Transfer changes attendee in place; history is in ticket_transfers. No TRANSFERRED ticket status.

Capacity is backend authoritative. Active holds reserve capacity; VALID/USED count; CANCELLED/INVALIDATED do not. Capacity is enforced transactionally, not from cached counters.

## Payments
PayMongo. Backend/webhooks are authoritative; frontend never declares payment success.
Platform fee is deducted from ticket subtotal:
₱100 ticket → attendee pays ₱100 → ₱1 fee → organizer receives ₱99 → NaSeRy revenue ₱1.
Free/invited/complimentary admissions have zero fee/revenue.
Fee configs are versioned and snapshots preserve historical calculations.
V1 refunds are recorded externally; original payment remains PAID.

## SaaS
Freemium + paid plans + platform fees. Organizer subscriptions; clients/attendees free. Entitlements, configurable plans, downgrade data preservation, grace period before Free.

## Stack
Frontend: Nuxt PWA
Backend: Laravel 13.19.0
Database: Supabase PostgreSQL, accessed directly by Laravel
Payments: PayMongo
Auth: Laravel Sanctum SPA authentication
API: /api/v1
