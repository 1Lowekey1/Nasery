# NaSeRy — Backend Conventions

## API
Business API prefix: /api/v1.

Success:
{
  "success": true,
  "data": {},
  "message": "..."
}

Error:
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "...",
    "details": {}
  }
}

Use semantically correct HTTP status codes; 409 for state/capacity/idempotency conflicts.

## Auth
Sanctum SPA cookie/session authentication. Nuxt → Laravel → PostgreSQL. Never make frontend authorization authoritative.

## Validation
Frontend for UX + Laravel for trust boundary + PostgreSQL constraints for integrity.

## Transactions
Required for multi-step invariant-sensitive operations: quotation acceptance, ownership transfer, ticket holds/checkout, capacity changes, transfers, check-in, payment/subscription state changes.

## Idempotency
Use for payment creation, checkout, webhook processing, ticket holds/transfer, quotation acceptance, and other retry-sensitive critical POST operations.

## Rate-limit starting points
Login 5/min/IP.
Registration 5/hour/IP.
Password reset request 5/hour/IP.
Email verification resend 5/hour/user.
Public marketplace 60/min/IP.
Normal authenticated APIs 120/min/user.
Sensitive state-changing operations 30/min/user.
Checkout 10/min/user or IP.
QR/check-in initially ~120/min/staff user, subject to testing.
Webhooks are not ordinary user traffic; prioritize signature verification and idempotency.

These are centralized starting values, not scattered permanent constants.

## CORS/CSRF
Known Nuxt origins only. No wildcard credentialed CORS. Keep Sanctum/Laravel CSRF protection.

## PayMongo
Frontend success pages are informational. Backend/webhook state is authoritative. Verify webhook authenticity, deduplicate by provider event ID, persist/process safely, and queue non-interactive follow-up work.

## Logging
Application logs are technical/operational. audit_logs are business accountability. Never log credentials/secrets/tokens.

## Queues
Establish queue infrastructure in B0 for notifications, email, reports/exports and other slow asynchronous work.

## Testing
Every module needs unit/API/authorization/security/business-rule tests. Concurrency-sensitive invariants need tests too.

## Environments
Local → Staging/Test → Production. Separate Supabase and PayMongo credentials/data where appropriate. Never commit secrets.
