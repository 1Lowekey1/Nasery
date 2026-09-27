# B5: Quotation / bidding (spec and handoff)

Status: **implemented and verified 2026-09-24**. Next phase: **B6 Event core** (convert an accepted inquiry into an event).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Bid content | Package name, a **proposal** (pitch / service plan), terms, and a **cost breakdown**. The total is always the sum of the lines, computed on the server in cents (half-up), never typed in. | paper ("price quote and service plan or pitch", "cost breakdown") |
| One active offer | At most one draft or sent quotation per organizer per inquiry (DB unique index). Drafts are invisible to the client. | pack |
| Editing a sent offer | **Revise = replace**: the current quotation is withdrawn ("Replaced by a revised quotation") and the new content is sent as a new quotation in the same transaction. The client sees one current offer marked "Revised n×"; earlier versions stay on record. | user |
| Withdraw | The organizer gives a reason. The invitation becomes `withdrawn`, and the organizer may quote again while the inquiry is open. | pack (reasons) |
| Client decline | The client gives a reason. The quotation becomes `rejected` and the invitation `rejected`; that organizer can't quote again on this inquiry. | pack |
| Accept | `accept_quotation()` (0025, SECURITY DEFINER) accepts the quote, rejects competing sent quotes, updates the inquiry and every invitation, and writes history and audit rows in one transaction. Other organizers' drafts are then cancelled. It does **not** create an event. Idempotent endpoint. | pack |
| Contact reveal | Organizers see the client as "Ella R." until the client accepts **their** quotation; then they see the client's full name, email and phone. Losing organizers never do. | privacy + paper (terms agreed externally) |
| Inquiry state | `submitted` ⇄ `receiving_bids` follows whether any sent quotation exists. Cancelling an inquiry, or auto-closing it, cancels its open quotations. | schema |
| Auto-close | "No activity" means the latest quotation sent, else the invitations; still 30 days (`inquiry_auto_close_days`). | paper |
| Lock order | Every action locks inquiry → quotation (the accept path locks the inquiry before calling the function), so concurrent actions serialize instead of deadlocking. | invariant |
| Migration 0032 (applied) | Adds `package_name` and `proposal`. **Column grants**: the app role can no longer INSERT or UPDATE `status`, `submitted_at` or `decided_*`, so status changes only in the logged system context or `accept_quotation()`. Clients can't read drafts, and can now read the cost lines (`quotation_items_client_read`). | user approved |
| Quotation calculator (0033, applied) | Organizers keep their own **price list** (`organization_price_options`): options priced flat or per guest, with an optional minimum guest count, optionally grouped under one of their services. Options under a service are alternatives (the client picks one, e.g. catering partner A or B); ungrouped options are add-ons. A public view exposes active options of listed organizers only; there is no anon or authenticated grant, and Laravel reads the view in the system context. | user ("options set and modified by the organizers") |

## Endpoints

**Organizer** (`account:business`, needs `quotation.manage`; writes also need an operational organization):

| Method | Path | Notes |
|---|---|---|
| POST | `organizations/{org}/inquiries/{inquiry}/quotations` | `{package_name?, proposal?, terms?, items[{description, quantity, unit_price, organization_service_id?}], submit?}`. Creates a draft, or sends it when `submit` is true. Idempotent. |
| GET | `organizations/{org}/quotations?status[]&page` | "My Offers", with an inquiry summary and the client (contact only once accepted). |
| GET | `organizations/{org}/quotations/{quotation}` | |
| PATCH | `organizations/{org}/quotations/{quotation}` | Drafts only; replaces the content and lines. |
| POST | `…/quotations/{quotation}/submit` | Idempotent. |
| POST | `…/quotations/{quotation}/revise` | Full content. Returns the **new** quotation (201). Idempotent. |
| POST | `…/quotations/{quotation}/withdraw` | `{reason}` |
| DELETE | `…/quotations/{quotation}` | Drafts only. |
| GET/POST/PATCH/DELETE | `organizations/{org}/price-options[/{priceOption}]` | Price list; members view, owners manage. Soft delete. |

Organizer inquiry payloads (B4) now include `quotation: {id, status, total_amount}`, the organization's latest quote.

**Client** (`account:client`):

| Method | Path | Notes |
|---|---|---|
| GET | `inquiries/{inquiry}/quotations` | One current offer per organizer: lines, organizer contact, `earlier_versions`. Open offers come first, cheapest first. |
| POST | `quotations/{quotation}/accept` | Idempotent. |
| POST | `quotations/{quotation}/decline` | `{reason}` |

**Public:** `GET marketplace/organizers/{slug}` now includes `price_options`.

## Frontend

- `composables/useQuotations.ts`: organizer, client and price-list APIs; status labels; line maths that matches the server (`lineSubtotal`, `linesTotal`, `optionAsLine`).
- `components/organizer/QuotationForm.vue`: a drawer that creates, edits and revises quotations. It can add lines from the price list (per-guest options use the inquiry's guest count or the option's minimum) and shows a live total.
- `pages/organizer/inquiries/match-inquiries.vue`: "Send Quotation", "Continue draft", or "Revise quotation", plus a status chip with the total.
- `pages/organizer/inquiries/offers.vue`: "My Offers" with Active, Won, Closed and All filters. It shows the cost breakdown and proposal and lets the organizer edit, withdraw or delete drafts. Once a quote is won, the client's contact details appear.
- `pages/client/inquiries/[id].vue`:
  - quotation comparison, accept (with a confirmation) and decline (with a reason);
  - **Invite more organizers**: the B4 API existed but no page used it.
- `pages/organizer/profile.vue`: "Quotation calculator" price-list management.
- `pages/organizers/[slug].vue`: the public calculator (guest count, one pick per service, add-ons, estimate). "Request a quotation with these" carries `?guests=&options=`; the parameters survive a login redirect.
- `pages/client/post-event.vue`: pre-fills guests, services (with the chosen option and price as notes) and the estimate from those parameters.

## Tests

- `tests/Feature/Quotations/QuotationTest.php` (12 tests):
  - draft, then send, with a server-computed total (cents rounding)
  - drafts hidden from clients
  - validation
  - only invited organizers may quote, once each, and not after declining
  - revise replaces the offer and keeps history
  - withdraw reopens the slot
  - accept: competitors rejected, drafts cancelled, contact revealed only to the winner, idempotent replay, a second accept refused
  - client decline
  - inquiry cancel cascades to quotations
  - isolation between clients and organizers, and staff without permission
  - suspended organizers can't send
  - the DB refuses a direct status write (0032)
  - auto-close counts quotation activity
- `tests/Feature/Quotations/PriceOptionTest.php` (4 tests):
  - price-list CRUD, grouping and validation
  - cross-organization isolation, including the 0033 trigger
  - the public view shows only active options of listed organizers
  - staff can view but not edit, and anon has no access

## Open items

- **B6**: turn an `accepted` inquiry into an event (`converted_to_event`).
- No emails are sent for new, accepted or declined quotations until **B13**; clients and organizers check the pages.
- Payment on acceptance: the paper says acceptance "proceeds to payment", but it also says final pricing is agreed externally. B10 covers ticket payments; quotation payments stay out of scope unless you decide otherwise.
