# B13: Notifications, ticket e-mails and reminders (spec and handoff)

Status: **implemented 2026-09-25**. One infrastructure migration (`laravel.mail_deliveries`, applied after a backup; no Supabase domain migration). Next phase: **B14 Reporting**.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Channels | **In-app** (always) + **e-mail** (per the user's preferences; critical types always). **No SMS** (user; enum stays for later). | user + 0016 |
| E-mail provider | **Real sending** (user) over plain SMTP: Gmail with an app password, or Resend's SMTP. You put the credentials in `.env` (see below). Until then `MAIL_MAILER=log` writes mail to `storage/logs`. | user |
| Safety rails | `MailGuard`:<br>• never mails reserved test domains (`.test`, `.example`, `.invalid`, `.localhost`, `example.com/net/org`), which covers seed and E2E data<br>• `MAIL_ONLY_TO` (comma-separated addresses or `@domains`) limits all mail to an allowlist for rehearsals<br>Skipped mail is logged as `suppressed` with the reason. | real sending |
| Ticket e-mails | Paper: QR tickets delivered by e-mail.<br>• One e-mail per recipient with all their tickets; the recipient is the attendee's e-mail, else the purchaser's.<br>• Each QR is an **inline PNG**; the tickets are also attached as a **PDF** (one A5 page each).<br>• Sent for guest-list adds and CSV imports ("E-mail the ticket" checkbox, default on), free self-registration, settled payments, and door sales with an e-mail.<br>• Organizers can **Resend**, and holders and organizers can **download the PDF**. | paper + old prototype |
| Delivery log | Guests often have no account, and `notifications` needs a user, so ticket e-mails are logged in **`laravel.mail_deliveries`** (user): queued, then sent, failed (with the error, after 3 tries), or suppressed (why). The guest list shows "Sent 1:02 PM", "Failed", "No e-mail" or "Not sent (test address)". User notification e-mails use 0016 `notification_deliveries`. | user |
| QR without GD | PHP here (and possibly the host) has neither GD nor Imagick. `bacon/bacon-qr-code` computes the matrix, and `QrImage` draws it as a **hand-written PNG** (zlib only; about 1.3 KB) for e-mail and as a **one-path SVG** for dompdf (which can't read PNGs without GD). Fonts are subset, so a ticket PDF is about 27 KB. | environment |
| Reminders | `nasery:reminders:send` runs hourly: for sessions starting within 24 hours (`NASERY_REMINDER_HOURS`) and at least an hour away, ticket holders get their QR again (**once per ticket per session**) and the event team gets a "Tomorrow" heads-up (once per session). | user |
| Capacity alerts | Paper: alert staff and organizers when the venue is full. "Almost full" (90%) and "full" (critical) go to everyone with `checkin.perform` or `event.update` on the event, **once per level per event**. Checked after walk-ins, guest adds, registrations and settled payments. | paper |
| Preferences | Per type, e-mail on/off; in-app is always on. Critical types (full venue, refunds, ticket or event cancelled) can't be switched off (0016). Business accounts see business + ticket types; clients see event + ticket types. | 0016 |
| Queue | E-mails are queued **after commit** (a rolled-back action never sends). **A worker must run** (`php artisan queue:work`), plus the scheduler (`php artisan schedule:work`) for reminders and hold expiry. | infra |

### Notification types
| Type | Who | E-mail default |
|---|---|---|
| `inquiry.received` | organizers with `inquiry.manage` (direct invites and matches) | on |
| `quotation.received` | client (new or revised quote) | on |
| `quotation.accepted` / `quotation.declined` | organizers with `quotation.manage` | on |
| `event.created` | client (event made from their inquiry) | on |
| `staff.invited` | the invitee | on |
| `task.assigned` | newly assigned staff (not the person assigning) | off |
| `capacity.warning` / `capacity.full` (critical) | door team and managers | on / always |
| `team.event_tomorrow` | event team | on |
| `tickets.issued` | account holder (the ticket e-mail carries the QR) | off |
| `payment.refunded` (critical) | buyer (manual and automatic refunds) | always |
| `ticket.cancelled` (critical) | buyer | always |
| `event.cancelled` (critical) | ticket holders + the client | always |

## Setting up real e-mail (you do this)
In `backend/.env`:
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com        # or smtp.resend.com
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=you@gmail.com     # Resend: resend
MAIL_PASSWORD=<16-char Gmail app password>   # Resend: the API key
MAIL_FROM_ADDRESS=you@gmail.com # must be allowed by the provider
MAIL_FROM_NAME=NaSeRy
MAIL_ONLY_TO=you@gmail.com,@yourteam.ph      # optional, for rehearsals
```
Then `php artisan config:clear` and run `php artisan queue:work` (and `php artisan schedule:work`). For a Gmail app password: Google Account, then Security, then 2-Step Verification, then App passwords.

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/me/notifications?unread=1&page=` | self (RLS); `meta.unread_count` |
| GET | `/me/notifications/unread-count` | self |
| POST | `/me/notifications/{id}/read`, `/me/notifications/read-all` | self |
| GET / PUT | `/me/notification-preferences` `{preferences: [{type, email}]}` | self |
| GET | `/me/tickets/{ticket}/pdf` | the buyer |
| GET | `/organizations/{org}/events/{event}/tickets/{ticket}/pdf` | `attendee.manage` or `checkin.perform` |
| POST | `…/tickets/{ticket}/email` | `attendee.manage` (resend) |
| — | guest list rows gain `email_delivery`; `POST …/guests` and `…/guests/import` accept `send_email` (default true) | |

## Frontend
- **Bell** (`NotificationBell.vue`) in the organizer, client and public headers (replacing the decorative "System Online" pill):
  - unread badge, refreshed every minute while the tab is visible
  - the latest 10, with "Mark all read"
  - clicking an item marks it read and opens its page
- **`/notifications`**: All / Unread / **E-mail settings**, in the layout matching the account type.
- Guest list:
  - "Ticket e-mail" column, E-mail/Resend and PDF actions
  - e-mail checkboxes on add and import
- My Tickets: **Download PDF**.

## Tests
`tests/Feature/Notifications/NotificationTest.php` (6):
- **Guest ticket e-mail:** subject, recipient, PDF attachment and QR. No-address and test-domain guests are suppressed with a reason. `send_email:false` skips. Resend is queued, then sent. PDF download works; the holder route refuses someone else's ticket.
- **Self-registration:** one e-mail with both tickets to the buyer, an in-app `tickets.issued`, and the holder PDF.
- **Notifications and preferences:**
  - the organizer is notified in-app and by e-mail of a new inquiry
  - a client who switched quotation e-mails off gets in-app only (no delivery row)
  - only the recipient can mark a notification read
  - critical types can't be switched off
  - clients can't set organizer types
- **Capacity:** warning, then full, once each, even when an override goes over; full is critical.
- **Reminders:** sent once per session, and so is the team heads-up.
- **MailGuard:** test domains blocked, allowlist honored.

**Verification (2026-09-25):**
- The seed through the real services produced `inquiry.received`, `quotation.received`, `quotation.accepted` and `event.created`.
- Browser run as organizer:
  - adding a guest with an address: "Sending…", then **"Sent 1:02 PM"** (queue worker)
  - seeded `.test` guests show "Not sent (test address)"
  - the e-mail (extracted from the log) renders with the inline QR and the ticket card, with a 27 KB PDF attached
  - the PDF renders on one page with ₱ and the dashes
  - filling the venue shows "E2E Reyes Garden Wedding is full" in the bell (3 unread)
  - clicking a notification opens its page and the count drops
  - settings: critical types locked; saving works
- Client: bell shows 2 unread; `/notifications` in the client layout.
- E2E data purged.

## Open items
- **SMS** (paper: "SMS or Gmail") is skipped (user). A PH provider such as Semaphore can plug into `NotificationChannel::Sms` later.
- **Guests without accounts** don't get an e-mail when an event is cancelled (only account holders and the client are notified). Could reuse `mail_deliveries` with a cancellation template.
- **Delivery status from the provider** (bounces, opens) isn't tracked; "sent" means the SMTP server accepted it.
- **Hosting:** the worker and scheduler must run as separate processes on the host (for example Railway: a worker service running `php artisan queue:work`, plus cron or `schedule:work`).
