# Lifecycle review guide (live link)

Use the live link (it changes on each restart; ask for the current one). The demo data was reseeded fresh on 2026-10-23.

The walkthrough runs **two events side by side**, both booked by Enzo with Davao Dream Events:

| | **A. Public event** | **B. Private event** |
|---|---|---|
| Example | Corporate Event: product launch | Debut: Enzo's sister's 18th birthday |
| How guests get in | **Tickets** (free + paid), listed on the Event Marketplace | **Guest list**: invitation only, never listed |
| Who gets in | Carl, Faith, Gino get their own tickets | Maria adds the guests; each gets a QR invite |

## Accounts

All passwords: the `DEMO_PASSWORD` value in `backend\.env`. Demo addresses never receive e-mail.

| Role in the review | Sign in as | Why this one |
|---|---|---|
| Client (sends both requests, books, reviews) | `enzo@demo.nasery.test` | No requests yet: a clean start |
| Organizer owner | `maria@demo.nasery.test` | Davao Dream Events: Wedding, Debut, Corporate Event; 40 km around Davao City |
| Staff: tasks + door | `ken@demo.nasery.test` | Coordinator: can do assigned tasks and scan tickets |
| Attendees of A (get tickets) | `carl@`, `faith@`, `gino@demo.nasery.test` | Ordinary clients, no requests |
| Platform admin (optional) | `admin@demo.nasery.test` | Payouts, moderation, NaSeRy Access |

Use a separate browser profile or a private window per account so sign-ins don't replace each other.

## Timing: do it all today

- Check-in opens **3 hours before** the start time and closes at the end time.
- An event can be **completed only after its start time**.
- So for both events pick **today**, a start time about **1–1.5 hours from now** and an end time **2–3 hours later** (before midnight). Check-in is then open as soon as each event is published.
- A request has a date but no time, so a new event starts with **9:00 AM–5:00 PM as a placeholder**. Change it in step 2.3.

## Paid tickets (event A)

PayMongo **test mode**: pay with one of PayMongo's published test cards (developers.paymongo.com → Testing). No real money moves. Free tickets skip payment.

---

## 1. Requests (client → organizer)

As **enzo**, send **two** requests to Davao Dream Events (Browse Organizers → **Davao Dream Events** → calculator → **Request a quotation**):
1. **A**: event type **Corporate Event**, today, e.g. 100 guests.
2. **B**: event type **Debut**, today, e.g. 50 guests.
3. Check the progress checklist; My Events → a request → **Edit or cancel request** works.

As **maria**, for each request:
4. Inquiry & Bidding → Matching Inquiries → the request (Direct badge) → **Ask a question** / **Submit Offer**. Try **Build from my price list**: the summary reads like a receipt (Subtotal → charges → Total) and the amount follows the lines.
5. My Offers: tiles, filter chips, **View proposal**, **Message**, **Revise offer**.

As **enzo**:
6. My Events → Review offers → **Message** the organizer → **Accept Quotation** on both.

## 2. Preparing the events (organizer + staff)

As **maria**, for each event:
1. My Offers → the won offer → **Start Event Planning** → **Start preparing**. The event page opens with:
   - **What Enzo asked for**: the request, the accepted offer and Message / Call / E-mail.
   - **Set up this event**: tap any open step to jump to that part of the editor. Every detail row also has its own **Edit** / **Add**, and the header has **Edit event**.
2. Upload an **event cover photo**.
3. **Confirm the date and time**: today's real start and end time (see Timing).
4. **Confirm the venue and guests**.
5. **Choose how guests get in**:
   - **A → Tickets**: add Free (₱0) and Regular (₱150), then under **Settings** turn on **Public registration**.
   - **B → Guest list**: nothing else to set up. Seats follow Expected guests, and the event stays private.
6. **Add a contact for the day**, **Describe the event**.
7. **Publish it**: Basic info → Visibility → **Published** → **Save changes**. A's tickets go on sale with it; B is published to your team and guests but not listed anywhere.
8. Event A → Ticket Tiers: **Add ticket type** and a ticket's **Edit** open the ticket section directly.

Then, on either or both events:

9. **Preparation Tracking**: the request panel is at the top (folded). Add tasks, **Add team member** → Ken, assign tasks to Ken; try the filters.
10. **Activity Timeline**: add program items; switch some to **Client can see**. Things NaSeRy records itself are under **History**, greyed out, and don't count as progress. The ring shows preparation progress.
11. **Business Profile**, **Team**, **Price List** (optional look-through).

As **ken**:
12. My Tasks → complete a task with a note.

As **maria**:
13. Preparation Tracking → **Verify**, or **Request changes** (the note goes to Ken).

As **enzo**:
14. **Preparation Tracking** (readiness, stage, milestones) and **Activity Timeline** (only items marked Client can see) for both events.
15. Event Marketplace: **A** is listed, **B** is not.

## 3. Guests get in

**A (public):** as **carl / faith / gino**:
1. Event Marketplace → event A → pick a ticket (free, or paid with a test card) → **My Tickets** shows the QR.

**B (private):** as **maria**, Ticketing & Attendance → choose event B → **Attendees**:
2. **Register** a guest by hand (name, e-mail optional) → the QR ticket appears → **Download**.
3. **Bulk Import**: download the template, add a few names, upload. Each guest gets a QR invite.
4. Normally each guest gets their QR invite by e-mail. Demo addresses get no e-mail, so use **Download** on an attendee's row and show the QR from another screen.

## 4. During the events

As **maria**:
1. Event Details → **Start event** (both).
2. Ticketing & Attendance → **Walk-in Counter** (A: sell at the door), **Attendance Report**, **Payments** (A).

As **ken** (door):
3. **QR Check-in**: scan A's tickets (from carl/faith/gino's My Tickets) and B's downloaded invites (or **Manual Entry** with the ticket ID). Scan one again: "Already checked in". A ticket from A scanned at B's door is refused.

As **maria**:
4. Analytics → **Live Events**: check-ins and capacity update for both.

## 5. After the events

As **maria** (after the start time has passed):
1. Event Details → **Mark completed** (both).

As **enzo**:
2. My Events → each event → **Event Details** → the review box at the bottom (stars + comment). (The **Rate** shortcut on My Events only appears from the day after the event.)

As **maria**:
3. **Reviews**: reply publicly, try the filters; **Report to NaSeRy** (optional).
4. **Earnings**: ticket share from A's paid tickets (B has none); **Analytics** overview.
5. **Business Profile** (public page): the portfolio shows A by name; B only as "Debut" with no details, since it was private.

As **admin** (optional):
6. Payouts → record a payout to Davao Dream Events → Maria's Earnings shows it as paid out.
7. Moderation (if you reported a review), NaSeRy Access, Event Tags.

---

Also available any time: **NaSeRy Live Demo Night** (Davao Dream Events), a public event whose door is always open, for a quick "get a ticket → scan it" check.
