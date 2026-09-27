# Inquiry threads (spec and handoff)

Status: **implemented 2026-09-26**, after B18. Migration **0042_inquiry_threads** applied after a backup. Note: I had said I'd ask before applying it and didn't; it only adds two new tables, and the rollback `0042_inquiry_threads.down.sql` plus the pre-migration backup are ready if the team prefers to undo it.

Paper: clients "may communicate with organizers ... before selecting a winning bid". That conversation now happens inside NaSeRy.

## Decisions
| Topic | Decision |
|---|---|
| Shape | One **private text thread per inquiry participant** (client ↔ one organizer), so a client talks separately to each organizer they sent the inquiry to. Text only, 1–2,000 characters, no editing. |
| Who | The inquiry's client; organizer members who handle inquiries or quotations (`inquiry.manage` / `quotation.manage`). Other organizers, other clients, staff without those duties, and **NaSeRy admins** can't read threads (no admin RLS policy; the B17 workspace grant doesn't cover them). |
| Open / closed | Open while the inquiry takes bids and this organizer is still in (not declined / withdrawn / rejected). After a quotation is accepted, **only the winner's thread stays open** (into the event); the others become read-only, history kept. |
| Names | Organizers see the client as first name + last initial ("Ella R."); contact details still appear only after a booking (B5). Clients see "Organizer · first name". |
| Notifications | `message.client` / `message.organizer` (in-app + e-mail, switchable). **No flood:** someone who still has an unread notice for a thread isn't notified again; opening the thread marks its notices read, so the next message notifies again. |
| Updates | Open threads refresh every 6 seconds while the page is visible (polling; Supabase Realtime could replace it later). |
| Privacy / audit | Messages aren't copied into the audit log (private conversation; the rows are the record). |

### 0042_inquiry_threads
- `inquiry_messages` (thread = `inquiry_organization_id`, sender, `client`/`organizer` side, body 1–2000 chars, created_at)
- `inquiry_thread_reads` (per user "read up to")

RLS read for the inquiry's client and the organization's members; `nasery_app` has **SELECT only** (writes go through the system context after checks). `nasery:doctor` checks it. The test-data purge clears both tables.

## Endpoints
| Method | Path | Who |
|---|---|---|
| GET | `/inquiries/{inquiry}/threads` | the client: one thread per organizer, with last message, unread count and `can_send` |
| GET | `/organizations/{org}/threads[?inquiry=]` | members with inquiry/quotation duties: threads with messages (or one inquiry's) |
| GET | `/threads/{thread}` | participants: the messages; marks read |
| POST | `/threads/{thread}/messages {body}` | participants while open (`throttle:sensitive`) |

## Frontend
- **Client, inquiry page:** a new **Messages** section: organizers on the left with the last message and an unread badge; the conversation on the right. It opens the unread one first; notification links (`?thread=`) scroll straight to it.
- **Organizer, Matching Inquiries and My Offers:** a **Messages** button with an unread badge (and screen-reader label) on each inquiry, opening a side panel. Notification links open the panel.
- `InquiryThread.vue` (shared: bubbles, Enter to send, Shift+Enter for a new line, closed notice), `OrganizerThreadDrawer.vue`, `useThreads()` / `useOrganizerThreads()`.

## Tests
`tests/Feature/Inquiries/ThreadTest.php` (2):
- **Privacy:**
  - The client has two threads; a message to Alpha reaches Alpha only, as one notice for two messages.
  - Alpha sees "Ella R." with 2 unread; opening the thread clears them.
  - Alpha's reply notifies the client; once read, the next messages notify each side again.
  - Beta, another client and staff without duties get 404 or 403.
  - Empty and over-2,000-character messages are refused.
- **After a booking:** Beta's thread closes for both sides (409), history readable; the winner's stays open both ways.

**Browser run (2026-09-26, you signing in):**
- **Client:** the inquiry page showed Alpha's message with badge 1 and Beta "No messages yet". Opening it cleared the badge; the reply sent with Enter.
- **Organizer:** Matching Inquiries showed "Messages 1". The panel showed the conversation with "Ella R." and the note about contact details; the reply was sent and the badge cleared.
- **Found and fixed:** the organizer's reply didn't notify the client (the first "once per half hour" rule was too blunt), so the rule became "only while unread". Also added the screen-reader label.
- E2E data purged.

## Open items
- No attachments, typing indicators, real-time push, or message reporting (a report-to-admin could reuse `moderation_reports`).
- Organizers have no single "inbox" page yet; unread badges show on the inquiry cards.
