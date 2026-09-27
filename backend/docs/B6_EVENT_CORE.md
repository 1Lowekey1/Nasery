# B6: Event core (spec and handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B7 Preparation** (checklists, tasks, readiness, client-facing progress).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Where events come from | (a) The **winning organizer converts** an accepted quotation into a draft event, once per inquiry; the inquiry becomes `converted_to_event`. Accepting a quote does not create an event on its own. (b) Organizers can also create **independent** events. | pack + paper ("accepted bid is converted into an official event record") |
| Event data | Name, type (platform default or the organizer's own), description, capacity, **venue** (name, address, optional map pin), **visibility** (`private` guest-list event or `public`, for B9 ticketing; default private), **event point person** (name, email, phone), timezone (default Asia/Manila). | paper + event form |
| Schedule | One or more **sessions** (`starts_at` / `ends_at`). The API takes local times (`2027-01-24T17:00`) in the event timezone, stores them in UTC and returns ISO times plus `starts_local` / `ends_local` for form inputs. New or moved sessions can't start in the past; a session can last at most 31 days. | schema |
| Lifecycle | `publish` draft→published (needs ≥1 session) · `unpublish` published→draft · `start` published→ongoing · `complete` published/ongoing→completed (only after the first session began) · `cancel` from draft, published or ongoing, with a reason (upcoming sessions are cancelled too). Completed and cancelled are terminal (DB trigger; only `admin_override_event_status()`). | schema |
| Permissions | View: any active member. Create: `event.create`. Edit and sessions: `event.update`. Publish/unpublish: `event.publish`. Complete: `event.complete`. Cancel: `event.cancel`. Owners have all of them. Changes also need an operational organization. | 0024 permissions |
| Deleting | Only **independent drafts** (soft delete). A converted event, or one that has been published, is cancelled instead. | integrity |
| Client view | The client sees events made from their inquiries: schedule, venue, status ("Being planned", "Confirmed", …), organizer contact and the event point person. The organizer sees the client's full contact on converted events (they won the quote in B5). | privacy |
| Migration 0034 (applied) | Adds the venue, map pin, visibility and point-person columns with CHECKs. Adds `events_client_read` and `event_sessions_client_read` through the 0031 helper (no recursion; the doctor probes it). Adds a **unique live event per inquiry**. **Column grants**: the app role can't write `inquiry_id`, `accepted_quotation_id`, `organization_id` or `created_by_member_id` after insert, and can't insert `status` or the links, so only the checked conversion step links an event to an inquiry. | user approved |

## Endpoints

**Organizer** (`account:business`):

| Method | Path | Notes |
|---|---|---|
| GET | `organizations/{org}/events?status[]&page` | Ordered by ongoing, published, draft, then the rest. |
| POST | `organizations/{org}/events` | Fields plus optional `sessions[{name?, starts_at, ends_at}]`. Idempotent. |
| POST | `organizations/{org}/quotations/{quotation}/event` | Convert a won quotation (same body). 409 if the quote wasn't accepted for this organization or the inquiry is already converted. Idempotent. |
| GET / PATCH / DELETE | `organizations/{org}/events/{event}` | PATCH is refused on terminal events; DELETE only for independent drafts. |
| POST | `…/events/{event}/{publish,unpublish,start,complete,cancel}` | `cancel` needs `{reason}`. |
| POST / PATCH / DELETE | `…/events/{event}/sessions[/{session}]` | A published event must keep at least one session. |

**Client** (`account:client`): `GET events` and `GET events/{event}` (their own events only, enforced by RLS).

My Offers quotations now include `event_id` and `inquiry.event_type_id`.

## Frontend

- `composables/useEvents.ts`: organizer and client APIs, status labels and classes, `sessionLabel()` (formats in the event timezone).
- `components/organizer/EventForm.vue`: a drawer to create, convert (prefilled with name, type, date, guest count as capacity, address, and the client as point person) or edit (details only; the schedule is managed on the event page).
- `components/event-management-tabs.vue`: "Create New Event" opens the real form; the tabs keep `?event=` when switching.
- `pages/organizer/eventmanagement/all-events.vue`: the real list with Upcoming, Completed, Cancelled and All filters.
- `pages/organizer/eventmanagement/event-details.vue` (`?event=`):
  - details, client banner, status actions (start and complete appear only once the event has begun), cancel with a reason, delete draft;
  - schedule management (add, edit and remove sessions).
- `pages/organizer/inquiries/offers.vue`: a won quote shows **Create the event**, or **Open the event** once created.
- `pages/client/event-details.vue` ("My Event" in the sidebar): the client's events. A converted inquiry links to it.

## Tests

`tests/Feature/Events/EventTest.php` (8 tests):
- creation with a schedule (UTC storage, +08:00 output, local values)
- validation: name, visibility, coordinate pairs, another organization's event type, end before start, a start in the past
- lifecycle: publish needs a session, completing early is refused, start then complete, terminal events are immutable, cancel needs a reason and cancels sessions
- session management, including that a published event keeps at least one session
- conversion: the losing organizer is refused, the inquiry becomes converted, a second conversion is refused, the client sees the event and nobody else does, a converted event can't be deleted
- deleting an independent draft
- staff can view but not change; suspended organizations are blocked
- the DB refuses linking an event to an inquiry directly (0034)

## Notes and open items

- **Browser pane (testing only):** when the Claude app window is behind other windows, the pane stops drawing and Nuxt's out-in page transition never finishes, so in-app navigation seems to stick. Direct loads work, and real browsers are unaffected.
- **B7** adds preparation (checklists, tasks, readiness) and the client-facing `event_progress`.
- **B9** uses `visibility` and `overall_capacity` for ticket tiers and registration; unpublishing will then need to be blocked once tickets exist.
- **B11** will likely move sessions and events to ongoing and completed from check-in activity; B6 does this manually.
