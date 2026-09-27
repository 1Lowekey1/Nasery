# NaSeRy — Current Handoff

Date: 2026-09-22

## Current phase
B0 — Backend Foundation.

## Stack
Nuxt PWA → Laravel 13.19.0 → Supabase PostgreSQL.
PayMongo for payments.
Sanctum for SPA authentication.
API prefix /api/v1.

## Completed
Database architecture and finalized migration package through 0026.
RLS/security corrections completed.
Final public-view security migration completed.
Supabase Advisor warnings resolved.

## Immediate task
Produce/implement the detailed B0 Technical Specification.

It should settle:
- Laravel project structure
- package/dependency decisions
- .env variables
- DB connection
- Sanctum configuration
- CORS/CSRF
- middleware
- rate limiter classes
- response/error handling
- exception handling
- idempotency mechanism
- queue driver
- logging
- audit foundation
- tests
- security baseline
- local/staging/production setup

## Do not
- redesign finalized DB silently
- use old prototype DB as authority
- let frontend determine authorization or payment success
- introduce V1 internal chat
- introduce organizer expense/profit accounting
- resurrect obsolete migration variants
- invent missing requirements

## Integration strategy
Backend proceeds from final DB. Frontend work can continue. Integrate module-by-module:
DB → backend → API → frontend → tests → complete.

Claude Code can accelerate integration later, but must follow the architecture.
