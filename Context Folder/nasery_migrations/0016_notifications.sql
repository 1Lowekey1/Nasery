-- =====================================================================
-- 0016_notifications.sql
-- Dependencies: 0001-0015
-- =====================================================================

CREATE TABLE notifications (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  recipient_user_id  uuid NOT NULL REFERENCES users(id),
  type               text NOT NULL,
  title              text NOT NULL,
  body               text,
  data               jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_critical        boolean NOT NULL DEFAULT false,
  read_at            timestamptz,
  deleted_at         timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_notifications_recipient_read ON notifications(recipient_user_id, read_at);

CREATE TABLE notification_preferences (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL REFERENCES users(id),
  notification_type text NOT NULL,
  channel           notification_channel NOT NULL,
  is_enabled        boolean NOT NULL DEFAULT true,
  created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_notification_preferences ON notification_preferences(user_id, notification_type, channel);

CREATE TABLE notification_deliveries (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  notification_id uuid NOT NULL REFERENCES notifications(id),
  channel         notification_channel NOT NULL,
  status          delivery_status NOT NULL DEFAULT 'queued',
  attempt_count   integer NOT NULL DEFAULT 0,
  last_error      text,
  sent_at         timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_notification_deliveries ON notification_deliveries(notification_id, channel, status);

COMMENT ON COLUMN notifications.is_critical IS
  'Critical notifications bypass notification_preferences entirely at the application layer -- they are '
  'never "checked and found enabled," they simply are not subject to the preference lookup.';
