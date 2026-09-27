# NaSeRy — Backend Roadmap

Every chunk follows:
Database → Model → Business Rules → API → Authorization → Tests → Frontend integration.

B0 Foundation
- Laravel/PHP baseline
- PostgreSQL connection
- environments
- /api/v1
- Sanctum
- CORS/CSRF
- response/error conventions
- rate limiting
- idempotency
- queues
- logging/audit foundation
- testing/security baseline

B1 Authentication
- registration/login/logout
- current user
- email verification
- password reset/change
- sessions/account status

B2 Organizer onboarding/approval
B3 Marketplace
B4 Inquiry
B5 Quotation/bidding
B6 Event core
B7 Preparation
B8 Staff access
B9 Ticketing/registration/QR
B10 PayMongo
B11 Check-in
B12 Walk-ins/capacity
B13 Notifications
B14 Reporting
B15 Analytics
B16 Reviews
B17 Admin
B18 SaaS billing/entitlements

Do not build later modules by bypassing B0/B1 security conventions.
