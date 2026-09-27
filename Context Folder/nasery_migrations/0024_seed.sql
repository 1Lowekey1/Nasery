-- =====================================================================
-- 0024_seed.sql
-- Purpose: idempotent platform default data. No fake user accounts.
-- Dependencies: 0001-0023
-- =====================================================================

-- ---------------------------------------------------------------------
-- Permissions catalog
-- ---------------------------------------------------------------------
INSERT INTO permissions (key, category, description) VALUES
  ('event.create',            'event',      'Create a new event'),
  ('event.publish',           'event',      'Publish a draft event'),
  ('event.cancel',            'event',      'Cancel an event'),
  ('event.complete',          'event',      'Mark an event completed'),
  ('event.update',            'event',      'Edit event configuration'),
  ('ticket.manage',           'ticket',     'Create/edit ticket types and pricing'),
  ('attendee.manage',         'attendee',   'View/edit attendee records'),
  ('checkin.perform',         'checkin',    'Scan/manually check in attendees'),
  ('quotation.manage',        'quotation',  'Create/edit/submit quotations'),
  ('inquiry.manage',          'inquiry',    'View/respond to inquiries'),
  ('finance.view',            'finance',    'View revenue/payment/fee reports'),
  ('staff.manage',            'staff',      'Invite/assign/remove staff and event members'),
  ('checklist.manage',        'checklist',  'Create/edit checklists and tasks'),
  ('task.manage',             'checklist',  'Create/edit/assign tasks')
ON CONFLICT (key) DO NOTHING;

-- ---------------------------------------------------------------------
-- System default event types (organization_id IS NULL = platform default)
-- ---------------------------------------------------------------------
INSERT INTO event_types (organization_id, name, is_system_default) VALUES
  (NULL, 'Wedding',        true),
  (NULL, 'Birthday Party', true),
  (NULL, 'Corporate Event',true),
  (NULL, 'Conference',     true),
  (NULL, 'Concert',        true),
  (NULL, 'Debut',          true),
  (NULL, 'Christening',    true),
  (NULL, 'Other',          true)
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Platform fee configuration -- V1 deduction model, 1% default, no payer column
-- ---------------------------------------------------------------------
INSERT INTO platform_fee_configs (name, is_enabled, fee_model, percentage_rate, effective_from)
SELECT 'V1 Default Fee', true, 'percentage', 0.0100, now()
WHERE NOT EXISTS (SELECT 1 FROM platform_fee_configs WHERE effective_to IS NULL);

-- ---------------------------------------------------------------------
-- SaaS plans, features, entitlements
-- ---------------------------------------------------------------------
INSERT INTO plans (name, slug, price_monthly, price_yearly, is_active, is_default_free) VALUES
  ('Free',    'free',    0,      0,       true, true),
  ('Starter', 'starter', 999,    9990,    true, false),
  ('Pro',     'pro',     2999,   29990,   true, false)
ON CONFLICT DO NOTHING;

INSERT INTO features (key, name, value_type, description) VALUES
  ('active_event_limit',     'Active Event Limit',      'numeric', 'Maximum number of non-completed events at once'),
  ('total_event_limit',      'Total Event Limit',        'numeric', 'Maximum total events ever created'),
  ('staff_limit',             'Staff Limit',              'numeric', 'Maximum organization_members (excluding owner)'),
  ('advanced_reports',        'Advanced Reports',         'boolean', 'Access to advanced analytics/reporting'),
  ('custom_quotation_bidding','Custom Quotation/Bidding', 'boolean', 'Custom quotation terms/templates'),
  ('custom_event_types',      'Custom Event Types',       'boolean', 'Ability to create organization-specific event types'),
  ('workspaces',              'Multiple Workspaces',      'numeric', 'Number of organizations a user may own')
ON CONFLICT (key) DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN false WHEN 'custom_quotation_bidding' THEN false WHEN 'custom_event_types' THEN false END,
  CASE f.key WHEN 'active_event_limit' THEN 2 WHEN 'total_event_limit' THEN 10 WHEN 'staff_limit' THEN 3 WHEN 'workspaces' THEN 1 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'free'
ON CONFLICT DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN true WHEN 'custom_quotation_bidding' THEN false WHEN 'custom_event_types' THEN true END,
  CASE f.key WHEN 'active_event_limit' THEN 10 WHEN 'total_event_limit' THEN 100 WHEN 'staff_limit' THEN 15 WHEN 'workspaces' THEN 1 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'starter'
ON CONFLICT DO NOTHING;

INSERT INTO plan_entitlements (plan_id, feature_id, boolean_value, numeric_value)
SELECT p.id, f.id,
  CASE f.key WHEN 'advanced_reports' THEN true WHEN 'custom_quotation_bidding' THEN true WHEN 'custom_event_types' THEN true END,
  CASE f.key WHEN 'active_event_limit' THEN NULL WHEN 'total_event_limit' THEN NULL WHEN 'staff_limit' THEN NULL WHEN 'workspaces' THEN 3 END
FROM plans p CROSS JOIN features f WHERE p.slug = 'pro'
ON CONFLICT DO NOTHING;
-- NULL numeric_value convention: unlimited. Document this explicitly at the application layer.

-- ---------------------------------------------------------------------
-- Platform settings defaults
-- ---------------------------------------------------------------------
INSERT INTO platform_settings (key, value, description) VALUES
  ('admin_grant_default_expiry_hours', '24',   'Default lifetime of a self-service admin access grant'),
  ('ticket_hold_default_minutes',      '15',   'Default ticket hold duration before expiry'),
  ('ticket_hold_max_minutes',          '60',   'Platform-wide ceiling an organizer may configure for hold duration'),
  ('subscription_grace_period_days',   '3',    'Grace period after a failed subscription payment')
ON CONFLICT (key) DO NOTHING;

COMMENT ON TABLE plan_entitlements IS
  'NULL numeric_value = unlimited for that feature/plan combination (used by the Pro plan above).';
