# Design merge: Sean's Oct 20 redesign + our current app

Branch `design-merge` (worktree `../Dev-design-merge`), started from `frontend-merge` @ 0b245a1
plus the two uncommitted patches of 2026-10-22 (demo event timing; profile photo on public pages,
My Tickets in the client portal).

Source of the redesign: `Development-Sean/nasery-client`, branch `ui-refresh-oct-2026`, commit
`e0a14ef` (2026-10-20). His `main` (7496c28, 2026-09-28) is what we merged in September, so it is the
common base for a three-way merge.

## Rules used

- **Design and layout: his.** It is the newer, more consistent look (teal hero banners, soft
  motion, ring stats, pill tabs, cleaner tables and dropdowns).
- **Behaviour and data: ours, always.** His pages call his old token backend (`/organizer/...`
  with Bearer headers); ours call `/api/v1`. Every feature added since September stays (uploads,
  request editing, revise offer, drafts, mobile fixes, dead-end fixes...).
- **Wording about money follows our server.** His labels ("from accepted quotations") describe his
  backend; ours say what our numbers are (NaSeRy fees + plans, ticket sales = organizers' money).
- **Nothing he shows is faked.** A field our server doesn't send is either wired up (small,
  read-only additions, listed below) or removed.

## Method

Line endings normalised, then `git merge-file ours base his` per file: his design edits and our
functional edits combine automatically where they touch different lines; overlaps were resolved
by hand (decisions below). Every merged page is checked for his old-backend calls, type-checked
(no new errors vs. the current app) and looked at on a private test site (API :8100 on this
worktree's backend, web :3101) signed in with demo accounts.

## Decisions so far

### Shared components and layouts
| Piece | Result |
|---|---|
| icon-base, SelectMultiple, superadmin page header, superadmin stat card | his (unchanged on our side) |
| FormsSelect | his look (checkmark, search only for lists > 8) + our typed props |
| superadmin avatar | his `soft` colours + our photo support |
| inquiry tabs | ours + his "Price List" tab, pointing at our `/organizer/price-list` |
| notifications dropdown | ours (his only adds routing for his backend's notification types) |
| ticketing tabs | his hero, ring stats, count-up, pill tabs + our data and our Payments tab |
| super admin layout | his teal portal badge |
| organizer layout (sidebar) | our routes (`nasery-access`, `billing`, `price-list`); his Business Profile entry and header button -> Business Profile page (to port) |
| client layout | his profile panel (wide, section rail, mobile chips, big header) with our three real sections (Identity, Contact, Appearance) and our save |

### Organizer pages
| Page | Result |
|---|---|
| Home | clean merge; revenue card stays ours ("Your Ticket Share": his "Quarter revenue" needs a field we don't send) |
| All events | his; his empty state + our "win one from an inquiry" link |
| Event details | his view + step-by-step edit with section menu; our cover photo behind his hero and our "Event cover photo" card; event types from our API (his came from his backend) |
| Ticket tiers, QR check-in, Walk-in counter | clean merge |
| Attendees | his stepped register form; our plain e-mail field (QR is e-mailed) instead of his e-mail verification; categories limited to Invited / Free with our note (paying guests buy online or at the Walk-in Counter); his Import button opens our import dialog |
| Attendance report | his table |
| Analytics overview | his split bar instead of the donut; our data loading and errors |
| Live events | his |

### Super admin pages
His styling everywhere (stat cards without colour tones, new table headers); our money wording.
- Event tags: his cards show "N organizers offer it" -> new read-only `organizers` count per type in
  `GET /admin/event-types` (no migration). Deleting asks with our dialog and his richer message.
- Organizer detail: his "support access" banner removed; our NaSeRy Access workspace panel already
  shows that state.

### Pages that needed hand-merging
| Page | Result |
|---|---|
| Offers | his cards, summary tiles and Won/Lost filter chips (counted from our data); our "client changed the request" banner, Revise offer, chat drawer, price breakdown |
| Match inquiries | ours (his quote calculator ran on his backend's price items; ours builds offers from our price list) |
| Preparation tracking (organizer) | his readiness ring, filter tabs, quick add, team rail; our reassign picker, "Request changes" note, confirm before delete, add-from-your-team (no passwords) |
| Activity timeline (organizer) | his page, rewired to our API; our "Client can see / Team only" switch + checkbox in his add form |
| Client My events | his design; Messages goes to the request's messages (`#messages` now scrolls there) |
| Client Post event | his layout; our missing-fields checks drive his progress checklist |
| Client preparation / timeline | his style; readiness, stage and milestones only (task list stays private) |

### His new pages, on our working pages
| His page | Our address | Notes |
|---|---|---|
| Business Profile | `/organizer/business-profile` (old `/organizer/profile` redirects) | his layout, map pin picker, profile strength, preview card, unsaved-changes bar; our listing toggle, logo/cover/photo uploads, catalog services with prices, own event types, owner-only editing |
| Team | `/organizer/team` | his crew header, badge/list views, filters, side panel; our e-mail invitations (no passwords), roles and permissions, remove with task hand-over, reactivate |
| NaSeRy Access | `/organizer/nasery-access` | his private/on hero with countdown, topics, durations, can/can't, history; our admin requests, invites, grants |
| Earnings | `/organizer/earnings` | his page on our numbers; monthly chart and per-event table on Starter/Pro (upgrade note on Free); server CSV |
| Reviews | `/organizer/reviews` | his page on our reviews; plus Report to NaSeRy and moderation chips |
| Plan & Billing | `/organizer/billing` | his hero, usage rings, plan cards; all our billing logic (credit quotes, renewals, scheduled switches) |
| Price List | `/organizer/price-list` | ours restyled in his look (ours has more: 5 pricing types, ordering, estimate settings, live preview) |

### Also fixed on the way
- Client My events: "Edit or cancel request" never showed (wrong status names). Fixed here **and in the main app** (`Dev/frontend/app/pages/client/my-events.vue`, uncommitted).
- Phones: All Events filter tabs and the Activity Timeline events panel no longer spill past the screen.

## Checks
- `nuxi typecheck`: no new errors (26 vs 29 in the current app).
- Every page above opened on the test site with demo accounts (Maria, Miguel, admin); no console errors; organizer pages checked at 375 px for sideways overflow.
- Not clicked through: anything that sends or saves for real (offers, invites, payments, access invites, reviews replies) beyond opening the forms.

## Not taken from his update
- His backend calls, token auth, e-mail verification on attendee registration, "Paid" attendee category, temporary passwords for staff, support-access banner on the admin organizer page (our workspace panel covers it), his quote calculator on Match inquiries.
