# B10: Payments with PayMongo, test and demo modes (spec and handoff)

Status: **implemented and verified 2026-09-25**. No migration (it uses the 0014 payment tables). Next phase: **B11 Check-in** (QR scanner).

NaSeRy is a capstone prototype that gets presented to a panel before any launch (user). Payments therefore run in **demo or test mode by default**, and live mode is locked behind production-only guards.

## Modes

| `PAYMONGO_DRIVER` | What happens | Needs |
|---|---|---|
| `fake` (**default**) | Built-in simulated checkout at `/pay/demo` with a clear amber "test mode" banner. **Pay** settles the payment, **Decline** fails it. It works fully offline and needs no PayMongo account, so it is the one to use for the panel. The link is a **signed, expiring URL**, so a forged or edited link is refused (403). | nothing |
| `test` | Real PayMongo **test-mode** Checkout Sessions (card, GCash, GrabPay, Maya sandboxes). No real money. | `PAYMONGO_SECRET_KEY=sk_test_…`, `PAYMONGO_PUBLIC_KEY=pk_test_…`, `PAYMONGO_WEBHOOK_SECRET` |
| `live` | Real charges. **Refused unless `APP_ENV=production` and the key starts with `sk_live_`**. The app won't boot a live gateway anywhere else, and the test driver refuses a live key. | production only |

Without a secret key the driver defaults to `fake`. The current mode is returned to the frontend, which shows "Test mode: no real money" on My Tickets, the return page and the demo checkout.

### Trying PayMongo test mode
1. Create a PayMongo account. In the dashboard, keep **Test mode** on and copy the `sk_test_`/`pk_test_` keys into `backend/.env`, then set `PAYMONGO_DRIVER=test`.
2. Webhooks need a public URL. Expose the API with a tunnel (for example `cloudflared tunnel --url http://localhost:8000`) and register `https://<tunnel>/api/v1/payments/paymongo/webhook` for `checkout_session.payment.paid`, `payment.paid` and `payment.failed`. Put its `whsk_` secret in `PAYMONGO_WEBHOOK_SECRET`.
3. Without a webhook the return page still works: it asks PayMongo for the session status directly and settles from that. Webhooks just make settlement immediate.
4. Use PayMongo's published test cards and e-wallet sandboxes, such as the success and decline card numbers in their docs.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Flow | Registering for a paid tier creates a pending registration, a **15-minute hold**, and **pending tickets** (attendee names saved, no QR yet). **Continue to payment** creates a Checkout Session and redirects to it. On success, the tickets become **valid and get QR codes**, the hold is released and the registration is confirmed. | B9 + paper |
| Settlement sources | Webhook (preferred) or the return-page status check. Both run the same idempotent `settle()`, which locks the payment row, so a payment settles only once. | integrity |
| Late payment | If the hold expired before the money arrived: the payment **still settles if places remain**; otherwise it is **automatically refunded in full** and the buyer sees "Payment refunded". | integrity |
| Failure / retry | A failed or abandoned payment leaves the reservation until the hold expires. The buyer can **Pay now** again or **Cancel**, which releases the places, from My Tickets. | UX |
| Platform fee | Buyers pay the ticket price only. NaSeRy's fee comes from the active `platform_fee_configs` row (**1% default**; percentage, fixed or hybrid) and is **deducted from the organizer's share**. It is snapshotted per payment and split per ticket, so later rate changes never alter past payments. | paper + 0014 |
| Webhooks | Signature header `t=…,te=…,li=…` is checked against the signature for the **event's own mode** only (`te` for test, `li` for live) within a **5-minute window**. The old prototype accepted either, so a test signature could settle a live payment. Each provider event id is stored once (`payment_webhook_events`), so repeats are answered `duplicate` and ignored. | security |
| Refunds | Organizers with **`finance.view`** refund full or partial amounts up to what remains, with a reason. They can optionally **cancel the buyer's tickets**, which invalidates the QR codes. PayMongo refunds go through `/refunds` on the `pay_` id; the fake gateway records a `fake_ref_` id. Every refund is its own `payment_refunds` row plus an audit entry; the original payment stays `paid`. The fee share is set by the **fee policy** row below. | 0014 + 0024 |
| Fee on refunds | **User policy (2026-09-25): the 1% fee is returned only for a valid reason, never for negligence.** Every refund needs a category:<br>• **Force majeure** (typhoon, calamity, government order) or **Payment error** (duplicate charge, technical problem): the fee is returned in proportion to the amount refunded, and the refund that empties the payment takes whatever fee is left.<br>• **Organizer fault** (cancelled or changed by the organizer, overbooking, mistakes) or **Buyer request** (goodwill): NaSeRy keeps the fee, and the refund comes out of the organizer's share.<br>`fee_portion_refunded` is always filled in. Automatic refunds of late payments record 0, because no fee was taken. The category is saved as a prefix on the reason ("Force majeure: …") and in the audit entry; no migration was needed. Organizer totals show fees kept = charged − returned. | user |
| Money math | All arithmetic is in integer centavos. PayMongo receives centavos; the database stores `numeric(12,2)` pesos. | correctness |

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/me/registrations/pending` | buyer: reservations waiting for payment, plus the mode |
| POST | `/me/registrations/{registration}/checkout` | buyer (`throttle:checkout`, idempotent), returning `checkout_url` |
| POST | `/me/registrations/{registration}/cancel` | buyer: release the reservation |
| GET | `/me/payments/{payment}` | buyer: status for the return page (checks the provider if still pending) |
| POST | `/payments/demo/{payment}/complete` | fake driver only, **signed URL** |
| POST | `/payments/paymongo/webhook` | PayMongo (`throttle:webhook`, signature-checked) |
| GET | `/organizations/{org}/events/{event}/payments` | `finance.view`: payments plus gross, fees, refunds and net totals |
| POST | `/organizations/{org}/events/{event}/payments/{payment}/refund` | `finance.view` (idempotent) |

## Frontend

- `/events/{id}`: paid tiers show **Continue to payment**, which redirects to the checkout.
- `/pay/demo`: the simulated checkout (fake driver).
- `/payments/return`: polls status up to 8 times and shows paid, refunded, waiting, failed or cancelled.
- `/my-tickets`: a **Waiting for payment** section with Pay now and Cancel.
- `/organizer/ticketattendance/payments`: a new **Payments** tab with gross, fee, refunds and net cards, the payments table and a refund dialog.
- `usePayments()` in `useTicketing.ts`; `useApi().blob()`.

## Tests

`tests/Feature/Payments/PaymentTest.php` (9):
- the fee policy: force majeure returns a proportional fee, organizer fault returns none, the last refund takes the remainder, and the totals net out
- the demo flow end to end with the 1% fee (₱16.00 on ₱1,600, ₱8.00 per ticket) and organizer totals
- forged and unsigned demo links refused
- failure followed by retry or cancel
- late payment: settles when places remain, auto-refunded otherwise
- test mode against a faked PayMongo API:
  - centavo payload
  - signed webhooks: wrong secret gives 401, a live signature on a test event gives 401, a valid one is processed, a repeat is a duplicate
  - refund sent to `pay_…`
- return-page fallback
- `finance.view` required
- live mode guarded, and the test driver refuses live keys

**Verification (2026-09-25):**
- Full suite: 219/219 on live, after a backup.
- `nasery:doctor` green.
- Browser run in fake mode:
  - register 2× VIP (₱3,000)
  - Decline, then retry from My Tickets
  - Pay
  - two QR tickets
  - organizer totals ₱3,000 / ₱30 / net ₱2,970
  - partial refund ₱1,500
  - over-refund blocked
  - final ₱1,500 with ticket cancellation
  - E2E data purged

**Bug found only by the browser run:** 0014's `trg_check_refund_total` is a *deferred* constraint trigger. It fired at COMMIT as `nasery_app`, which can't lock `payments` (42501, shown as 403), so every refund failed. PHPUnit tests roll back and never commit, so they couldn't catch it. Fix: `SET CONSTRAINTS trg_check_refund_total IMMEDIATE` inside the `system()` block (manual and automatic refunds). It is the only deferred trigger that needs privileges; the other deferred constraint is a plain UNIQUE.

## Open items

- The organizer self-declares the refund category. An admin review of force majeure and payment-error claims (with a way to reverse abuse) belongs in B17 admin; every claim is already in the audit log.

- Paying for **quotations or event contracts** (client to organizer) is out of scope until the user decides.
- Organizer payouts and settlement reports come later (B14 reports, B18 SaaS billing).
- Receipt emails arrive with B13 notifications.
