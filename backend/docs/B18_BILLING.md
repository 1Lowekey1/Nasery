# B18: SaaS billing (spec and handoff)

Status: **implemented 2026-09-26** (overnight; decisions taken with you before you went to bed; browser-verified the next morning). Migration **0041_saas_billing** applied after a backup; rollback file kept. This is the last backend phase of the roadmap (B0–B18).

Paper/pack: SaaS plans with entitlements (0020: plans, features, plan_entitlements, subscriptions, subscription_invoices). Seeded plans: Free ₱0, Starter ₱999/mo (₱9,990/yr), Pro ₱2,999/mo (₱29,990/yr).

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Hitting a limit | **Block new, keep existing.** Creating past a limit returns `402 PLAN_LIMIT_REACHED` with the limit, usage and an "Upgrade in Plan & Billing" message. After a downgrade nothing is deleted or locked; new items wait until there's room. | you |
| What's paid | **Useful things stay on Free; only extras are limited:** analytics keep working on Free with a 3-month history (Starter 12, Pro 24); quarterly view and report **CSV** exports are Starter/Pro (the report **PDF** and the guest-list CSV stay free); custom event types Free 2 / paid unlimited; price-list options Free 5 / Starter 20 / Pro unlimited. Seeded limits kept: active events 2/10/∞, total events 10/100/∞, team 3/15/∞. | you |
| Trial | None; everyone starts on Free. | you |
| Payments | Same checkout as tickets: fake demo gateway by default (test/live PayMongo drivers work the same). No platform fee on plan payments. Owners manage billing; members can see it. | capstone demo mode |
| Plan changes | **Upgrades** (higher plan, or monthly → yearly) start **now** and the **unused whole days** of the current paid period are **credited** (e.g. 20 of 30 days of ₱999 = ₱665.94 → yearly costs ₱9,324.06). **Downgrades** (lower plan, or yearly → monthly), and upgrades whose credit would exceed the new price, are **scheduled for the period end**, charge nothing now, and can be undone ("Keep … instead" / cancel). Plan & Billing shows a quote (price, credit, pay now) before anything happens; invoices show list price − credit. A declined payment changes nothing. | you (2026-09-26), common practice |
| Cancel | Keeps the plan until the period ends, then Free. "Resume" undoes it before then. | derived |
| Renewals | Hourly job `nasery:billing:cycle`: at period end a renewal invoice is issued and the plan enters its **grace period** (platform setting `subscription_grace_period_days`, 3 days) with features kept. Paying it moves the period forward from the old end; unpaid → **expired → Free**. Owners are notified at each step (`billing.update`, critical). No stored cards in demo mode, so the owner pays renewals from Plan & Billing. Abandoned checkouts expire after a day. | seed + derived |
| Admin | Settings → **Plans**: prices, availability, every limit (empty = unlimited) and the paid-features switch per plan; MRR, paid this month, organizers on Free, overdue/cancelling counts, recent invoices. Free can't be priced or disabled. Price changes apply at renewal. Analytics adds plan payments. | derived |

### 0041_saas_billing
- `ALTER TYPE subscription_status ADD VALUE 'incomplete'` (checkout started, not paid; not "live", so the plan doesn't change until payment).
- Data only: features `analytics_history_months`, `custom_event_type_limit`, `price_option_limit` and their 9 entitlements (values above). NULL = unlimited.
- Rollback `0041_saas_billing.down.sql` removes the 3 features/entitlements and turns any `incomplete` rows into `expired` (PostgreSQL can't drop an enum value). `nasery:doctor` checks it.

## Endpoints
| Method | Path | Who |
|---|---|---|
| GET | `/organizations/{org}/billing` | members (plan, subscription, usage vs limits, plans, open renewal, invoices, `can.manage`) |
| GET | `/organizations/{org}/billing/quote?plan&cycle` | members: kind (new / upgrade / scheduled / renewal), price, credit, due now, start |
| POST | `/organizations/{org}/billing/checkout {plan, cycle}` | owner; `checkout_url`, or `scheduled: true` for downgrades (idempotent) |
| POST | `/organizations/{org}/billing/cancel`, `/resume` | owner |
| GET | `/me/payments/{payment}` | now also plan payments for owners (`kind: subscription`), used by the demo checkout page |
| GET / PATCH | `/admin/billing`, `/admin/plans/{plan}` | admin |

Limit checks live in `Entitlements` (used by EventManager create/convert, StaffManager invite, event types, price options, analytics, report CSV).

## Frontend
- **Plan & Billing** page (organizer sidebar): current plan and renewal date, cancel/resume, overdue-renewal banner with "Pay renewal", usage bars per limit, plan cards with a monthly/yearly toggle and "Choose", invoices. Returning from checkout shows paid / declined / cancelled.
- Analytics filters: ranges beyond the plan are shown as "(Starter/Pro)" and disabled, quarterly shows 🔒, a "More history on Starter/Pro" link; an old link with a longer range falls back to the plan's default instead of an error.
- Report CSV on Free shows "CSV exports are part of the Starter and Pro plans. The PDF stays free."
- Other limits (events, team, event types, price options) show the API's upgrade message where the action is.
- Admin: Settings → Plans tab; Analytics "Plan payments" chart and "Platform income" (fees + plans).

## Tests
`tests/Feature/Billing/BillingTest.php` (4):
- **Free limits:** 3rd active event, 4th staff invite, 3rd custom type, 6th price option → 402 with details; analytics default 3 months, 12m and quarterly → 402; report CSV 402 but PDF 200; usage numbers.
- **Upgrade and cancel:** staff can't pay; Free has no checkout; declined payment keeps Free; paid → Starter, invoice paid ₱999, no platform-fee snapshot, owner notified, 3rd event allowed, 12m quarterly analytics allowed, admin MRR ₱999; cancel/resume; period end → Free, the 3 events kept, 4th blocked.
- **Renewal:** period end → grace with an open ₱2,999 invoice and Pro kept; paying moves the period from the old end; next unpaid period → grace → expired → Free.
- **Admin plans:** Free can't be priced; Free's event limit raised to 3 applies at once; Starter price and unlimited team; non-admins 403.

**Full suite (2026-09-26, after a backup): 253/253 passed**; affected suites 88/88; `nasery:doctor` green.

Test fixture change: organizations created by the test helpers are put on **Pro** by default (`planForTests()`), so earlier feature tests aren't cut short by Free's limits; BillingTest uses Free.

## Browser verification (2026-09-26, with you signing in)
- **Organizer on Free:**
  - Plan & Billing showed 2 of 2 active events.
  - Creating a 3rd event showed "Your Free plan allows 2 active events and you have 2. Upgrade in Plan & Billing to add more."
- **Upgrade:**
  - Choose Starter → demo checkout ₱999 → Pay → "Payment received. Your new plan is active."
  - The page then showed Starter, renewing Oct 26, 2026: 2 of 10 active events, 12 months of analytics, paid features on.
  - A switch to yearly (₱9,990) was also made during the session and correctly replaced the monthly plan.
- **Admin → Settings → Plans:**
  - Monthly recurring ₱832.50 (9,990 ÷ 12), paid this month ₱10,989, 1 organizer on Free
  - Starter shows 1 subscriber; the limit editor shows current values
  - Both invoices are listed
- **Cleanup:** test data purged (it now also clears subscriptions, invoices and plan payments).
- **Fix made during the run:** usage labels in sentence case.

## Plan changes (added 2026-09-26)
- `tests/Feature/Billing/BillingTest.php` +2: monthly → yearly after 10 days is quoted and charged `9990 − credit` (credit ≈ ₱666), the yearly plan replaces monthly, and the invoice shows the list price; Pro monthly from a fresh yearly plan is scheduled. A downgrade Pro → Starter is scheduled, undone, rescheduled, then starts at the period end with its first invoice due and is paid from the old end; yearly → monthly is scheduled, and cancelling instead drops it and ends on Free.
- **Browser (you signed in):** on Starter monthly for 10 days → Yearly → quote "₱9,990 − ₱665.94 credit, pay now ₱9,324.06" → demo pay → Starter yearly until Sep 26, 2027, and the invoice shows the credit. Yearly → monthly showed "Nothing is charged now" → scheduled banner → "Keep Starter instead" restored it.
- **Fixes:** the credit now counts whole days, so the quoted amount can't drift by a centavo before paying (seen once: ₱9,324.06 quoted, ₱9,324.07 charged). The demo checkout shows centavos.

## Open items
- The seeded **Free total-event limit is 10 events ever** (0020). That's strict for a real product; change it in Settings → Plans if the team prefers (e.g. unlimited total, keep 2 active).
- `workspaces` (orgs per account) is shown in the seed but not enforced: NaSeRy allows one organization per business account (B2/B8 decision).
- `custom_quotation_bidding` (Pro only in the seed) has no meaning yet; the price list is limited by `price_option_limit` instead.
- No stored cards and no automatic renewal charge (demo mode); credits are whole days and apply only to upgrades. A live launch would add PayMongo saved payment methods or recurring links.
- Plan payments can't be refunded from the UI (organizer refunds are per event).
