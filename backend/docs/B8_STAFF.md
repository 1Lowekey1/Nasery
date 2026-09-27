# B8: Staff access (spec and handoff)

Status: **implemented and verified 2026-09-25**. Next phase: **B9 Ticketing / registration / QR**. No migration was needed.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Adding teammates | The owner (or anyone with `staff.manage`) **invites an existing NaSeRy business account by email** and picks a role. The invitee sees the invitation after signing in: a business account without an organization lands on onboarding, where invitations are listed with **Join team / Decline**. Invitation emails come with B13. | user |
| One organization per account | An account is the owner of one organization **or** a member of one team. Accepting an invitation lapses the others; onboarding refuses to create an organization for someone already on a team; nobody can invite a person who already belongs to one. | user (extends B2) |
| Roles | Organization-wide permission sets (`organization_roles` + `role_permissions`). **Five suggested presets** (Event Manager, Coordinator, Sales, Check-in Staff, Finance) are copied into the organization on first use; custom roles pick from the 14 permission keys. A role can't be deleted while members use it. Owners hold every permission without a role. | 0005 + paper |
| Event scope | **Event team** (`event_members`): a teammate added to an event, with an optional label and **event-only permissions** (edit event, checklists, tasks, tickets, guest list, check-in). `canOnEvent()` = organization-wide permission **or** the same permission on that event. Members **without any organization-wide event permission only see events they're assigned to** (lists filter them; direct links return 404). | paper ("access to assigned events") |
| Task assignment | Tasks are assigned to people on the event's team (`task_assignees`). **Assignees may change their own task's status** without `task.manage` (paper: "team members … update task progress"); nothing else. Removing someone from an event unassigns their tasks there. | paper |
| My Tasks | `/organizer/my-tasks` lists the member's tasks across open events; `?task=` highlights one (the paper's "task link"). | paper |
| Deactivation | Deactivating a member ends their access immediately and deactivates their event memberships; they can be reactivated unless they've joined another organization since. Pending invitations are cancelled. Owners are managed only through ownership transfer. | integrity |
| Escalation guard | A non-owner with `staff.manage` may only hand out permissions they hold themselves, never `staff.manage`, and can't change owners or themselves. | security |
| System context | Used for: the invite insert (the business-account trigger reads `users`, which RLS hides), the invitee accepting or declining (they aren't an active member yet), event-team writes (the RLS insert policy requires an event permission a non-member owner lacks), and reading teammates' names and emails. Laravel checks come first every time. | RLS |

## Endpoints

Business accounts (`account:business`):

| Method | Path | Notes |
|---|---|---|
| GET | `me/invitations` | Your pending invitations (organization, role, who invited). |
| POST | `me/invitations/{id}/accept`, `…/decline` | |
| GET | `organizations/{org}/team` | Members (active, invited, deactivated), roles, presets, permission catalog, `can_manage`. |
| POST | `organizations/{org}/team/invitations` | `{email, role_id \| preset}` |
| PATCH / DELETE | `organizations/{org}/team/{member}` | Change role; deactivate or cancel invite. |
| POST | `organizations/{org}/team/{member}/reactivate` | |
| POST / PATCH / DELETE | `organizations/{org}/roles[/{role}]` | `{name, description?, permissions[]}` |
| GET / POST / DELETE | `organizations/{org}/events/{event}/team[/{eventMember}]` | `{organization_member_id, label?, permissions[]}` (event permissions only). |
| PUT | `organizations/{org}/events/{event}/tasks/{task}/assignees` | `{event_member_ids[]}`, members of that event's team. |
| GET | `organizations/{org}/my-tasks` | |

Changed payloads:
- `my_membership` now carries `role`, `permissions[]` (effective, organization-wide) and `sees_all_events`.
- Single-event responses carry `can: {update, publish, complete, cancel, manage_team}`.
- The preparation payload carries `can`, `team[]`, and per-task `assignee_ids[]` and `mine`.

## Frontend

- `pages/organizer/team.vue`:
  - invite by email with a role picker (`components/RoleSelect.vue`: your roles plus the suggested presets);
  - member list with role change, deactivate, reactivate and cancel invite;
  - roles, including a custom role builder.
- `pages/organizer/onboarding.vue`: pending invitations shown first (Join team / Decline).
- `pages/organizer/my-tasks.vue`: your tasks with a status selector, overdue flag, and link to the event's preparation.
- `components/organizer/EventTeam.vue` on the event page: add a teammate with a label and event-only permissions, or remove them.
- Preparation page: an assignee picker per task; every control is gated by `can` (assignees can move only their own tasks).
- Dashboard nav is permission-aware: My Tasks and Team for everyone; Business Profile for owners; Analytics needs `finance.view`; Inquiry & Bidding needs `inquiry.manage` or `quotation.manage`. "Create New Event" needs `event.create`.

## Tests

- `tests/Feature/Staff/StaffTest.php` (7 tests):
  - invite, accept, role permissions and one organization per account (the other invitation lapses, no new organization, can't be re-invited elsewhere)
  - invitation rules (unknown email, client account, self, duplicate, decline then re-invite, someone else's invitation)
  - escalation guard
  - deactivation and reactivation
  - limited staff see and work only on assigned events, with event-level `task.manage`; non-event permissions refused per event
  - task assignment: My Tasks, status-only updates, not other people's tasks, unassigning when removed from the event
  - role management
- B6 and B7 tests updated: an unassigned permission-less staff member now gets 404 on events; once on the event team, they can view.

## Open items

- **B13**: invitation and task-assignment emails with the link to My Tasks.
- **B11**: check-in uses `canOnEvent(…, 'checkin.perform')` for door staff.
- Per-member permission overrides (`member_permission_overrides`) are honored by authorization but have no screen yet; roles cover the prototype.
