# Notifications — Product Spec (v1)

Status: draft for engineering · Owner: Notifications workstream · Refines FEATURE_MAP §7
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas/template texts are ours.

## 1. Purpose & scope

Turns domain events into (a) templated emails to clients/contacts and staff and (b) signed outbound webhooks. Owns template storage &
rendering, recipient resolution & preferences, the email log, staff notification subscriptions, webhook endpoints & delivery log.
It does not own SMTP/provider config beyond a mail driver setting, nor inbound email (Support).

## 2. Entities & key fields

### email_templates

`id, key (unique, dotted, e.g. invoice.created), type (general|product|invoice|domain|support|admin), audience (client|staff), name (admin label),
is_system (seeded, non-deletable), is_enabled, from_name (nullable), from_email (nullable; ignored for support type — department address used),
cc (json), bcc (json), attach_invoice_pdf (bool), attach_quote_pdf (bool), plain_text_only (bool), timestamps`

### email_template_translations

`id, template_id, locale, subject, body (markdown + merge syntax), updated_by, timestamps` — unique `(template_id, locale)`; default locale row required. **MUST**

### email_messages (email log)

`id, uuid, dedupe_key (unique), template_key (nullable for ad-hoc), client_id, user_id, contact_id, staff_id, to (json), cc, bcc, from, subject,
body_html, body_text, related_type, related_id, status (queued|sent|failed|suppressed), suppressed_reason, failure_reason, attempts,
provider_message_id, queued_at, sent_at, timestamps`

- Secrets (service passwords, reset/invite tokens) are redacted in stored bodies: the log stores a render with sensitive fields replaced by `••••`. **MUST**
- Bodies purged after `notifications.email_log_body_retention_days: 365` (metadata kept). **SHOULD**

### Preferences

- Client account owner: `clients.email_preferences` json; contacts: `contacts.email_preferences` (existing); other users: `client_user.email_preferences`. Keys: `general, invoice, product, domain, support, affiliate`. Default all true. **MUST**

### Staff subscriptions

- `roles.email_categories` json: subset of `system` (automation, provisioning/registrar failures, sync reports), `account` (orders, cancellations, client changes, withdrawals), `support` (ticket mails, still gated by department notify flags). **MUST**
- Per-staff opt-out override `users.staff_email_optout` json. **SHOULD**

### webhook_endpoints

`id, url (https only; http allowed only when app.env=local), description, secret (encrypted; shown once), previous_secret (encrypted), previous_secret_expires_at,
events (json list of event types or ["*"]), is_active, consecutive_failures, disabled_reason, last_success_at, created_by, timestamps`

### webhook_deliveries

`id, event_id (uuid), endpoint_id, event_type, payload (json), attempt, status (pending|succeeded|failed|abandoned), response_status, response_body (≤2 KB),
duration_ms, next_attempt_at, created_at, updated_at` — unique `(event_id, endpoint_id)`. Retention `webhooks.delivery_retention_days: 30`. **MUST**

## 3. Rendering

- Syntax (sandboxed, no code execution): `{{ path.to.field }}` (HTML-escaped), `{{ path | raw }}` only for pre-sanitised fields (e.g. `ticket.message_html`),
  `{% if path %}…{% else %}…{% endif %}`, `{% for item in list %}…{% endfor %}`, filters `money`, `date`, `upper`, `default('x')`. Implemented with a sandboxed engine and an allow-list of variables/filters. **MUST**
- Body markdown → HTML, wrapped in the global layout (`mail.header_html`, `mail.footer_html`, `mail.css`), CSS inlined; text part auto-generated. `{{ signature }}` = `mail.signature`. **MUST**
- Unknown variables render empty and are reported as warnings in preview/save. Syntax errors block saving; at send time a broken template marks the message `failed` (no partial send) and notifies staff (`system`). **MUST**
- Locale: recipient user language → client language → `localization.default_language`. Dates/money formatted by locale and client currency. **MUST**
- Support-type subjects are fixed-format `[#{{ ticket.number }}] {{ ticket.subject }}` (editable body only) so threading works. **MUST**

## 4. Merge field categories (available per template type)

| Category        | Fields (examples)                                                                                                                                                                                                  | Available in     |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------- |
| `company`       | name, url, logo_url, email, pay_to_text                                                                                                                                                                            | all              |
| `system`        | client_area_url, admin_url (staff only), today                                                                                                                                                                     | all              |
| `recipient`     | name, first_name, email                                                                                                                                                                                            | all              |
| `client`        | id, first_name, last_name, company_name, email, address fields, country, currency, language, group_name, credit_balance, `custom.<key>`                                                                            | client templates |
| `user` / `auth` | first_name, email, reset_url, verify_url, invite_url, expires_at, ip                                                                                                                                               | general (auth)   |
| `order`         | number, date, total, payment_method, items[] (description, amount, cycle), notes                                                                                                                                   | general, admin   |
| `invoice`       | id, number, status, date, due_date, subtotal, tax_lines[], total, credit_applied, amount_paid, balance, payment_method, pay_url, items[], last_payment (amount, date, transaction_ref), days_overdue               | invoice          |
| `quote`         | number, subject, valid_until, total, view_url, items[]                                                                                                                                                             | general          |
| `service`       | id, product_name, group_name, domain, username, password (welcome only), server (hostname, ip, nameservers[]), cycle, recurring_amount, next_due_date, status, suspension_reason, config_options[], `custom.<key>` | product          |
| `addon`         | name, cycle, recurring_amount                                                                                                                                                                                      | product          |
| `domain`        | name, registration_date, expiry_date, next_due_date, days_until_expiry, period_years, nameservers[], renew_url, transfer_failure_reason                                                                            | domain           |
| `domains[]`     | list of {name, expiry_date, days_until_expiry, renew_url} for aggregated reminders                                                                                                                                 | domain           |
| `ticket`        | number, subject, department, priority, status, url (guest URL includes key), message_html, attachments[], requester_name, assignee_name                                                                            | support          |
| `affiliate`     | referral_url, code, balance_available, balance_pending, withdrawal_amount                                                                                                                                          | general          |
| `staff`         | first_name, email (recipient staff)                                                                                                                                                                                | admin            |
| `automation`    | run_at, counts{invoices_created, captures_ok, captures_failed, suspended, terminated, reminders_sent, domains_synced, errors[]}                                                                                    | admin            |

## 5. Template catalog (v1 seed) — keys are ours; bodies are written by us

**General (client):** `auth.password_reset` M · `auth.password_changed` S · `auth.email_verification` M · `auth.user_invitation` M · `auth.new_login_alert` C ·
`client.welcome` M · `order.confirmation` M · `quote.delivered` M · `affiliate.activated` S · `affiliate.withdrawal_processed` S · `affiliate.monthly_summary` C
**Product:** `service.welcome.shared_hosting` M · `service.welcome.reseller_hosting` M · `service.welcome.server` M · `service.welcome.other` M ·
`service.suspended` M · `service.unsuspended` M · `service.cancellation_requested` M · `service.upgrade_completed` S · `service.terminated` C
**Invoice:** `invoice.created` M · `invoice.created_autocharge` (card/stored method will be charged) M · `invoice.payment_reminder` M ·
`invoice.overdue_first` M · `invoice.overdue_second` M · `invoice.overdue_third` M · `invoice.payment_confirmation` M · `invoice.payment_failed` M ·
`invoice.refunded` M · `invoice.modified` M · `payment_method.expiring` S · `credit_note.issued` C
**Domain:** `domain.registration_confirmation` M · `domain.transfer_initiated` M · `domain.transfer_completed` M · `domain.transfer_failed` M ·
`domain.renewal_confirmation` M · `domain.renewal_upcoming` M · `domain.expired_notice` M
**Support (client):** `ticket.autoresponse` M · `ticket.opened_by_staff` M · `ticket.reply` M · `ticket.auto_closed` M · `ticket.closed_bounce` M ·
`ticket.unregistered_bounce` M · `ticket.replies_only_bounce` M
**Admin (staff):** `staff.order_new` M · `staff.order_fraud` S · `staff.ticket_new` M · `staff.ticket_client_reply` M · `staff.ticket_assigned` M ·
`staff.ticket_mention` S · `staff.ticket_department_changed` S · `staff.provisioning_failed` M · `staff.registrar_action_failed` M ·
`staff.domain_sync_report` S · `staff.free_domain_renewal_blocked` M · `staff.cancellation_request` M · `staff.affiliate_withdrawal_request` M ·
`staff.automation_summary` S · `staff.password_reset` M · `staff.invitation` M · `staff.login_failures` S · `staff.client_details_changed` C ·
`staff.webhook_endpoint_disabled` M · `staff.template_render_failed` M
(M = MUST, S = SHOULD, C = COULD.) Admins can create custom templates of types general/product/domain/invoice for manual sending and per-product welcome overrides. **MUST**

## 6. Event → template mapping (client category in brackets = preference gate; "always" ignores preferences)

| Event                                                            | Template(s)                                                       | Recipients                                                                             | Conditions                                                                                  |
| ---------------------------------------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `PasswordResetRequested`*                                        | auth.password_reset                                               | the user                                                                               | always                                                                                      |
| `EmailVerificationRequested`*                                    | auth.email_verification                                           | the user                                                                               | always                                                                                      |
| `UserInvited`*                                                   | auth.user_invitation                                              | invitee email                                                                          | always                                                                                      |
| `ClientRegistered`                                               | client.welcome                                                    | owner [general]                                                                        | —                                                                                           |
| `OrderPlaced`                                                    | order.confirmation; staff.order_new                               | owner [general]; staff `account`                                                       | staff mail also on admin-created orders                                                     |
| `OrderMarkedFraud`                                               | staff.order_fraud                                                 | staff `account`                                                                        | —                                                                                           |
| `ServiceActivated`                                               | product `welcome_email_key` or `service.welcome.{type}`           | owner + [product]                                                                      | skipped if product welcome = none or activation is a re-activation                          |
| `ProvisioningFailed`                                             | staff.provisioning_failed                                         | staff `system`                                                                         | —                                                                                           |
| `ServiceSuspended`                                               | service.suspended                                                 | [product]                                                                              | skip when `notify_client=false` on manual suspend                                           |
| `ServiceUnsuspended`                                             | service.unsuspended                                               | [product]                                                                              | —                                                                                           |
| `ServiceCancellationRequested`                                   | service.cancellation_requested; staff.cancellation_request        | [product]; staff `account`                                                             | —                                                                                           |
| `ServiceUpgraded`                                                | service.upgrade_completed                                         | [product]                                                                              | —                                                                                           |
| `InvoiceCreated`                                                 | invoice.created or invoice.created_autocharge                     | [invoice]                                                                              | not for drafts, zero-total, or order invoices when `orders.no_invoice_email_on_order`       |
| `InvoicePaymentReminder`                                         | invoice.payment_reminder                                          | [invoice]                                                                              | —                                                                                           |
| `InvoiceOverdue(level)`                                          | invoice.overdue_{first,second,third}                              | [invoice]                                                                              | skip if client `send_overdue_reminders=false` (needs `level` on event)                      |
| `PaymentReceived`                                                | invoice.payment_confirmation                                      | [invoice]                                                                              | one per transaction; shows remaining balance on partial                                     |
| `PaymentFailed`                                                  | invoice.payment_failed                                            | [invoice]                                                                              | automatic captures only                                                                     |
| `InvoiceRefunded`                                                | invoice.refunded                                                  | [invoice]                                                                              | —                                                                                           |
| `InvoiceModified`*                                               | invoice.modified                                                  | [invoice]                                                                              | e.g. domain line removed at expiry                                                          |
| `QuoteSent`                                                      | quote.delivered (+PDF)                                            | quote recipient                                                                        | always (explicit staff action)                                                              |
| `DomainRegistered`                                               | domain.registration_confirmation                                  | [domain]                                                                               | —                                                                                           |
| `DomainTransferInitiated`*                                       | domain.transfer_initiated                                         | [domain]                                                                               | —                                                                                           |
| `DomainTransferred`                                              | domain.transfer_completed                                         | [domain]                                                                               | —                                                                                           |
| `DomainTransferFailed`*                                          | domain.transfer_failed; staff.registrar_action_failed             | [domain]; staff `system`                                                               | —                                                                                           |
| `DomainRenewed`                                                  | domain.renewal_confirmation                                       | [domain]                                                                               | —                                                                                           |
| `DomainExpiring(daysLeft)`                                       | daysLeft ≥ 0: domain.renewal_upcoming; < 0: domain.expired_notice | [domain]                                                                               | aggregated per client/day (domains[])                                                       |
| `RegistrarActionFailed`                                          | staff.registrar_action_failed                                     | staff `system`                                                                         | —                                                                                           |
| `FreeDomainRenewalBlocked`*                                      | staff.free_domain_renewal_blocked                                 | staff `system`                                                                         | —                                                                                           |
| `DomainSyncReport`*                                              | staff.domain_sync_report                                          | staff `system`                                                                         | only if changes/errors                                                                      |
| `TicketOpened(byStaff=false)`                                    | ticket.autoresponse; staff.ticket_new                             | requester + CC (+owner if contact) when dept autoresponse on; dept members with notify | [support] gate does not apply to the requester's own ticket                                 |
| `TicketOpened(byStaff=true)`                                     | ticket.opened_by_staff                                            | requester + CC (+owner)                                                                | —                                                                                           |
| `TicketReplied(byStaff=true)`                                    | ticket.reply                                                      | requester + CC (+owner)                                                                | not for notes                                                                               |
| `TicketReplied(byStaff=false)`                                   | staff.ticket_client_reply                                         | assignee, else dept members; watchers                                                  | —                                                                                           |
| `TicketAssigned`                                                 | staff.ticket_assigned                                             | assignee (not self), watchers                                                          | —                                                                                           |
| `TicketNoteAdded(mentions)`*                                     | staff.ticket_mention                                              | mentioned staff                                                                        | —                                                                                           |
| `TicketDepartmentChanged`*                                       | staff.ticket_department_changed                                   | new dept members if unassigned                                                         | —                                                                                           |
| `TicketClosed(closedBy=system)`                                  | ticket.auto_closed                                                | requester                                                                              | —                                                                                           |
| `InboundEmailBounced(template)`*                                 | the named bounce template                                         | original sender                                                                        | max 1 bounce per sender per hour                                                            |
| `AffiliateActivated`*                                            | affiliate.activated                                               | affiliate owner [affiliate]                                                            | —                                                                                           |
| `AffiliateWithdrawalRequested`                                   | staff.affiliate_withdrawal_request                                | staff `account`                                                                        | —                                                                                           |
| `AffiliateWithdrawalProcessed`*                                  | affiliate.withdrawal_processed                                    | [affiliate]                                                                            | —                                                                                           |
| `ClientUpdated`                                                  | staff.client_details_changed                                      | staff `account`                                                                        | setting `notifications.notify_client_changes: false` must be true; client-made changes only |
| `StaffPasswordResetRequested`* / `StaffInvited`*                 | staff.password_reset / staff.invitation                           | that staff                                                                             | always                                                                                      |
| `StaffLoginFailuresThreshold`*                                   | staff.login_failures                                              | staff holding `*`                                                                      | —                                                                                           |
| `AutomationRunCompleted`*                                        | staff.automation_summary                                          | staff `system`                                                                         | `notifications.daily_summary: true`                                                         |
| `WebhookEndpointDisabled`*                                       | staff.webhook_endpoint_disabled                                   | staff `system`                                                                         | —                                                                                           |
| `*` = event not yet in `app/Events` — owning module must add it. |

## 7. Recipient resolution & delivery rules

- Client-audience mail goes to: owner user (if category enabled) + every contact/user with that category enabled; addresses de-duplicated case-insensitively. **MUST**
- If no recipient remains for `invoice` or `domain`, fall back to the owner (these must always reach someone). Client UI prevents disabling the last invoice/domain recipient. **MUST**
- `always` mail (auth, explicit staff sends) ignores preferences. Closed clients receive only auth mail. **MUST**
- Global switches: `mail.disabled: false` (log as `suppressed`), `mail.bcc: []` (all client mail), `notifications.client_can_manage_preferences: true`. **MUST**
- Sending is queued (`notifications` queue) after commit; `dedupe_key = sha1(event_id|template_key|recipient)` prevents duplicates on event replays. **MUST**
- Transport failure retries: 3 attempts (1 min, 5 min, 30 min) then `failed`; staff can resend from the log. **MUST**
- Invoice PDF attached when template `attach_invoice_pdf` and `billing.pdf_enabled`. **MUST**

## 8. Outbound webhooks

- Event types: dotted snake versions of events, e.g. `invoice.paid`, `payment.received`, `payment.reversed` (a chargeback, or a refund with reverse effects), `payment.reinstated` (a won dispute undid the chargeback, #221: one per `payment.reversed` its chargeback sent, same invoice and amount, also when the invoice still owes more), `service.suspended`, `domain.renewed`, `ticket.opened`, `client.registered`, `order.placed`, `affiliate.commission_earned`; plus `webhook.ping`. **MUST**
- Envelope: `{"id": "<uuid>", "type": "invoice.paid", "created_at": "<ISO-8601 UTC>", "api_version": "2026-10", "data": {"object": {…resource snapshot…}, "ids": {…}}}`. No secrets ever (passwords, tokens, EPP codes, card data). **MUST**
- Headers: `Content-Type: application/json`, `User-Agent: <AppName>-Webhooks/1`, `X-Webhook-Id`, `X-Webhook-Event`, `X-Webhook-Timestamp` (unix), `X-Webhook-Signature: v1=<hex HMAC-SHA256(secret, "{timestamp}.{raw_body}")>`; during rotation both `v1=` values, comma-separated. Receivers should reject timestamps older than 5 minutes. **MUST**
- Delivery: POST, connect timeout 5 s, total 10 s, redirects not followed; 2xx = success. Others/timeouts retry with backoff 1 m, 5 m, 30 m, 2 h, 6 h, 12 h, 24 h (`webhooks.max_attempts: 8`), then `abandoned`. **MUST**
- `webhooks.disable_after_consecutive_failures: 20` → endpoint disabled + `WebhookEndpointDisabled`. Any success resets the counter. **MUST**
- SSRF guard: resolve host at send time; refuse private, loopback, link-local, CGNAT and metadata ranges; `webhooks.allow_private_targets: false` (true only for local development). **MUST**
- Order is not guaranteed; consumers dedupe by `id`. Manual "resend" creates a new attempt with the same event id. **MUST**
- Secret rotation keeps the previous secret valid for 24 h. **SHOULD**

## 9. Admin capabilities

Template list by type, edit per locale with merge-field sidebar, preview with sample fixtures or a chosen real record, send test to self, enable/disable,
restore default text (system templates), create custom; manual send of a template/ad-hoc message to a client (optionally save as template);
email log (search by client/recipient/template/status, view, resend); staff role email categories; webhook endpoints CRUD, secret reveal-once, test ping,
delivery log with request/response and resend. **MUST**

## 10. Client-area capabilities

Email preferences per user/contact (categories), "email history" list of messages sent to the account (subject/date, view body) **SHOULD**; preferences editable only when `notifications.client_can_manage_preferences`.

## 11. Automation

- `notifications:retry-webhooks` every minute (dispatches due deliveries; idempotent by row lock). **MUST**
- `notifications:prune` daily (email bodies, webhook deliveries per retention). **MUST**

## 12. Events emitted

`EmailQueued`, `EmailSent`, `EmailFailed`, `EmailSuppressed(reason)`, `WebhookDelivered`, `WebhookDeliveryFailed`, `WebhookEndpointDisabled`.

## 13. Edge cases & pitfalls

- Sending before the DB transaction commits (recipient sees data that was rolled back) — listeners `afterCommit`.
- Event replays / queue retries creating duplicate mails — dedupe key.
- User-supplied text (ticket subject, client name) injected into HTML — escape by default; `raw` only for sanitised fields.
- Welcome emails containing passwords end up in logs/backups — redact stored copies; prefer a set-password link where the driver supports it.
- Template edited into a syntax error → whole category silently stops; block save + staff alert on render failure.
- Locale fallback missing → always keep default locale row.
- Contacts with all categories off for invoices → no one gets invoices; enforce last-recipient rule.
- Ticket threading breaks if subjects are customised — subject format fixed for support templates.
- Webhook endpoints pointing to internal services (SSRF) and slow endpoints tying up workers — guards + timeouts + dedicated queue.

## 14. Acceptance criteria

1. Given `InvoiceCreated` for a client whose owner disabled `invoice` and one contact enabled it, Then only the contact receives `invoice.created`.
2. Given all invoice recipients disabled, Then the owner still receives it (fallback) and the client UI blocked the last opt-out.
3. Given `PasswordResetRequested` for a user with all categories off, Then the reset mail is sent.
4. Given the same event dispatched twice, Then exactly one `email_messages` row per recipient exists.
5. Given `mail.disabled=true`, Then messages are logged with status `suppressed` and no transport call is made.
6. Given a template with `{{ client.first_name }}` and a client named `<b>Ann</b>`, Then the HTML contains the escaped text.
7. Given a template body with an unclosed `{% if %}`, When saved, Then validation fails.
8. Given a client with language `de` and a German translation, Then the German subject is used; without one, the default locale.
9. Given a hosting welcome email with a password, Then the sent email contains it and the stored log body shows `••••`.
10. Given transport fails 3 times, Then status = `failed`, attempts = 3, and resend from the log creates a new attempt.
11. Given `TicketReplied(byStaff=true)` on a ticket with 2 CC addresses opened by a contact, Then requester, both CCs and the owner receive `ticket.reply` once each.
12. Given `DomainExpiring(daysLeft=-3)`, Then `domain.expired_notice` is sent, not `renewal_upcoming`.
13. Given an endpoint subscribed to `invoice.paid`, When an invoice is paid, Then a POST is made whose signature verifies with HMAC-SHA256 over `"{timestamp}.{body}"`.
14. Given the endpoint returns 500, Then the next attempt is scheduled ~1 minute later and the delivery log records status/body.
15. Given 8 failed attempts, Then the delivery is `abandoned`.
16. Given 20 consecutive failures, Then the endpoint is disabled and `staff.webhook_endpoint_disabled` is sent.
17. Given an endpoint URL resolving to 169.254.169.254 or 10.0.0.5, Then delivery is refused and logged.
18. Given a payload for `service.activated`, Then it contains no password field.
19. Given a 302 response, Then it is treated as failure (not followed).
20. Given preview with sample data for `invoice.created`, Then it renders without warnings for every seeded template (seed fixtures cover all fields).

## 15. Implementation notes (v1, accepted by the CTO 2026-10-18)

- Settings keys are namespaced under `notifications.*` so they never fall back to Laravel's own config:
  `mail.disabled` → `notifications.mail_disabled`, `mail.bcc` → `notifications.mail_bcc`,
  `mail.signature` → `notifications.signature`, `webhooks.max_attempts` → `notifications.webhooks_max_attempts`,
  `webhooks.disable_after_consecutive_failures` → `notifications.webhooks_disable_after_consecutive_failures`,
  `webhooks.delivery_retention_days` → `notifications.webhooks_delivery_retention_days`,
  `webhooks.allow_private_targets` → config-only `notifications.webhooks_allow_private_targets` (ignored in production).
- Passwords are never emailed (supersedes the welcome-email password in §4/§5 and criterion 9): welcome mail links
  to the client-area service page. The log still redacts one-time links (reset, verify, invite).
- Dedupe keys use a per-occurrence event id (`App\Contracts\Events\DomainEvent`, implemented by
  `IdentifiesOccurrence`), not the payload.
- Password reset and email verification are sent through the `App\Contracts\Auth\AuthMailer` contract (Core default:
  Laravel's notifications; Notifications: its templates), not through events, so no event carries a token or signed
  URL (ADR 0011). `PasswordResetRequested` / `EmailVerificationRequested` in §6 are therefore not events.
- Access links (security review B1): merge fields that grant access by themselves are `App\Data\Notifications\SensitiveValue`
  (account links; the guest ticket link). Staff-editable text can never use one, in any template: it is rejected on
  save and removed at render. The system places them as its own button: in reset, verification and invitation mail,
  and in the client ticket mail (`SecretTemplatePolicy::SYSTEM_ACTIONS`: "View your ticket", label editable as plain
  text), where the button is added to a system slot of the layout after the mail is built. Staff content in mail
  that carries a guest link is reduced to an element allow-list, and the finished HTML must hold the link exactly
  once, as the button's href, to both the HTML5 parser and libxml, or nothing is sent. The text part gets a system
  "Label: url" line. Masking and the final check work on the secret inside the link (`SensitiveValue::secret()`: the
  access key or token) in any case or encoding, over every field — subject, preheader, HTML, text and the log copies.
  One normaliser (entities and percent-encoding decoded until stable, any case) serves masking and the check on the
  HTML and text paths. Known limit: a key split across HTML tags (`GUEST<b></b>KEY`) is not recognised as the key.
  Notifications bodies are final HTML: the layout prints them without a second Markdown pass. Ticket CCs get the
  reply without the button and with a note that the ticket owner has the link. Mail that carried a private link is
  never resent from the log. Reset, verification and invitation text is plain and may use only `company.name`,
  `user.first_name` / `staff.first_name` (URL-like text removed), `auth.expires_at`, `support.email` and `system.today`.
- Account links are built from `APP_URL` and refused on any other origin (except `notifications.auth_link_host`):
  the email is then not sent at all, and the security log, the dashboard health card and the install pre-flight say
  why (an `http://` APP_URL in production holds all of them).

## 16. Out of scope for v1

Mass mail/marketing campaigns, SMS/Slack/push channels and conditional staff notification rules, open/click tracking, bounce/complaint webhooks from mail providers,
inbound webhooks that trigger actions, per-template attachments uploaded by staff, WYSIWYG HTML editor, scheduled digest emails other than the automation summary.
