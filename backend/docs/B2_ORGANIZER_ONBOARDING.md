# B2 — Organizer onboarding & approval (spec + handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B3 Marketplace**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Application content | Profile only (legal + display name, brand name, description, address, contact email/mobile) + offered services. **Permits/documents are collected by email**; the admin review screen shows the owner's verified email with an "Email owner for documents" action. | user |
| Organizations per account | A business account owns **at most one** organization (409 `ORGANIZATION_EXISTS`); it can still join others as staff (B8). | user |
| Column-level backstop | Migration **0028** (applied): `nasery_app` can update only names/slug on organizations; names/phone/password on users; role/status/removal on members (never ownership flags). | user approved |
| Who manages settings | Owners (incl. co-owners). The permission catalog has no org-settings key, so staff can view but not manage. | schema |
| Admin review access | Admins read organizations under RLS; profile/services/owner of an **application** are read in the logged system context after the admin check. Other tenant data still needs an admin access grant (B17). | derived, flagged |

## Flow

1. Verified business account → `POST /organizations` (system context: RLS has no path to create a tenant): organization (`pending`), primary-owner membership, initial `ownership_transfers` row, profile, `organizer_approval_requests` submission, audit `organization.created`.
2. Admin → `POST /admin/organizer-applications/{org}/decision` via `set_organizer_approval_status()` (re-checks admin, requires reasons for reject/suspend, writes history + audit, syncs `is_suspended`). The row is locked first, so concurrent decisions serialize.
3. Rejected → owner fixes profile → `POST /organizations/{org}/approval/resubmit` (status + history written together) → pending again.

Admin transitions: `pending → under_review | approved | rejected`, `under_review → approved | rejected`, `approved → suspended`, `suspended → approved`. Anything else: 409 `INVALID_STATE_TRANSITION` with the allowed list. `Organization::isOperational()` (approved, not suspended) is the gate later modules use.

## Endpoints (`/api/v1`, all `auth:sanctum` + `active` + `verified`)

| Method | Path | Who |
|---|---|---|
| POST | `organizations` | business account (onboarding) |
| GET | `organizations/mine` | business account |
| GET / PATCH | `organizations/{org}` | member / owner |
| GET / PATCH | `organizations/{org}/profile` | member / owner |
| GET / POST / PATCH / DELETE | `organizations/{org}/services[/{service}]` | member / owner |
| GET | `organizations/{org}/approval` | member (status, can_resubmit, history) |
| POST | `organizations/{org}/approval/resubmit` | owner |
| GET | `services` | platform catalog (empty until admins curate it, B17) |
| GET | `admin/organizer-applications[?status&search]` | admin (+ `meta.counts` per status) |
| GET | `admin/organizer-applications/{org}` | admin |
| POST | `admin/organizer-applications/{org}/decision` | admin (idempotent) |

`{org}` is resolved under RLS: another tenant's organization is a 404, not a 403.

## New building blocks

- `App\Support\Authorization\OrganizationAccess`: membership → role permissions → overrides (owners = all). Used by `OrganizationPolicy` now, and by every tenant module later.
- `account:business|admin|client` middleware (`ACCOUNT_TYPE_NOT_ALLOWED`).
- Models: `Organization`, `OrganizationMember`, `OrganizationProfile`, `OrganizationService`, `Service`, `OrganizerApprovalRequest`, `OwnershipTransfer`.
- Commands: `nasery:admin:create` (platform admins are never self-registered), `nasery:db:sql` (dry-run/apply raw SQL with backup), `nasery:test-data:purge` (removes `@nasery.test` accounts and their organizations; backup first).

## Frontend

`useOrganization()`, `useOrganizerApplications()`; guard sends business users without an organization to `/organizer/onboarding`; `OrganizerApprovalBanner` (pending / needs changes with reviewer's note + resubmit / suspended) on every organizer page; sidebar shows the real organization name; admin `/admin/organizers` (status counts, filter, search, pagination) and `/admin/organizers/{id}` (profile, services, history, owner contact, allowed decisions with required reasons) now use real data. Organizer-home stats and the profile side panel (tags, specialties) are still mock → B3/B6/B15.

## Verified

- Full suite (B0–B2): **141 tests / 635 assertions pass**; `nasery:doctor` passes (incl. the 0028 check); no test data left.
- `tests/Feature/Organizations/*`: 26 tests, including tenant isolation (404 on other tenants' orgs/services), staff view-only, **database refuses self-approval and self-promotion to owner even with raw SQL** (0028), DB function refuses non-admin callers, invalid transitions, resubmission, suspension/reinstatement, idempotent decisions.
- Real browser: organizer sign-in → onboarding → pending banner; admin queue/counts → review → reject with reason; organizer sees the note → resubmit; admin approves. Test data purged afterwards.

## Open

- **Service catalog is empty** (`services` not seeded). Organizers use custom service names for now; seeding/curating the catalog belongs to B17 (or a seed migration if the team wants a starter list).
- **Inquiry matching (B4)** needs service tags + location range. The schema has lat/long on profiles and services per organization, but no service-radius column (the prototype had `service_radius_km`). Decide in B4.
- Email notifications for approval decisions → B13 (Notifications).
- Local dev: the in-app browser pane is hidden, so Vue's out-in page transitions never finish there; a normal browser is unaffected.
