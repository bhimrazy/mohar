# Support — Product Spec (v1)

Status: draft for engineering · Owner: Support workstream · Refines FEATURE_MAP §6
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

Helpdesk for clients, contacts and guests: departments, tickets with replies/internal notes/attachments, assignment, canned replies,
auto-close, inbound email (piping via provider webhook), knowledge base, announcements and network status (incidents).
Email rendering/sending is Notifications; Support emits events and supplies merge data.

## 2. Entities & key fields

### support_departments

`id, name, slug, description, email (unique, outbound From + inbound routing), is_hidden (direct link only), clients_only, replies_only_by_email
(email cannot open new tickets, only reply), send_autoresponse (bool, default true), prevent_client_close (bool), sort_order, timestamps`

- `department_staff (department_id, user_id, notify_new, notify_replies)` — staff can see/act only in assigned departments unless they hold `support.tickets.access_all`. **MUST**
- Deleting a department with tickets is blocked; staff must move tickets first. **MUST** (safer than silent reassignment)

### tickets

`id, number (masked, unique), access_key (random 32, for guest links), department_id, client_id (nullable), user_id (opener login, nullable),
contact_id (nullable), requester_name, requester_email, subject (≤255), status, priority (low|medium|high|urgent), source (web|email|staff|api),
service_id / domain_id (nullable, must belong to client), assigned_to (staff user_id, nullable), cc (json emails, ≤10), tags (json),
is_pinned, prevent_client_close, merged_into_id (nullable), version (int, optimistic lock), first_staff_reply_at, last_reply_at,
last_client_reply_at, last_staff_reply_at, closed_at, closed_by (client|staff|system), timestamps`

### ticket_messages

`id, ticket_id, type (reply|note), author_type (client|guest|staff|system), user_id, contact_id, author_name, author_email,
body (markdown source), body_html (sanitised render cache), source (web|email|staff|api), email_message_id (nullable, unique), is_autoresponse,
rating (1–5, nullable, staff replies only), edited_at, edited_by, timestamps`

- Notes (`type=note`) are staff-only, never emailed to clients, never change status. **MUST**

### ticket_attachments

`id, ticket_id, message_id, disk, path, original_name, stored_name, mime (detected), size_bytes, sha256, pruned_at, created_at`

### Other tables

- `ticket_statuses (key, label, color, is_core, is_active_state, is_awaiting_reply, auto_close, sort_order)` — core keys seeded and non-deletable. **MUST**; custom statuses (lowercase `[a-z0-9_]`) **SHOULD**
- `ticket_watchers (ticket_id, user_id)` **SHOULD** · `ticket_events (ticket_id, actor, type, from, to, created_at)` audit trail **MUST**
- `canned_reply_categories (id, name, parent_id)`, `canned_replies (id, category_id, name, body, sort_order)` **MUST**
- `inbound_emails (id, provider, message_id unique, from, to, subject, status, reason, ticket_id, raw_path, received_at)` **MUST**
- `support_spam_rules (id, field: sender|subject|body, match: contains|equals, value)` **MUST**
- `kb_categories (id, parent_id ≤1 level, name, slug, description, is_hidden, sort_order)`, `kb_articles (id, title, slug, body, is_published, is_private (login required), tags json, views, votes_up, votes_down, sort_order, timestamps)`, pivot `kb_article_category` **MUST**
- `announcements (id, title, slug, excerpt, body, is_published, published_at, timestamps)` **MUST**
- `network_incidents (id, title, type: server|system|other, server_id nullable, affected (text), priority: low|medium|high|critical, status: investigating|identified|monitoring|scheduled|resolved, description, starts_at, ends_at, timestamps)` + `network_incident_updates (incident_id, body, status, created_at)` **SHOULD**

## 3. Ticket status machine

Core: `open, answered, customer_reply, on_hold, in_progress, closed` (on_hold/in_progress are seeded non-core, renamable).

| Event                                                  | Resulting status                                                  |
| ------------------------------------------------------ | ----------------------------------------------------------------- |
| Ticket created by client/guest/email                   | `open`                                                            |
| Ticket created by staff (with first message to client) | `answered` (staff may choose)                                     |
| Staff reply                                            | `answered` (default; staff may pick any status in the reply form) |
| Client/guest/email reply                               | `customer_reply` (also reopens `closed` unless blocked, see §6)   |
| Staff sets status                                      | any active status                                                 |
| Client closes                                          | `closed` (unless `prevent_client_close` on ticket)                |
| Auto-close job                                         | `closed` (`closed_by=system`)                                     |
| Staff note, assignment, tag change                     | unchanged                                                         |

- "Awaiting reply" queue = statuses with `is_awaiting_reply` (default `open`, `customer_reply`). **MUST**
- `prevent_client_close` copied from department at creation and on department move; later department changes don't alter existing tickets retroactively except on move. **MUST**

## 4. Business rules

### 4.1 Masked numbers & access

- `support.ticket_number_format: '%n%n%n%n%n%n'` tokens: `%A` A–Z, `%a` a–z, `%n` 0–9, `%y` `%m` `%d` date parts, `%i` id. Length ≤ 20. Collision → regenerate (max 10 tries, then append `-{id}`). Sequential id never exposed to clients. **MUST**
- Client view requires login and the client permission `support` on that client account; guest view via `/support/tickets/{number}?key={access_key}`. **MUST**
- Guest tickets: name + email + captcha (`security.captcha_on_ticket_submit: true`). If the email maps to exactly one client (owner/user/contact), attach `client_id`; if ambiguous or none, keep as guest (badge "guest") and let staff link. **MUST**
- Clients-only departments are hidden from guests (`support.show_clients_only_to_guests: false`). **MUST**
- Related service/domain picker shows only items of the selected client account. **MUST**

### 4.2 Replies, notes, editing

- Markdown input, rendered with an allow-list sanitiser (no raw HTML, no inline scripts, links `rel=noopener nofollow`). **MUST**
- Merge tokens in staff replies/canned replies: `{{ requester.name }}`, `{{ requester.first_name }}`, `{{ requester.email }}`, `{{ ticket.number }}`, `{{ client.company_name }}`; staff signature appended (`users.ticket_signature`). **MUST**
- Collision detection: reply/update requests carry `version`; if the ticket changed since load (new message, status, assignment, department, priority, subject, cc), respond 409 with a change summary; staff may resubmit with `force=true`. **MUST**
- Staff can edit replies/notes (`support.tickets.edit_messages`), logged with previous body in `ticket_events`. The first message cannot be deleted (delete the ticket instead). **MUST**
- `@username` mentions in notes notify those staff (only if they can access the department). **SHOULD**
- Client reply ratings 1–5 on staff replies when `support.reply_ratings_enabled: true`; one rating per message, changeable by the client. **SHOULD**

### 4.3 Attachments

- Client/guest allow-list `support.attachment_extensions: [jpg, jpeg, png, gif, webp, pdf, txt, log, zip]`; staff list `support.staff_attachment_extensions` (adds `doc, docx, xls, xlsx, csv, tar, gz`). Hard deny regardless of settings: `php, phtml, phar, exe, bat, cmd, sh, js, html, htm, svg`. **MUST**
- Limits: `support.attachment_max_mb: 10` per file, `support.attachment_max_files: 5` per message. **MUST**
- MIME detected from content; extension/MIME mismatch rejected. Filenames sanitised (`[A-Za-z0-9._-]`), empty → `attachment_{n}`; stored under random names on a private disk. **MUST**
- Download via authorised route only (ticket access check), `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`; images may render inline as thumbnails. **MUST**
- Client emails include attachments of the staff reply up to `support.email_attachment_limit_mb: 10` total; otherwise links. **SHOULD**
- Prune files of tickets closed more than `support.prune_attachments_after_months: 0` (0 = never); metadata kept with `pruned_at`. **SHOULD**

### 4.4 Assignment & routing

- Assign only to staff in the ticket's department or with `support.tickets.access_all`. Self-assign doesn't notify. **MUST**
- Department move: keep assignee if still eligible, else unassign; notify new department members when unassigned. **MUST**
- Pinned tickets list first for everyone with department access. **SHOULD**
- Merge (**SHOULD**): newer tickets merge into the oldest selected; messages ordered chronologically; status from the ticket with the latest message; client from the oldest; subject unchanged plus "[merged]" event; merged tickets keep `merged_into_id`, their URLs redirect and inbound email replies route to the target.

### 4.5 Canned replies

- Staff-only, category tree, searchable by name/body, inserted into editor with merge tokens resolved at send time. **MUST**

### 4.6 Auto-close

- `support.auto_close_hours: 72` (0 = off). Closes tickets whose status has `auto_close = true` (default only `answered`; `customer_reply`, `on_hold`, `in_progress` never by default) and `last_reply_at ≤ now − hours`. Sends `ticket.auto_closed`. **MUST**
- Hourly job, batch 500, idempotent (re-check status inside a row lock). **MUST**

## 5. Notifications matrix (events → Notifications module decides template)

| Action                       | Requester                      | CC  | Owner (if requester is a contact) | Dept members (notify)  | Assignee        | Watchers |
| ---------------------------- | ------------------------------ | --- | --------------------------------- | ---------------------- | --------------- | -------- |
| Opened by client/guest/email | autoresponse (if dept enabled) | ✓   | ✓                                 | ✓                      | —               | —        |
| Opened by staff              | ✓                              | ✓   | ✓                                 | —                      | —               | —        |
| Staff reply                  | ✓                              | ✓   | ✓                                 | —                      | —               | ✓        |
| Client reply                 | —                              | —   | —                                 | ✓ only if unassigned   | ✓               | ✓        |
| Assigned / unassigned        | —                              | —   | —                                 | —                      | ✓ (not self)    | ✓        |
| Department changed           | —                              | —   | —                                 | new dept if unassigned | —               | ✓        |
| Note with mention            | —                              | —   | —                                 | —                      | mentioned staff | ✓        |
| Auto-closed                  | ✓                              | —   | —                                 | —                      | —               | —        |

## 6. Inbound email (piping)

Endpoint `POST /api/inbound/email/{provider}` (normalised driver per provider, e.g. generic MIME POST, Mailgun, Postmark); signature verified with `support.inbound_secret`. Pipeline (each rejection writes `inbound_emails.status`): **MUST**

1. Dedupe by `Message-ID` (missing → hash of from+date+subject).
2. Drop auto-generated mail: `Auto-Submitted` ≠ `no`, `Precedence: bulk|list|junk|auto_reply`, `X-Auto-Response-Suppress`, null/`MAILER-DAEMON` sender, sender equal to any department address (loop).
3. Rate limit: more than `support.inbound_max_per_sender: 10` messages from one sender in 15 minutes → drop.
4. Spam rules (case-insensitive) → drop.
5. Match existing ticket: `In-Reply-To`/`References` against stored `email_message_id`s, else subject token `[#{number}]`. Merged → target ticket.
6. Sender authorisation for replies: requester, CC, any user or contact of the ticket's client. Otherwise store as `blocked` (staff can import from the ticket view); `support.allow_unverified_email_replies: false` — when true, import anyway with an "unverified sender" badge.
7. Closed ticket: if `support.prevent_email_reopen: false` → reopen as `customer_reply`; else send `ticket.closed_bounce` and stop.
8. New ticket: department by recipient address (To/Cc/Delivered-To); unknown address → `support.default_department_id`. `replies_only_by_email` → bounce `ticket.replies_only_bounce`; `clients_only` and unknown sender → bounce `ticket.unregistered_bounce`.
9. Strip quoted history above the marker `support.reply_marker: '--- Reply above this line ---'` and common client quote headers; keep raw MIME for 30 days.
10. Attachments through §4.3 rules; rejected files listed in a system note.
11. `support.auto_add_cc: false` — when true, Cc addresses of the inbound email are added to the ticket.

- Outbound ticket mail: From department email, subject contains `[#{number}]`, `Message-ID` stored, `Auto-Submitted: auto-replied` on autoresponses (RFC 3834). **MUST**
- IMAP/POP mailbox polling **COULD** (out of v1 unless trivial via provider).

## 7. Knowledge base, announcements, network status

- KB: public listing of non-hidden categories; `is_private` articles require login; search (DB full-text over title/body/tags) **MUST**; views counted once per session **MUST**; helpful yes/no vote once per session/user **SHOULD**; ticket-form suggestions (top 5 matches on subject/body as the client types) **SHOULD**.
- Announcements: draft/published, scheduled `published_at` (hidden until then), client home shows latest 3, public list/detail, RSS feed **SHOULD**.
- Network status page: open incidents first by `updated_at`, then resolved last 30 days; `support.network_status_requires_login: true`; incidents linked to a server are highlighted for clients with services on that server **SHOULD**; "resolve" sets `status=resolved`, `ends_at=now`. **MUST**

## 8. Metrics (consumed by Reports)

- `first_response_minutes = first_staff_reply_at − created_at` (staff reply = `type=reply`, `author_type=staff`, not autoresponse). **MUST**
- `resolution_minutes = closed_at − created_at` (last close). Reopen count via `ticket_events`. **MUST**
- Calendar time only (business-hours SLA out of scope). Store timestamps UTC. **MUST**

## 9. Admin capabilities

Ticket queue with filters (status, department, priority, assignee, tag, client, text in subject/first message), saved "Awaiting reply / My tickets / Flagged" views, bulk close/assign/merge/delete/block-sender; ticket view with thread, notes, options (department, status, priority, assignee, cc, related service, client link, tags, prevent-close), canned reply picker, attachments, event log, blocked inbound replies; departments, statuses, canned replies, spam rules, inbound email log; KB, announcements, incidents CRUD. **MUST** (merge, watchers: **SHOULD**)

## 10. Client-area & public capabilities

Open ticket (department → form with related service picker), list/filter own account's tickets, view/reply/close, rate replies, attachments;
guest submit + guest link view/reply; KB browse/search/vote; announcements; network status. **MUST**

## 11. Automation

- `support:auto-close` hourly (§4.6). **MUST**
- `support:prune-attachments` daily (§4.3). **SHOULD**
- `support:purge-inbound-raw` daily — raw MIME older than 30 days. **MUST**

## 12. Events emitted

Existing: `TicketOpened(byStaff)`, `TicketReplied(byStaff)`, `TicketAssigned`, `TicketClosed` — extend `TicketClosed` with `closedBy: client|staff|system`.
New: `TicketNoteAdded(mentions[])`, `TicketStatusChanged`, `TicketDepartmentChanged`, `TicketPriorityChanged`, `TicketUnassigned`, `TicketReopened`,
`TicketMerged(targetId, sourceIds)`, `TicketReplyRated`, `InboundEmailRejected(reason)`, `InboundEmailBounced(template)`, `AnnouncementPublished`,
`NetworkIncidentOpened`, `NetworkIncidentUpdated`, `NetworkIncidentResolved`.

## 13. Edge cases & pitfalls

- Autoresponder ping-pong between our department and a client's vacation responder — RFC 3834 headers + rate limit + own-address check.
- Two departments sharing one inbound address cause duplicate tickets — department email unique.
- Unverified sender spoofing a reply into another client's ticket — sender authorisation (§6.6).
- Guests reusing a client's email to see their data — guests never get client context unless matched; matching only attaches, it does not grant the guest login access.
- Staff without department access seeing tickets via global search or direct URL — every query scoped by department access.
- Customer bumping by replying repeatedly: queue order uses `last_client_reply_at` of the _first unanswered_ client message, so extra replies don't push a ticket back (`support.queue_sort: oldest_unanswered`).
- Auto-closing `customer_reply` tickets loses customer messages — excluded by default (deliberate deviation from some products).
- HTML/script in subjects or bodies rendered in admin UI — escape everywhere; sanitise markdown output.
- Large or malicious attachments (zip bombs are not unpacked; no server-side preview except images).
- Merging tickets from different clients leaks data — require confirmation and same client unless `support.tickets.merge_cross_client` permission.

## 14. Acceptance criteria

1. Given format `ABC-%n%n%n`, When a ticket is created, Then its number matches `^ABC-\d{3}$` and is unique.
2. Given a guest submits with a new email, Then the ticket has no client, status `open`, and the confirmation link with access key works only with the right key.
3. Given a guest email matching exactly one contact, Then the ticket is attached to that contact's client.
4. Given a clients-only department, When a guest lists departments, Then it is absent and posting to it returns 403.
5. Given a staff reply without explicit status, Then status = `answered` and `TicketReplied(byStaff=true)` is emitted once.
6. Given a client reply on a closed ticket via web, Then status = `customer_reply` and `TicketReopened` is emitted.
7. Given a staff note, Then status is unchanged and no client-facing event is emitted.
8. Given staff A loaded version 3 and staff B replied (version 4), When A submits a reply with version 3, Then 409 is returned with the change summary; with `force=true` it posts.
9. Given an upload `invoice.pdf.php` or a PNG whose content is HTML, Then the upload is rejected.
10. Given an 11 MB file with limit 10 MB, Then 422.
11. Given staff X not in department D and without `access_all`, When X opens a D ticket URL, Then 403; X cannot be assigned a D ticket.
12. Given `auto_close_hours=72` and an `answered` ticket idle 73h, When the job runs, Then it closes with `closed_by=system` and `TicketClosed(closedBy=system)`; an idle `customer_reply` ticket stays open.
13. Given an inbound email with `Auto-Submitted: auto-replied`, Then no ticket/reply is created and the log shows `rejected_auto_submitted`.
14. Given 11 emails from one sender within 15 minutes, Then the 11th is dropped as `rejected_rate_limit`.
15. Given an inbound reply whose `In-Reply-To` matches a stored message id, Then it is appended to that ticket as `customer_reply`.
16. Given an inbound reply from an unrelated sender, Then it is stored as blocked and not visible in the thread until staff imports it.
17. Given the same Message-ID posted twice, Then only one message exists.
18. Given `replies_only_by_email` department, When a new email arrives, Then no ticket is created and the bounce event fires.
19. Given tickets #1 (older) and #2 merged, Then #2's messages appear in #1 chronologically and inbound replies to #2 land in #1.
20. Given a private KB article, When a guest opens it, Then 404/redirect to login; logged-in client sees it.
21. Given an announcement with future `published_at`, Then it is hidden from public lists until that time.
22. Given a first staff reply 45 minutes after creation, Then `first_response_minutes = 45`; autoresponses and notes don't count.

## 15. Out of scope for v1

Live chat, business-hours SLA calendars, escalation-rule engine, scheduled ticket actions, per-department custom fields, IMAP/OAuth mailbox import,
billable items from tickets, ticket feedback surveys on close, ticket splitting, KB/announcement translations, downloads library, satisfaction surveys by email.
