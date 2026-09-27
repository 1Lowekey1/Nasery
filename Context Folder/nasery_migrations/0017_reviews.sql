-- =====================================================================
-- 0017_reviews.sql
-- Dependencies: 0001-0016
-- =====================================================================

CREATE TABLE reviews (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id        uuid NOT NULL REFERENCES events(id),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  client_user_id  uuid NOT NULL REFERENCES users(id),
  rating          smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  written_review  text,
  status          review_status NOT NULL DEFAULT 'published',
  deleted_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

-- One review per client per event (events is 1:N to reviews -- many distinct clients may review the same event).
CREATE UNIQUE INDEX uq_reviews_client_event ON reviews(client_user_id, event_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_reviews_org_status ON reviews(organization_id, status);

CREATE TABLE review_edits (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id              uuid NOT NULL REFERENCES reviews(id),
  previous_rating        smallint,
  previous_written_review text,
  edited_at              timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE review_responses (
  id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id               uuid NOT NULL UNIQUE REFERENCES reviews(id),   -- one organizer response per review
  organization_member_id  uuid NOT NULL REFERENCES organization_members(id),
  response_text           text NOT NULL,
  deleted_at              timestamptz,
  created_at              timestamptz NOT NULL DEFAULT now(),
  updated_at              timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE review_reports (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  review_id     uuid NOT NULL REFERENCES reviews(id),
  reported_by   uuid NOT NULL REFERENCES users(id),
  reason        text NOT NULL,
  status        moderation_report_status NOT NULL DEFAULT 'open',
  resolved_by   uuid REFERENCES users(id),
  resolved_at   timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_review_reports_review ON review_reports(review_id);

-- Public display view: never exposes contact info; identity reduced to first name + last initial.
CREATE VIEW public_reviews AS
  SELECT r.id, r.event_id, r.organization_id, r.rating, r.written_review, r.created_at,
         u.first_name AS reviewer_first_name,
         LEFT(u.last_name, 1) AS reviewer_last_initial,
         et.name AS event_type_name
  FROM reviews r
  JOIN users u ON u.id = r.client_user_id
  JOIN events e ON e.id = r.event_id
  LEFT JOIN event_types et ON et.id = e.event_type_id
  WHERE r.status = 'published' AND r.deleted_at IS NULL;
