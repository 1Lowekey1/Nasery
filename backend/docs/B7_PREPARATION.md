# B7: Preparation and activity timeline (spec and handoff)

Status: **implemented and verified 2026-09-25**. Next phase: **B8 Staff access** (team members, event membership, task assignment and task links).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Checklist | An event has checklists; each has tasks with a name, description, due time (entered in the event's local time), priority (low, medium, high, urgent) and status (to do, in progress, done, blocked, not needed). A **starter checklist** adds six common preparation tasks (venue, suppliers, catering, logistics, guest list, final walkthrough). | paper ("venue confirmation, supplier bookings, catering, logistics…") |
| Readiness | Done tasks ÷ tasks not cancelled. Deleted tasks and checklists don't count; with no tasks it's shown as "no tasks yet". Recalculated on **every** change. The organizer can **override** it manually (work tracked outside NaSeRy) and switch back to automatic. | paper ("automatically computed … live") |
| Stages (0035 seed, applied) | Planning → Confirmed → Ready → Event day → Completed, derived automatically: draft = Planning; published = Confirmed, or Ready at 100%; ongoing = Event day; completed = Completed. A cancelled event keeps the stage it reached and is shown as cancelled. Each newly reached stage writes an `event.stage_reached` audit row. | user approved |
| Client surface | Only `event_progress` (readiness and stage) plus whitelisted milestones. **No task names, staff or amounts.** | pack + schema comment |
| Activity timeline | Built from `audit_logs` (no new table). **Organizer:** every activity on the event (checklists, tasks, schedule, status, stages), the inquiry it came from, and **their own** quotations, each with who did it. **Client:** "You sent your inquiry" → "You accepted X's quotation" → "X set up your event" → "Stage reached: …" (→ "The event was cancelled" + reason). | paper (role-differentiated timeline) + user |
| Audit timestamps | Audit rows now store the PHP wall-clock time with microseconds. PostgreSQL `now()` is fixed per transaction, so rows from one request used to share a timestamp and lose their order. Rows written by DB functions (`accept_quotation()`) still use the transaction time, which is correct in production because each request is its own transaction. | fix |
| Permissions | View: any active member. Checklists: `checklist.manage`. Tasks: `task.manage`. Readiness override: `event.update`. Writes need an operational organization. Everything becomes read-only once the event is completed or cancelled. Assignees and "task links" for team members come in **B8**. | 0024 permissions |

## Endpoints

**Organizer** (`organizations/{org}/events/{event}/…`; `{checklist}` and `{task}` are scoped to the event, so another event's task is a 404). Every write returns the whole preparation state.

| Method | Path | Notes |
|---|---|---|
| GET | `preparation` | readiness (effective and computed), manual flag, stage, stages, checklists with tasks, done/total |
| POST | `checklists` | `{name, description?}` or `{starter: true}` |
| PATCH / DELETE | `checklists/{checklist}` | DELETE also removes its tasks (soft delete) |
| POST | `checklists/{checklist}/tasks` | `{name, description?, due_at?, priority?, status?}` |
| PATCH / DELETE | `tasks/{task}` | status changes refresh readiness |
| PUT | `readiness` | `{readiness_percent: 0–100 \| null}`; null returns to automatic |
| GET | `timeline` | detailed activity, oldest first |

**Client:** `GET events/{event}/progress` returns `{readiness_percent, stage, stages, event_status, milestones[]}`.

Event payloads (B6) now include `readiness_percent`, `readiness_manual` and `stage`.

## Frontend

- `components/event/StageTrack.vue`: readiness bar and stage stepper (shared by organizer and client).
- `components/event/Timeline.vue`: vertical timeline (milestones highlighted).
- `components/organizer/EventPicker.vue`: event selector for per-event pages (keeps `?event=`; defaults to the first event).
- `pages/organizer/eventmanagement/preparation-tracking.vue`:
  - readiness and stages, manual override;
  - starter checklist, checklists and tasks (tick to finish, status menu, priority, due time with overdue warning, remove);
  - quick ticking of several tasks is safe: each task locks only while its own request runs, and overlapping updates reload once at the end.
- `pages/organizer/activity-timeline.vue`: detailed timeline with a "show only what the client sees" filter.
- `pages/organizer/eventmanagement/all-events.vue`: readiness bar, stage and a "Preparation" link per event.
- `pages/client/preparation-tracking.vue` and `pages/client/activity-timeline.vue`: `components/client/EventProgressView.vue` shows readiness with stages, or the milestone timeline (event picker when there are several).

## Tests

`tests/Feature/Events/PreparationTest.php` (4 tests):
- readiness follows the checklist: starter list, done, cancelled, deleted, due times in local time
- stages move with publish, override, clear, start and complete; finished events are read-only; 6 stage rows recorded
- the client sees milestones but never internal tasks or staff names; the organizer's timeline shows everything with actors; other clients get 404
- staff can view but not change; another organization's task isn't reachable through my event

## Open items

- **B8**: task assignees (`task_assignees` needs event members), a team member's own task list, and the paper's emailed "task link".
- **B13**: notifications when tasks are assigned or overdue.
