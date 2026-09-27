-- =====================================================================
-- 0021_functions_triggers.sql
-- Purpose: domain-action functions that span multiple tables. Every
--          function below is the SANCTIONED path for its transition.
--
-- SECURITY NOTE (fixed in this version): every function that writes to
-- audit_logs, ownership_transfers, or organizer_approval_requests is
-- declared SECURITY DEFINER (owned by the migration-running privileged
-- role, which bypasses RLS) specifically so its internal INSERTs succeed
-- even though ordinary authenticated users are granted NO direct INSERT
-- on audit_logs (see 0022) -- the only way to write an audit row is
-- through one of these functions. Because SECURITY DEFINER elevates
-- privilege, NONE of these functions accept a caller-identity parameter
-- (p_actor_user_id, p_initiated_by, etc.) -- identity is always resolved
-- from auth.uid() internally, and each function independently verifies
-- that auth.uid() is actually entitled to perform the action before
-- doing anything, exactly like the RLS helper functions in 0022.
-- Dependencies: 0001-0020 (needs audit_logs, quotations, events, tickets,
--               ticket_holds, subscriptions, organizer_approval_requests,
--               admin_access_grants).
-- =====================================================================

-- ---------------------------------------------------------------------
-- transfer_organization_ownership()
-- Caller must currently BE the org's active primary owner (verified via
-- auth.uid(), not a trusted parameter).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION transfer_organization_ownership(
  p_organization_id uuid,
  p_from_member_id  uuid,
  p_to_member_id    uuid,
  p_reason          text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  lock_first  uuid;
  lock_second uuid;
BEGIN
  IF p_reason IS NULL OR length(trim(p_reason)) = 0 THEN
    RAISE EXCEPTION 'A reason is required for an ownership transfer';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM organization_members
    WHERE id = p_from_member_id AND organization_id = p_organization_id
      AND user_id = auth.uid() AND is_primary_owner AND status = 'active' AND deleted_at IS NULL
  ) THEN
    RAISE EXCEPTION 'Only the organization''s current active primary owner may initiate an ownership transfer';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM organization_members
    WHERE id = p_to_member_id AND organization_id = p_organization_id AND status = 'active' AND deleted_at IS NULL
  ) THEN
    RAISE EXCEPTION 'Target member % is not an active member of organization %', p_to_member_id, p_organization_id;
  END IF;

  IF p_from_member_id < p_to_member_id THEN
    lock_first := p_from_member_id; lock_second := p_to_member_id;
  ELSE
    lock_first := p_to_member_id; lock_second := p_from_member_id;
  END IF;

  PERFORM 1 FROM organization_members WHERE id = lock_first  FOR UPDATE;
  PERFORM 1 FROM organization_members WHERE id = lock_second FOR UPDATE;

  UPDATE organization_members
     SET is_primary_owner = true, is_owner = true, updated_at = now()
   WHERE id = p_to_member_id AND organization_id = p_organization_id;

  UPDATE organization_members
     SET is_primary_owner = false, updated_at = now()
   WHERE id = p_from_member_id AND organization_id = p_organization_id;

  INSERT INTO ownership_transfers (organization_id, from_member_id, to_member_id, initiated_by, reason, transferred_at)
  VALUES (p_organization_id, p_from_member_id, p_to_member_id, auth.uid(), p_reason, now());

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  VALUES (auth.uid(), p_organization_id, 'ownership_transfer', 'organization_members', p_to_member_id, p_reason,
          jsonb_build_object('from_member_id', p_from_member_id, 'to_member_id', p_to_member_id));
END;
$$;

-- ---------------------------------------------------------------------
-- accept_quotation() -- caller must be the inquiry's own client.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION accept_quotation(p_quotation_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_inquiry_id uuid;
  v_status     quotation_status;
BEGIN
  SELECT inquiry_id INTO v_inquiry_id FROM quotations WHERE id = p_quotation_id;
  IF v_inquiry_id IS NULL THEN
    RAISE EXCEPTION 'Quotation % not found', p_quotation_id;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM inquiries WHERE id = v_inquiry_id AND client_user_id = auth.uid()) THEN
    RAISE EXCEPTION 'Only the inquiry''s own client may accept a quotation';
  END IF;

  PERFORM 1 FROM inquiries WHERE id = v_inquiry_id FOR UPDATE;

  SELECT status INTO v_status FROM quotations WHERE id = p_quotation_id;
  IF v_status <> 'submitted' THEN
    RAISE EXCEPTION 'Quotation % is not in submitted status (currently %)', p_quotation_id, v_status;
  END IF;

  UPDATE quotations SET status = 'accepted', decided_at = now(), decided_by = auth.uid(), updated_at = now()
  WHERE id = p_quotation_id;

  UPDATE inquiries SET status = 'accepted', updated_at = now() WHERE id = v_inquiry_id;

  UPDATE quotations SET status = 'rejected', decided_at = now(), decided_by = auth.uid(), updated_at = now()
  WHERE inquiry_id = v_inquiry_id AND id <> p_quotation_id AND status = 'submitted';

  INSERT INTO quotation_status_history (quotation_id, from_status, to_status, actor_user_id, reason)
  VALUES (p_quotation_id, 'submitted', 'accepted', auth.uid(), 'accepted by client');

  INSERT INTO quotation_status_history (quotation_id, from_status, to_status, actor_user_id, reason)
  SELECT id, 'submitted', 'rejected', auth.uid(), 'competing quotation accepted'
  FROM quotations WHERE inquiry_id = v_inquiry_id AND id <> p_quotation_id AND status = 'rejected'
    AND decided_at = (SELECT decided_at FROM quotations WHERE id = p_quotation_id);

  INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, reason)
  VALUES (auth.uid(), 'quotation_accept', 'quotations', p_quotation_id, 'client accepted quotation');
END;
$$;

-- ---------------------------------------------------------------------
-- set_organizer_approval_status() -- caller must be a platform admin.
-- Keeps organizations.approval_status (authoritative) and
-- organizer_approval_requests (historical log) from ever drifting apart.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_organizer_approval_status(
  p_organization_id uuid,
  p_new_status      approval_status,
  p_decision_reason text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin') THEN
    RAISE EXCEPTION 'Only an admin account may change organizer approval status';
  END IF;
  IF p_new_status IN ('rejected','suspended') AND (p_decision_reason IS NULL OR length(trim(p_decision_reason)) = 0) THEN
    RAISE EXCEPTION 'A reason is required to set organizer approval status to %', p_new_status;
  END IF;

  UPDATE organizations
     SET approval_status = p_new_status,
         is_suspended = (p_new_status = 'suspended'),
         suspended_reason = CASE WHEN p_new_status = 'suspended' THEN p_decision_reason ELSE suspended_reason END,
         suspended_at     = CASE WHEN p_new_status = 'suspended' THEN now() ELSE suspended_at END,
         suspended_by     = CASE WHEN p_new_status = 'suspended' THEN auth.uid() ELSE suspended_by END,
         updated_at = now()
   WHERE id = p_organization_id;

  INSERT INTO organizer_approval_requests (organization_id, status, reviewed_by, decision_reason, decided_at)
  VALUES (p_organization_id, p_new_status, auth.uid(), p_decision_reason, now());

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  VALUES (auth.uid(), p_organization_id, 'organizer_approval_status_change', 'organizations', p_organization_id,
          p_decision_reason, jsonb_build_object('status', p_new_status));
END;
$$;

-- ---------------------------------------------------------------------
-- admin_override_event_status() -- caller must be a platform admin.
-- The sole escape hatch past the terminal-state trigger on events.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION admin_override_event_status(
  p_event_id   uuid,
  p_new_status event_status,
  p_reason     text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
  IF p_reason IS NULL OR length(trim(p_reason)) = 0 THEN
    RAISE EXCEPTION 'A reason is required for an administrative event-status override';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = auth.uid() AND account_type = 'admin') THEN
    RAISE EXCEPTION 'Only an admin account may perform an event-status override';
  END IF;

  PERFORM set_config('nasery.admin_override', 'on', true);

  UPDATE events SET status = p_new_status, updated_at = now() WHERE id = p_event_id;

  INSERT INTO audit_logs (actor_user_id, organization_id, action, entity_type, entity_id, reason, new_values)
  SELECT auth.uid(), organization_id, 'admin_override_event_status', 'events', p_event_id, p_reason,
         jsonb_build_object('status', p_new_status)
  FROM events WHERE id = p_event_id;

  PERFORM set_config('nasery.admin_override', 'off', true);
END;
$$;

-- ---------------------------------------------------------------------
-- transfer_ticket() -- mutates tickets.attendee_id in place (LOCKED
-- direction). Caller must either hold 'ticket.manage' on the ticket's
-- event, or be the registration's own purchaser.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION transfer_ticket(
  p_ticket_id      uuid,
  p_to_attendee_id uuid,
  p_reason         text,
  p_qr_action      transfer_qr_action DEFAULT 'retained'
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_from_attendee_id uuid;
  v_status           ticket_status;
  v_event_id         uuid;
  v_purchaser        uuid;
BEGIN
  SELECT t.attendee_id, t.status, t.event_id, r.purchaser_user_id
    INTO v_from_attendee_id, v_status, v_event_id, v_purchaser
  FROM tickets t JOIN registrations r ON r.id = t.registration_id
  WHERE t.id = p_ticket_id FOR UPDATE OF t;

  IF v_from_attendee_id IS NULL THEN
    RAISE EXCEPTION 'Ticket % not found', p_ticket_id;
  END IF;
  IF NOT (user_has_event_permission(v_event_id, 'ticket.manage') OR v_purchaser = auth.uid()) THEN
    RAISE EXCEPTION 'Not authorized to transfer ticket %', p_ticket_id;
  END IF;
  IF v_status <> 'valid' THEN
    RAISE EXCEPTION 'Only a VALID ticket may be transferred (ticket % is %)', p_ticket_id, v_status;
  END IF;

  UPDATE tickets SET attendee_id = p_to_attendee_id, updated_at = now() WHERE id = p_ticket_id;

  IF p_qr_action = 'reissued' THEN
    UPDATE qr_tickets SET status = 'invalidated', invalidated_at = now(),
           invalidation_reason = 'ticket transferred, QR reissued'
     WHERE ticket_id = p_ticket_id;
    -- application layer generates and inserts the new qr_tickets row with a fresh token_hash
  END IF;

  INSERT INTO ticket_transfers (ticket_id, from_attendee_id, to_attendee_id, transferred_by, reason, qr_action)
  VALUES (p_ticket_id, v_from_attendee_id, p_to_attendee_id, auth.uid(), p_reason, p_qr_action);

  INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, reason, old_values, new_values)
  VALUES (auth.uid(), 'ticket_transfer', 'tickets', p_ticket_id, p_reason,
          jsonb_build_object('attendee_id', v_from_attendee_id), jsonb_build_object('attendee_id', p_to_attendee_id));
END;
$$;

GRANT EXECUTE ON FUNCTION transfer_organization_ownership(uuid, uuid, uuid, text) TO authenticated;
GRANT EXECUTE ON FUNCTION accept_quotation(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION set_organizer_approval_status(uuid, approval_status, text) TO authenticated;
GRANT EXECUTE ON FUNCTION admin_override_event_status(uuid, event_status, text) TO authenticated;
GRANT EXECUTE ON FUNCTION transfer_ticket(uuid, uuid, text, transfer_qr_action) TO authenticated;

-- ---------------------------------------------------------------------
-- Capacity helper functions (read-only; the SAME formula Laravel uses
-- under a row lock during enforcement -- kept here as the single source
-- of truth for the calculation so application code and reporting never
-- drift apart). These do NOT themselves take locks; enforcement in
-- Laravel wraps its own SELECT ... FOR UPDATE around the equivalent query.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ticket_type_consumed_capacity(p_ticket_type_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT
    (SELECT COUNT(*) FROM tickets WHERE ticket_type_id = p_ticket_type_id AND status IN ('valid','used'))
    +
    (SELECT COALESCE(SUM(quantity), 0) FROM ticket_holds
       WHERE ticket_type_id = p_ticket_type_id AND status = 'active' AND expires_at > now());
$$;

CREATE OR REPLACE FUNCTION ticket_type_available_capacity(p_ticket_type_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT tt.capacity - ticket_type_consumed_capacity(p_ticket_type_id)
  FROM ticket_types tt WHERE tt.id = p_ticket_type_id;
$$;

CREATE OR REPLACE FUNCTION event_consumed_capacity(p_event_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT
    (SELECT COUNT(*) FROM tickets WHERE event_id = p_event_id AND status IN ('valid','used'))
    +
    (SELECT COALESCE(SUM(h.quantity), 0) FROM ticket_holds h
       JOIN ticket_types tt ON tt.id = h.ticket_type_id
       WHERE tt.event_id = p_event_id AND h.status = 'active' AND h.expires_at > now())
    +
    (SELECT COALESCE(SUM(count), 0) FROM walk_in_records
       WHERE event_id = p_event_id AND linked_ticket_id IS NULL);
$$;

CREATE OR REPLACE FUNCTION event_available_capacity(p_event_id uuid)
RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT e.overall_capacity - event_consumed_capacity(p_event_id)
  FROM events e WHERE e.id = p_event_id AND e.overall_capacity IS NOT NULL;
$$;

GRANT EXECUTE ON FUNCTION ticket_type_consumed_capacity(uuid)  TO authenticated;
GRANT EXECUTE ON FUNCTION ticket_type_available_capacity(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION event_consumed_capacity(uuid)        TO authenticated;
GRANT EXECUTE ON FUNCTION event_available_capacity(uuid)       TO authenticated;

-- ---------------------------------------------------------------------
-- expire_stale_holds() -- background reconciliation (run periodically by
-- the Laravel scheduler / pg_cron, as a privileged service role, NOT
-- exposed to authenticated users). Cascades expired holds to their
-- PENDING tickets and draft registrations. This is a safety net; the
-- expires_at > now() predicate already excludes expired holds from every
-- capacity read even before this job runs.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expire_stale_holds() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE
  v_hold record;
  v_count integer := 0;
BEGIN
  FOR v_hold IN
    SELECT * FROM ticket_holds WHERE status = 'active' AND expires_at <= now() FOR UPDATE SKIP LOCKED
  LOOP
    UPDATE ticket_holds SET status = 'expired', released_at = now(), updated_at = now() WHERE id = v_hold.id;

    UPDATE tickets SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'hold expired', updated_at = now()
     WHERE registration_id = v_hold.registration_id AND ticket_type_id = v_hold.ticket_type_id AND status = 'pending';

    UPDATE registrations SET status = 'cancelled', updated_at = now()
     WHERE id = v_hold.registration_id AND status = 'pending'
       AND NOT EXISTS (SELECT 1 FROM ticket_holds WHERE registration_id = v_hold.registration_id AND status = 'active');

    v_count := v_count + 1;
  END LOOP;
  RETURN v_count;
END;
$$;
-- Deliberately NOT granted to `authenticated` -- invoked only by the scheduler's service connection.
