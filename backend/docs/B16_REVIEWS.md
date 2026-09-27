# B16: Reviews (spec and handoff)

Status: **implemented 2026-09-25**. Migration **0038_public_review_replies** (approved and applied after a backup). Next phase: **B17 Admin**.

Paper: "Verified client reviews tied to bid reference numbers, with administrator moderation authority." "Client reviews are restricted to users with a valid bid reference number; unsolicited public reviews are not supported." The public organizer directory shows "client ratings".

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Who may review | Only the **client whose accepted quotation became the event** (the "bid reference"), **once**, after the organizer marks the event **completed**. Other users can't even reach the event (404 under RLS); independent events (no inquiry) can't be reviewed. | paper |
| Content | 1–5 stars (required) and optional text (≤2,000). Shown publicly as first name + last initial and the event type (0017 `public_reviews`). | 0017 |
| Editing | For **30 days** after posting the client can edit (each earlier version saved in `review_edits`; the public review shows "edited") or remove it. After that it's final. | user |
| Replies | One public reply per review, written, edited or removed by the organization **owner**. Other members read reviews but don't reply. Shown on the public profile under the review. | user (0038) |
| Reports | Anyone signed in (not the author) can report a review with a reason, one open report per person. A report alone never hides the review. **Updated in B17:** once an admin takes the case, the review is `disputed` and hidden until they keep or hide it (see B17_ADMIN.md). Organizers see "⚑ Reported · with NaSeRy admin". Organizers still can't delete reviews (0026 locked rule). | paper + 0026 |
| Notifications | `review.requested` → client when the event is completed ("How was …?", once). `review.received` → owners and quote managers. `review.replied` → client. All e-mail by default, switchable. | B13 |
| Ratings | The marketplace average, count and distribution come from published reviews only (`public_reviews`), as since B3; removed or hidden reviews drop out. | B3 |

### 0038_public_review_replies
`CREATE OR REPLACE VIEW public_reviews` with three appended columns:
- `response_text`, `responded_at` from `review_responses` (non-deleted)
- `is_edited` (a `review_edits` row exists)

Filter, security_invoker and grants are unchanged. Rollback `0038_public_review_replies.down.sql` recreates the 0026 view.

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/events/{event}/review` | client: `{can_review, reason, review}` |
| POST / PATCH / DELETE | `/events/{event}/review` `{rating, written_review?}` | the booking client (POST idempotent; PATCH/DELETE within 30 days) |
| GET | `/organizations/{org}/reviews` | members: summary (average, count, distribution, awaiting reply), all reviews with status, reply, open reports; `can.reply` |
| PUT / DELETE | `/organizations/{org}/reviews/{review}/reply` `{text}` | owner |
| POST | `/reviews/{review}/report` `{reason}` | anyone signed in except the author |
| GET | `/marketplace/organizers/{slug}` (…`/reviews`) | public: reviews now carry `reply` and `edited` |

## Frontend
- **Client (Your events):** a review panel on completed events (`#review`, linked from the notification):
  - accessible **star picker** (a radio group, keyboard-friendly, "Poor → Excellent" wording)
  - optional text; after posting it shows the review, "edited", the organizer's reply, and Edit/Remove until the 30-day date
- **Organizer:** new **Reviews** page (sidebar, all members):
  - average, stars and distribution bars; "waiting for your reply" filter
  - reply / edit / remove reply (owner); "Report to NaSeRy"; reported and hidden badges
- **Public profile:** the reply under each review, "edited", and a **Report** link for signed-in visitors.
- `StarRating.vue` (display and input), `useReviews()`.

## Tests
`tests/Feature/Reviews/ReviewTest.php` (3):
- **Eligibility and posting:** blocked before completion (with the reason); `review.requested` sent on completion; rating validation; text trimmed; one review only; public profile average 5, "Ella R.", no reply; the organizer notified; strangers get 404.
- **Edits and replies:** edit keeps the previous version and marks it edited; staff get 403 on reply while the owner can reply (public, client notified); remove reply; after 31 days edit and removal are refused.
- **Reports:** reported once (repeat and self-report refused); the organizer sees the open report while the review stays public; the client removes their review and the rating count drops; they can review again.

**Verification (2026-09-25):** browser run on a completed booked event:
- The client picked 5 stars ("Excellent"), wrote a review and posted it; the panel shows Edit/Remove "until Oct 25, 2026".
- The owner's Reviews page shows 5.0, the distribution and "1 waiting for your reply", then the reply.
- The public profile shows "★ 5.0 · 1 review", "Ella R.", the review, and "Reply from E2E Davao Events".
- The client had the "How was …?" notification.
- E2E data purged.

**Full suite:** 244/245 on live, after a backup. The one failure was a clock-sensitive B9 test (see below), fixed and passing.

**Test fix (not an app bug):** tests that "expire" a checkout hold set `expires_at` from PHP's clock, but capacity reads compare with SQL `now()`, which PostgreSQL fixes at the start of the transaction (one transaction per test). Once a test ran longer than a minute, the hold still looked active. `TicketingTest` and `PaymentTest` now use `now() - interval '1 minute'` from the database clock. Production is unaffected (each request has its own transaction).

## Open items
- ~~Admin moderation~~: done in B17 (take case → disputed/hidden, keep, hide, restore; everyone notified).
- Ticket-buying attendees can't review (not bid-reference holders); an attendee event-feedback survey could be future work.
