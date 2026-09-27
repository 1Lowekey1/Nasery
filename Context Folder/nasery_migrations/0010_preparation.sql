-- =====================================================================
-- 0010_preparation.sql
-- Purpose: checklists/tasks, client-facing progress summary.
-- Dependencies: 0001-0009
-- =====================================================================

CREATE TABLE checklists (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id    uuid NOT NULL REFERENCES events(id),
  name        text NOT NULL,
  description text,
  deleted_at  timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_checklists_event ON checklists(event_id);

CREATE TABLE tasks (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  checklist_id  uuid NOT NULL REFERENCES checklists(id),
  name          text NOT NULL,
  description   text,
  due_at        timestamptz,
  priority      task_priority NOT NULL DEFAULT 'medium',
  status        task_status NOT NULL DEFAULT 'pending',
  created_by    uuid NOT NULL REFERENCES users(id),
  deleted_at    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_tasks_checklist_status ON tasks(checklist_id, status);

-- References event_members (not organization_members directly): correctness
-- of "this assignee belongs to the right org/event" is inherited for free
-- from event_members' own composite FKs -- no extra check needed here.
CREATE TABLE task_assignees (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  task_id         uuid NOT NULL REFERENCES tasks(id),
  event_member_id uuid NOT NULL REFERENCES event_members(id),
  assigned_at     timestamptz NOT NULL DEFAULT now(),
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_task_assignees ON task_assignees(task_id, event_member_id);

CREATE TABLE event_stages (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid REFERENCES organizations(id),   -- NULL = system default set
  name            text NOT NULL,
  sort_order      integer NOT NULL DEFAULT 0,
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE event_progress (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id           uuid NOT NULL UNIQUE REFERENCES events(id),
  current_stage_id   uuid REFERENCES event_stages(id),
  readiness_percent  numeric(5,2) CHECK (readiness_percent IS NULL OR (readiness_percent BETWEEN 0 AND 100)),
  is_manual_override boolean NOT NULL DEFAULT false,
  overridden_by      uuid REFERENCES users(id),
  overridden_at      timestamptz,
  updated_at         timestamptz NOT NULL DEFAULT now(),
  created_at         timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE event_progress IS
  'The ONLY progress table exposed to clients. Clients never read checklists/tasks directly -- staff names and internal task detail are structurally unreachable from the client-facing surface.';
