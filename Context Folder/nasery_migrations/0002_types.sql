-- =====================================================================
-- 0002_types.sql
-- Purpose: all ENUM types used across the schema.
-- Dependencies: 0001
-- =====================================================================

CREATE TYPE account_type            AS ENUM ('client','business','admin');
CREATE TYPE membership_status       AS ENUM ('active','invited','deactivated');
CREATE TYPE approval_status         AS ENUM ('pending','under_review','approved','rejected','suspended');
CREATE TYPE inquiry_status          AS ENUM ('draft','submitted','receiving_bids','accepted','converted_to_event','cancelled');
CREATE TYPE inquiry_org_status      AS ENUM ('pending','viewed','declined','quoted','withdrawn');
CREATE TYPE quotation_status        AS ENUM ('draft','submitted','accepted','rejected','withdrawn','cancelled');
CREATE TYPE event_status            AS ENUM ('draft','published','ongoing','completed','cancelled');
CREATE TYPE session_status          AS ENUM ('upcoming','ongoing','completed','cancelled');
CREATE TYPE task_status             AS ENUM ('pending','in_progress','done','blocked','cancelled');
CREATE TYPE task_priority           AS ENUM ('low','medium','high','urgent');
CREATE TYPE ticket_type_status      AS ENUM ('draft','on_sale','paused','closed');
CREATE TYPE registration_status     AS ENUM ('pending','confirmed','cancelled');
CREATE TYPE admission_type          AS ENUM ('paid','free','invited','complimentary');
CREATE TYPE registration_source     AS ENUM ('online','organizer','staff','walk_in');
CREATE TYPE ticket_status           AS ENUM ('pending','valid','used','cancelled','invalidated');
CREATE TYPE hold_status             AS ENUM ('active','converted','expired','cancelled');
CREATE TYPE payment_status          AS ENUM ('pending','processing','paid','failed','cancelled');
CREATE TYPE webhook_processing_status AS ENUM ('received','processed','ignored','failed');
CREATE TYPE fee_model_type          AS ENUM ('percentage','fixed','hybrid');
CREATE TYPE review_status           AS ENUM ('published','hidden','disputed');
CREATE TYPE moderation_report_status AS ENUM ('open','in_review','resolved','dismissed');
CREATE TYPE subscription_status     AS ENUM ('active','payment_failed','grace_period','cancelled','expired');
CREATE TYPE billing_cycle           AS ENUM ('monthly','yearly');
CREATE TYPE invoice_status          AS ENUM ('draft','issued','paid','void');
CREATE TYPE walk_in_category        AS ENUM ('ticket_linked','paid_walk_in','registered_attendee','headcount_only');
CREATE TYPE notification_channel    AS ENUM ('in_app','email','sms');
CREATE TYPE delivery_status         AS ENUM ('queued','sent','delivered','failed','retrying');
CREATE TYPE announcement_audience   AS ENUM ('all','organizers','clients');
CREATE TYPE transfer_qr_action      AS ENUM ('retained','reissued');
CREATE TYPE permission_effect       AS ENUM ('grant','revoke');
