# Lifecycle review guide (design-merge test site)

Test site: **http://localhost:3101** (its own API on :8100, separate from the live site).
Same database as the live demo, so whatever you create here also appears there.

## Accounts

All passwords: the `DEMO_PASSWORD` value in `backend\.env`. Demo addresses never receive e-mail.

| Role in the review | Sign in as | Why this one |
|---|---|---|
| Client (posts the inquiry, books, reviews) | `enzo@demo.nasery.test` | No inquiries yet: a clean start |
| Organizer owner | `maria@demo.nasery.test` | Davao Dream Events: Wedding, Debut, Corporate Event; 40 km around Davao City |
| Staff: tasks + door | `ken@demo.nasery.test` | Coordinator: can do assigned tasks and scan tickets |
| Attendees (buy tickets) | `carl@`, `faith@`, `gino@demo.nasery.test` | Ordinary clients, no inquiries |
| Platform admin (optional) | `admin@demo.nasery.test` | Payouts, moderation, NaSeRy Access |

Use a separate browser profile or a private window per account so sign-ins don't replace each other.

## Timing: do it all today

- Check-in opens **3 hours before** the start time and closes at the end time.
- An event can be **completed only after its start time**.
- So in the client's inquiry pick **today**, start time about **1–1.5 hours from now**, end time **2–3 hours later**. Check-in is open as soon as the event is published, and you can complete it after the start time passes.

## Paid tickets

PayMongo **test mode**: pay with one of PayMongo's published test cards (developers.paymongo.com → Testing). No real money moves. The return page confirms the payment itself. Free tickets skip payment.

---

## 1. Inquiry (client → organizer)

As **enzo**:
1. Browse Organizers → **Davao Dream Events** → use the calculator → **Request a quotation**. This is a *direct* request (no distance rule).
   - Alternative: Post Event Inquiry (open posting). Without the address-search key, type the venue and press **Use my current location**; Maria only sees it if your location is within 40 km of Davao City. Event type must be Wedding, Debut or Corporate Event.
2. Fill the form (today, times as above, guests, budget) → Send. Check the progress checklist on the right.
3. My Events → the request → **Edit or cancel request** works.

As **maria**:
4. Inquiry & Bidding → Matching Inquiries → the request (Direct badge) → **Ask a question** / **Submit Offer**. Try **Build from my price list**.
5. My Offers: tiles, filter chips, **View proposal**, **Message**, **Revise offer**.

As **enzo**:
6. My Events → Review offers → **Message** the organizer → **Accept Quotation**.

## 2. Preparing the event (organizer + staff)

As **maria**:
1. My Offers → the won offer → **Start Event Planning** → Event Details opens.
2. **Edit**: check date/time, venue, guests. Under **Ticket Types** add e.g. Free (₱0) and Regular (₱150). Under **Settings** turn on **Public registration**. Set it to **Published** → Save.
3. Upload an **event cover photo**.
4. **Preparation Tracking**: add tasks, **Add team member** → Ken, assign tasks to Ken; try filters (To do / Done / Review).
5. **Activity Timeline**: add program items; switch some to **Client can see**.
6. **Business Profile**, **Team**, **Price List** pages (optional look-through).

As **ken**:
7. My Tasks → complete a task with a note.

As **maria**:
8. Preparation Tracking → **Verify**, or **Request changes** (note goes to Ken).

As **enzo**:
9. **Preparation Tracking** (readiness, stage, milestones) and **Activity Timeline** (only the items marked Client can see).

## 3. During the event

As **carl / faith / gino** (attendees):
1. Event Marketplace → the event → pick a ticket (free, or paid with a test card) → **My Tickets** shows the QR.

As **maria**:
2. Event Details → **Start event** (new bar under the header).
3. Ticketing & Attendance → **Attendees** (register a guest by hand: Invited / Free), **Walk-in Counter** (sell at the door), **Attendance Report**, **Payments**.

As **ken** (door):
4. **QR Check-in** → scan an attendee's QR from another screen (or **Manual Entry** with the ticket ID). Scan again: "Already checked in".

As **maria**:
5. Analytics → **Live Events**: check-ins and capacity update.

## 4. After the event

As **maria** (after the start time has passed):
1. Event Details → **Mark completed**.

As **enzo**:
2. My Events → the event's main button → **Event Details** → the review box at the bottom (stars + comment). (The **Rate** shortcut on My Events only appears from the day after the event.)

As **maria**:
3. **Reviews**: reply publicly, try filters; **Report to NaSeRy** (optional).
4. **Earnings**: ticket share from the paid tickets; **Analytics** overview.

As **admin** (optional):
5. Payouts → record a payout to Davao Dream Events → Maria's Earnings shows it as paid out.
6. Moderation (if you reported the review), NaSeRy Access, Event Tags.

---

## Notes

Fixed while preparing this review (none of these were possible before, in the live app either):
- **Start event / Mark completed** on organizer Event Details. No screen could do either, so a booked event could never be completed.
- **Client review box** on client Event Details. It was lost in the September merge, so clients could not leave reviews.
- Attendance Report chart (a component from Sean's update was missing), and **Message organizer** on client Event Details now opens the request's messages.
- The live site (`Dev`) has none of this yet: it gets it when design-merge is merged.
