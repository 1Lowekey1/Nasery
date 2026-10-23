# Lifecycle review guide (live link)

Use the live link (it changes on each restart; ask for the current one). The demo data was reseeded fresh on 2026-10-23.

## Accounts

All passwords: the `DEMO_PASSWORD` value in `backend\.env`. Demo addresses never receive e-mail.

| Role in the review | Sign in as | Why this one |
|---|---|---|
| Client (sends the request, books, reviews) | `enzo@demo.nasery.test` | No requests yet: a clean start |
| Organizer owner | `maria@demo.nasery.test` | Davao Dream Events: Wedding, Debut, Corporate Event; 40 km around Davao City |
| Staff: tasks + door | `ken@demo.nasery.test` | Coordinator: can do assigned tasks and scan tickets |
| Attendees (get tickets) | `carl@`, `faith@`, `gino@demo.nasery.test` | Ordinary clients, no requests |
| Platform admin (optional) | `admin@demo.nasery.test` | Payouts, moderation, NaSeRy Access |

Use a separate browser profile or a private window per account so sign-ins don't replace each other.

## Timing: do it all today

- Check-in opens **3 hours before** the start time and closes at the end time.
- An event can be **completed only after its start time**.
- So pick **today**, a start time about **1–1.5 hours from now** and an end time **2–3 hours later** (before midnight). Check-in is then open as soon as the event is published, and you can complete it once the start time passes.
- A request has a date but no time, so a new event starts with **9:00 AM–5:00 PM as a placeholder**. Change it in step 2.3.

## Paid tickets

PayMongo **test mode**: pay with one of PayMongo's published test cards (developers.paymongo.com → Testing). No real money moves. Free tickets skip payment.

---

## 1. Request (client → organizer)

As **enzo**:
1. Browse Organizers → **Davao Dream Events** → use the calculator → **Request a quotation** (a direct request: no distance rule, any event type).
2. Fill the form (today, guests, budget) → Send. Check the progress checklist on the right.
3. My Events → the request → **Edit or cancel request** works.

As **maria**:
4. Inquiry & Bidding → Matching Inquiries → the request (Direct badge) → **Ask a question** / **Submit Offer**. Try **Build from my price list**: the summary reads like a receipt (Subtotal → charges → Total) and the amount follows the lines.
5. My Offers: tiles, filter chips, **View proposal**, **Message**, **Revise offer**.

As **enzo**:
6. My Events → Review offers → **Message** the organizer → **Accept Quotation**.

## 2. Preparing the event (organizer + staff)

As **maria**:
1. My Offers → the won offer → **Start Event Planning** → **Start preparing**. The event page opens with:
   - **What Enzo asked for**: the request, the accepted offer and Message / Call / E-mail.
   - **Set up this event**: tap any open step to jump to that part of the editor. Every detail row also has its own **Edit** / **Add**, and the header has **Edit event**.
2. Upload an **event cover photo**.
3. **Confirm the date and time**: set today's real start and end time (see Timing).
4. **Confirm the venue and guests**.
5. **Choose how guests get in**:
   - **Tickets** for this walkthrough, so carl/faith/gino can get tickets from the Event Marketplace: add e.g. Free (₱0) and Regular (₱150), then under **Settings** turn on **Public registration**.
   - **Guest list** is the option for invitation-only events (birthdays, weddings): no ticket setup, the event stays private and you add guests under Ticketing & Attendance → Attendees, each getting a QR invite. Look at it, but pick Tickets here, since attendees can't find a private event on the marketplace.
6. **Add a contact for the day**, **Describe the event**.
7. **Publish it**: Basic info → Visibility → **Published** → **Save changes**. Tickets go on sale with it.
8. Ticket Tiers: **Add ticket type** and a ticket's **Edit** open the ticket section directly.
9. **Preparation Tracking**: the request panel is at the top (folded). Add tasks, **Add team member** → Ken, assign tasks to Ken; try the filters.
10. **Activity Timeline**: add program items; switch some to **Client can see**. Things NaSeRy records itself are under **History**, greyed out, and don't count as progress. The ring shows preparation progress.
11. **Business Profile**, **Team**, **Price List** (optional look-through).

As **ken**:
12. My Tasks → complete a task with a note.

As **maria**:
13. Preparation Tracking → **Verify**, or **Request changes** (the note goes to Ken).

As **enzo**:
14. **Preparation Tracking** (readiness, stage, milestones) and **Activity Timeline** (only items marked Client can see).

## 3. During the event

As **carl / faith / gino** (attendees):
1. Event Marketplace → the event → pick a ticket (free, or paid with a test card) → **My Tickets** shows the QR.

As **maria**:
2. Event Details → **Start event**.
3. Ticketing & Attendance → **Attendees** (register a guest by hand), **Walk-in Counter** (sell at the door), **Attendance Report**, **Payments**.

As **ken** (door):
4. **QR Check-in** → scan an attendee's QR from another screen (or **Manual Entry** with the ticket ID). Scan again: "Already checked in".

As **maria**:
5. Analytics → **Live Events**: check-ins and capacity update.

## 4. After the event

As **maria** (after the start time has passed):
1. Event Details → **Mark completed**.

As **enzo**:
2. My Events → the event → **Event Details** → the review box at the bottom (stars + comment). (The **Rate** shortcut on My Events only appears from the day after the event.)

As **maria**:
3. **Reviews**: reply publicly, try the filters; **Report to NaSeRy** (optional).
4. **Earnings**: ticket share from the paid tickets; **Analytics** overview.

As **admin** (optional):
5. Payouts → record a payout to Davao Dream Events → Maria's Earnings shows it as paid out.
6. Moderation (if you reported the review), NaSeRy Access, Event Tags.

---

Also available any time: **NaSeRy Live Demo Night** (Davao Dream Events), a public event whose door is always open, for a quick "get a ticket → scan it" check.
