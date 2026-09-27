# B17: Admin (spec and handoff)

Status: **implemented 2026-09-26**. Migration **0040_admin_access_requests** (approved and applied after a backup; 0039 was proposed and declined, so the number is unused). Next phase: **B18 SaaS billing**.

Paper: "platform-wide oversight through an administration interface for organizer approvals, directory management, and activity monitoring"; "verified client reviews … with administrator moderation authority"; the admin "can monitor total users, total events, and check overall system activity". Context pack: "organizer approval, moderation, settings, announcements, metrics, controlled tenant access, audit".

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Admin access to a workspace | **Needs the organization's consent.** An admin requests access with a reason (1–72 h, default from settings); an **owner** allows or refuses. Owners can also **invite NaSeRy support** (for example, to debug an issue) and any admin accepts. Only then is an `admin_access_grants` row written, with `granted_by` = the consenting owner. Owners end access anytime; admins can end their own early. Unanswered requests lapse after 7 days. | team, 2026-09-25 (**replaces 0018's locked "single-admin self-service"**) |
| What access shows | **Read-only**: events (with client, tickets, sales), clients who booked, team, payments and refunds, all reviews. Each opening is audited for the organization. No admin write path uses grants. | user |
| Without a grant | **Totals only** for every organizer: events, completed events, tickets, ticket sales, rating, team size, owner e-mail. Events page lists public events, plus private ones only while access is granted. The Activity Log lists every action, but an organizer's own reasons and changed values show only while access is granted. | user |
| Reported reviews | A report alone changes nothing. An admin may **take the case**: the review becomes `disputed` and is **hidden from the profile and rating until decided**. Then **keep** (back to published; reports dismissed) or **hide** (reports upheld); a hidden review can be **restored**. A reason is required, and the client, owners and reporters are told. Each step is a `moderation_cases` row and an audit entry. | user (visibility: "only 0040", so no view change) |
| Refund fee claims | Refunds whose category returns the fee (force majeure, payment error) are listed for review. The admin marks each **reason holds** or **flag** (flag needs a note and tells the owners). No money moves; recorded as a moderation case on the refund. | user (B10 open item) |
| Platform settings | Whitelisted, bounded keys: checkout hold (now used by self-registration instead of the hard-coded 15 min), longest hold, admin access length, inquiry auto-close. **Platform fee**: a change closes the current version and starts a new one; earlier payments keep their snapshot. The fee can be switched off. | 0013/0018 |
| Accounts | Admins suspend or reactivate client and organizer accounts with a reason: signed out everywhere and e-mailed (critical). Admin accounts can't be suspended here. New admins are still created with `php artisan nasery:admin:create` (you enter the password yourself). | paper |
| Service catalog | Admins add, rename, archive and restore the platform services (no duplicate names). Archived services disappear from pickers; organizers keep existing picks. | B2 open item |
| Announcements | Title, message, audience (everyone including visitors / organizers / clients), start and optional end. Shown as a dismissible banner in the organizer, client and public layouts. | 0018 |

### 0040_admin_access_requests
New table `admin_access_requests`:
- Who asked: `initiated_by` (admin / organizer), `admin_user_id`, `requested_by`
- What and for how long: `reason` (5–1000 chars), `duration_hours` (1–72)
- Outcome: `status` (pending / approved / declined / cancelled / expired), `decided_by/at`, `decision_note`, `grant_id`, `expires_at` (7 days)
- Checks: an approved row must reference its grant. There is one pending request per admin per organization, and one pending support invite per organization.

RLS: admins and the organization's members can read. The app role has **SELECT only**; writes go through Laravel's system context after owner and admin checks. `admin_has_active_grant()` is unchanged (it still reads grants only), so a pending request never opens anything. Rollback: `0040_admin_access_requests.down.sql`. `nasery:doctor` checks it.

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/admin/overview`, `/admin/analytics?months=3\|6\|12\|24`, `/admin/events` | admin |
| GET | `/admin/organizer-applications[/{org}]` (now with `owner_email`, `totals`, `access`) | admin |
| GET / POST / DELETE | `/admin/access`, `/admin/organizations/{org}/access-requests`, `/admin/access-requests/{id}` (withdraw), `…/{id}/accept` (support invite), `/admin/access-grants/{id}` (end) | admin |
| GET | `/admin/organizations/{org}/workspace` | admin **with a live grant** (else 403) |
| GET / POST | `/admin/moderation/reviews?queue=open\|in_review\|hidden\|closed`, `…/{review}/start`, `…/decision {keep\|hide, reason}`, `…/restore {reason}` | admin |
| GET / POST | `/admin/moderation/fee-claims?status=…`, `…/{refund} {accepted\|flagged, note}` | admin |
| GET / PATCH / POST | `/admin/settings`, `/admin/settings/fee` | admin |
| GET / POST | `/admin/users`, `/admin/users/{id}/status {active\|suspended, reason}` | admin |
| GET / POST / PATCH / DELETE | `/admin/services[/{id}[/restore]]`, `/admin/announcements[/{id}]` | admin |
| GET | `/admin/activity` | admin |
| GET / POST / DELETE | `/organizations/{org}/nasery-access`, `…/requests/{id}/decision`, `…/invite[/{id}]`, `…/grants/{id}` | members read; owners act |
| GET | `/announcements` | anyone (audience by account type) |

Notifications:
- **Admins:** new/resubmitted applications, reported reviews, access answers and support invites (`admin.*`, a new "NaSeRy admin" preference group)
- **Owners:** `access.requested` (critical), `access.update`, `refund.claim_reviewed` (critical)
- **Everyone:** `review.moderated` and `account.notice` (critical, a new "Your account" group)

## Frontend
- **Admin layout:** Overview, Organizers, Workspace Access, Moderation, Events, Users, Analytics, Activity Log, Settings. The notification bell replaces the mock "System Online" badge, and the signed-in admin's name is shown.
- **Built on the groupmates' old admin layout, now with real data:**
  - **Overview:** KPI tiles, a "needs your attention" list, top organizers, recent activity
  - **Organizers:** directory with owner e-mail, events, sales and rating
  - **Organizer detail:** totals plus the **Workspace** panel: request access, pending/withdraw, or the read-only tabs with "End access now"
- **New admin pages:**
  - **Moderation:** reported-review queues and fee claims
  - **Events:** status counts; public and granted events
  - **Users:** search, filter, suspend and reactivate with a reason
  - **Analytics:** six monthly charts, reusing the B15 charts and palette, each with a table view
  - **Activity Log:** filters and expandable details
  - **Settings:** fee and limits, service catalog, announcements
- **Organizer:** a new **NaSeRy Access** page (sidebar) to allow or refuse requests, see who has access, end it, invite support, and see the history. The review badges now tell "under NaSeRy review" apart from "hidden by moderation".
- **Everyone:** `AnnouncementBanner` (dismissals remembered per browser).

## Tests
`tests/Feature/Admin/AdminTest.php` (4):
- **Consent:** totals open; the workspace is 403; the request is pending and gives no grant; staff can see it but not decide; the owner refuses and the admin is notified; ask again and approve, giving 24 h with `granted_by` = owner; the view is audited; the owner ends it; support invite accepted by an admin; access expires; unanswered requests lapse after a week.
- **Moderation:** report reaches the admins; take the case (disputed, hidden, client told); keep needs a reason, the review is back and the reporter told; a new report, hide, then restore; three cases and audit entries.
- **Fee claims and settings:** only the force-majeure refund is listed; flag needs a note; re-review refused; owner told. Settings bounds; checkout hold now 20 min. Fee 2% applies to new payments only; fee off means no snapshot. Totals, analytics and events numbers.
- **Accounts, catalog, announcements, activity:** suspend (blocked, told) and reactivate; admins can't be suspended; catalog add, duplicate refused, archive hides from the organizer picker, restore. Announcements reach only their audience and only within their dates. Activity hides an organizer's details without consent.

Related suites (reviews, organizations, notifications, ticketing, payments): 56/56 green after the B17 hooks.

**Verification (2026-09-26):** browser run on seeded data:
- **Admin:**
  - The overview totals matched: fee ₱5 = 1% of ₱1,000 minus ₱5 returned.
  - The directory columns were correct.
  - The request was sent and shown as waiting.
  - The reported review was taken (hidden), then kept with a reason.
  - The fee claim was flagged with a note.
  - Events hid the private wedding, and the Activity Log showed the admin actions with details.
- **Organizer:**
  - The announcement banner showed.
  - NaSeRy Access showed the request; **Allow** gave 4 h, "allowed by Owen Cruz", and it appears in the history.
  - Notifications arrived for the request, review under review, review kept and claim flagged.
  - The review is back on Reviews without a badge.
- **Admin again:**
  - The workspace showed 3 events, including the private wedding with its client, plus team, payments (₱1,000 sales, ₱500 refunded) and reviews.
  - Events now listed the private wedding "via your access".
  - "End now" returned 403 on the workspace again.
- E2E data purged.

Fixes made during the run:
- moderation follows the review to its new queue, and each list has its own loading state
- report statuses in plain words
- "Tickets Issued" instead of "Tickets Sold"
- workspace payment tiles fit narrow screens
- reasons no longer end in ".."

**Note on the demo server:** `php artisan serve` handles one request at a time, and each request goes to Supabase, so admin pages take a few seconds to load on the dev machine. This is not an issue with a real web server (php-fpm / Octane).

## Open items
- **Policy change to record:** 0018's comment still says "single-admin self-service". The consent flow above supersedes it (0040 comment and this doc). The schema comment wasn't edited.
- Admin roles (moderator vs support vs super admin) don't exist: every `account_type = admin` has all admin tools.
- `admin_override_event_status()` (0021) isn't exposed in the UI.
- `moderation_reports` (generic reports on organizers/events) is unused; only reviews can be reported.
- Suspending an **organization** still goes through the application decision (B2); suspending the owner's **account** is separate.
- Pending requests lapse lazily (when someone opens a list), not by a scheduled job.
