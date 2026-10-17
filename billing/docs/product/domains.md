# Domains — Product Spec (v1)

Status: draft for engineering · Owner: Domains workstream · Refines FEATURE_MAP §5
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

Domains sells and manages domain names as recurring billable items: TLD catalog & pricing, registration, transfer-in,
renewal, expiry lifecycle (grace → redemption → expired), registrar integration through drivers, availability lookup,
renewal reminders (ICANN-style), and client self-service (nameservers, lock, EPP, contacts, auto-renew).
It does **not** own invoices (Billing, via `Invoicer`), the cart (Orders, via `DomainPricing`), or email rendering (Notifications).

## 2. Entities & key fields

### tlds

`id, extension (".com", lowercase, unique; IDN TLDs stored as punycode + display_unicode), registrar_key (nullable = manual handling),
auto_register (bool), epp_required (bool, default true), min_years (1), max_years (10), allow_register/allow_transfer (bool),
grace_days (int, 0 = off), grace_fee (int minor, default currency), redemption_days (int, 0 = off), redemption_fee (int minor),
renew_window_days (nullable; renewal orders only allowed within N days before expiry, e.g. 180 for some ccTLDs),
addon_dns (bool), addon_id_protection (bool), addon_email_forwarding (bool), is_spotlight (bool), badge (null|hot|new|sale),
additional_fields (json schema, nullable), sort_order, is_active, timestamps`

- `extension` normalised: lowercase, leading dot, no trailing dot. **MUST**
- Grace/redemption fees are stored once in the default currency and converted at invoice time with the current exchange rate. **MUST**
- `additional_fields` (e.g. nexus/purpose questions) collected at checkout, passed to driver, editable by staff only. **SHOULD**

### tld_prices

`id, tld_id, currency (char3), client_group_id (nullable = base slab), years (1–10), register (int minor|null), transfer (int minor|null), renew (int minor|null)`

- Unique `(tld_id, currency, client_group_id, years)`. `null` = term not offered for that action (we do not use `-1` sentinels). **MUST**
- Lookup order: client-group slab row → base row. Missing currency row ⇒ not sellable in that currency (no silent conversion). **MUST**
- Addon prices (`addon_dns`, `addon_id_protection`, `addon_email_forwarding`) per year per currency in `tld_addon_prices (tld_id, currency, addon, price)`; 0 = free. **SHOULD**
- Transfers are sold for exactly one term (the lowest configured transfer term, normally 1 year) which adds that term to expiry. **MUST**

### domains

`id, client_id, order_id (nullable), tld_id, name (full ASCII/punycode, lowercase), name_unicode, sld, tld, type (register|transfer),
registrar_key (nullable), status (enum §3), registration_date (date), expiry_date (date|null), next_due_date (date), next_invoice_date (date),
period_years (int), first_payment_amount, recurring_amount (int minor), currency, promotion_id (nullable), gateway_key,
auto_renew (bool, default `domains.default_auto_renew`), id_protection, dns_management, email_forwarding (bools),
registrar_lock (bool|null, cached), nameservers (json array, 2–5), epp_code (encrypted, nullable, transfer-in only, wiped after success),
is_premium (bool), premium_renew_cost (int minor|null), linked_service_id (nullable, free-domain bundle), additional_fields (json),
last_synced_at, last_sync_status (ok|error|unsupported), last_error (text), registrar_meta (json), timestamps, soft deletes`

- Unique active name: at most one non-terminal (`pending|pending_registration|pending_transfer|active|grace|redemption`) row per `name`. **MUST**
- Dates are date-only, evaluated in `system.timezone`. **MUST**

### domain_contacts

`id, domain_id, role (registrant|admin|tech|billing), first_name, last_name, company, email, phone, address_1, address_2, city, state, postcode, country, synced_at`

- Local cache; registrar is source of truth when a driver supports `getContacts`. **SHOULD**

### domain_reminders (ICANN log, never pruned)

`id, domain_id, client_id, expiry_date (cycle key), offset_days (int, negative = after due), template_key, recipients (json), sent_at`

- Unique `(domain_id, expiry_date, offset_days)` → idempotent sends. **MUST**

### registrar_accounts & registrar_command_log

- `registrar_accounts: key (unique, driver key), is_active, settings (encrypted json), test_mode`. **MUST**
- `registrar_command_log: id, domain_id, registrar_key, command, request (json, secrets redacted), response (json, truncated 16KB), success, error, duration_ms, staff_id|null, created_at`. Retention `domains.command_log_retention_days: 90`. **MUST**

## 3. Status enum & transitions

`pending, pending_registration, pending_transfer, active, grace, redemption, expired, transferred_away, cancelled, fraud`

| From                     | To                   | Trigger                                                                       |
| ------------------------ | -------------------- | ----------------------------------------------------------------------------- |
| pending                  | active               | register succeeded synchronously                                              |
| pending                  | pending_registration | registrar accepted but async (or result unknown/timeout)                      |
| pending                  | pending_transfer     | transfer request accepted by registrar                                        |
| pending                  | cancelled / fraud    | order cancelled / marked fraud                                                |
| pending_registration     | active               | sync confirms registration                                                    |
| pending_transfer         | active               | transfer sync reports completed (expiry from registrar)                       |
| pending_transfer         | pending              | transfer sync reports failed (`last_error` = reason; client may resubmit EPP) |
| active                   | grace                | today > expiry_date and today ≤ expiry_date + grace_days                      |
| grace                    | redemption           | today > expiry_date + grace_days and ≤ + redemption_days                      |
| active/grace/redemption  | expired              | window exhausted (or grace & redemption both 0)                               |
| grace/redemption/expired | active               | successful renewal (expired → active only via staff manual action)            |
| active                   | transferred_away     | sync reports transferred away                                                 |
| active/expired           | cancelled            | staff action (manual domains), or registrar reports cancelled                 |

- Status is changed only through a `DomainStateMachine` service; every change writes activity log + emits `DomainStatusChanged`. **MUST**
- Staff may force any status (permission `domains.domains.update`), logged with reason. **MUST**

## 4. Business rules & calculations

### 4.1 Pricing contract (consumed by Orders)

`DomainPricing::price(tld, action: register|transfer|renew, years, currency, ?clientGroupId): ?Money` and `terms(tld, action, currency, ?group): int[]`. **MUST**

- Existing domains renew at their stored `recurring_amount` (price locked at purchase); price list changes affect only new orders and client-initiated renewal orders. **MUST**
- Client-initiated renewal order (cart) uses current renew price. `domains.enable_renewal_orders: true`. **MUST**
- Maximum horizon: a renewal of N years is offered only if `expiry_date + N years ≤ today + 10 years − 1 day`. **MUST**
- `renew_window_days`: hide renewal orders until `today ≥ expiry_date − renew_window_days`. **SHOULD**
- Premium names (driver flags premium): register/renew 1 year only; price = registrar cost × (1 + markup%) using bracket table `domains.premium_markup: [{below: 20000, percent: 20}, …]`; disabled by `domains.premium_enabled: false`. **COULD**

### 4.2 Registration / transfer provisioning

- Submitted to registrar only after the order invoice line is paid; never before payment. **MUST**
- If `tld.auto_register` and Orders allows auto-provision (see orders.md fraud rules) → queue `RegisterDomain`/`TransferDomain` job on `InvoicePaid`; else wait for staff "accept". **MUST**
- Nameservers: from order if supplied, else linked hosting server's nameservers, else `domains.default_nameservers: []` (2–5 hostnames). Validated as hostnames, de-duplicated. **MUST**
- Contacts: registrant = client profile; admin/tech/billing = client profile when `domains.use_client_contacts: true`, else `domains.default_contact` (company details). **MUST**
- Lock enabled after registration/transfer when supported: `domains.lock_by_default: true`. **SHOULD**
- On registrar timeout/unknown result: set `pending_registration`, do **not** auto-retry register; next sync or staff "check status" resolves (prevents double registration/charge). Explicit errors → stay `pending`, `RegistrarActionFailed`, staff task. **MUST**

### 4.3 Renewal invoicing

- Daily job selects domains with `status = active`, `auto_renew = true`, `next_invoice_date ≤ today`, no open renewal invoice for this `next_due_date`. **MUST**
- `next_invoice_date = next_due_date − domains.invoice_lead_days` (default 30; if null fall back to `billing.invoice_lead_days: 14`). **MUST**
- `next_due_date = expiry_date − domains.next_due_offset_days: 0` whenever expiry changes (sync/renew). **MUST**
- Invoice line references `billable_type=domain, billable_id, period_start=next_due_date`, idempotency key `domain:{id}:renew:{next_due_date}`. **MUST**
- Free-domain guard: if `recurring_amount = 0` and `domains.free_renewal_requires_service: true`, renew only when `linked_service_id` service is active with the same `next_due_date`; otherwise no renewal, staff notification `FreeDomainRenewalBlocked`, staff task. **MUST**
- Clients cannot place paid renewal orders for domains that are free with an active bundled service. **SHOULD**
- Turning `auto_renew` off cancels an unpaid renewal invoice with no partial payment (single-line) or removes the line (multi-line; Billing issues the adjustment). **MUST**

### 4.4 Payment → renewal

- On `InvoicePaid` with a domain renewal line and `domains.renew_on_payment: true`: queue `RenewDomain(years = line years)`. Partial payment never triggers. **MUST**
- Success: `expiry_date += years` (or registrar-returned date), recompute dates, status → active, emit `DomainRenewed`. Failure: `RegistrarActionFailed`, staff task, client sees "renewal processing". **MUST**
- Renewal for a manual (no registrar) domain: extend dates locally and create a staff task to renew at the registrar. **MUST**

### 4.5 Grace / redemption invoicing

- Global switch `domains.grace_redemption_enabled: true`. When off (or TLD has 0 days), an unpaid renewal invoice is cancelled the day after expiry. **MUST**
- On entering `grace` with `grace_fee > 0` (or `redemption` with `redemption_fee > 0`): cancel the open renewal invoice and issue a replacement containing renewal + fee (never edit an issued invoice). Fee converted to invoice currency. **MUST**
- On entering `expired`: single-line renewal invoice → cancelled; multi-line → line removed via Billing adjustment and `InvoiceModified` notification. **MUST**
- Renewal paid during redemption: driver `renew` is called with `restore = true` flag (driver may map to a restore command). **SHOULD**

### 4.6 Transfer-in flow

1. Lookup returns `registered` → cart offers "transfer". Block if name exists in our system in a non-terminal status. **MUST**
2. EPP code required when `tld.epp_required`; stored encrypted on the domain; never shown back to the client. **MUST**
3. Invoice = transfer price for the single transfer term (+ addons). **MUST**
4. On payment → `TransferDomain`; success → `pending_transfer`, emit `DomainTransferInitiated`. **MUST**
5. Transfer sync each run: completed → `active`, expiry from registrar, EPP wiped, emit `DomainTransferred`; failed → `pending`, reason stored, emit `DomainTransferFailed`; client may submit a new EPP code and staff/client retries without a new invoice. **MUST**
6. `pending_transfer` longer than `domains.transfer_stale_days: 14` → staff notification once. **SHOULD**

### 4.7 Outbound / ownership

- EPP retrieval requires unlocked-or-lockable domain; driver may return `{code}` or `{emailed: true}` (registrar emails registrant). Every retrieval is logged with actor. **MUST**
- Contact changes may start a registrar-side change-of-registrant verification and a 60-day transfer lock; client UI shows driver-returned `contact_change_pending` / `transfer_lock_until` flags. **SHOULD**
- "Move to another client" (staff): moves domain only; existing invoices stay with the old owner; next renewal invoices the new owner. **MUST**
- Deleting a domain record never calls the registrar. **MUST**

## 5. Registrar driver contract (`App\Contracts\Domains\RegistrarDriver`)

`capabilities(): string[]` — subset of: `availability, suggestions, register, transfer, renew, restore, info, nameservers, lock, epp, contacts, id_protection, sync, transfer_sync, child_nameservers, dns, delete, tld_pricing`.

| Method                                                                                | Returns                                                                                                            |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `checkAvailability(string $sld, array $tlds)`                                         | `AvailabilityResult[]` (status `available                                                                          | registered                       | reserved | invalid | unknown`, `is_premium`, premium prices) |
| `suggest(string $term, array $tlds, int $limit)`                                      | available `AvailabilityResult[]`                                                                                   |
| `register(RegisterRequest)` / `transfer(TransferRequest)`                             | `RegistrarResult{status: completed                                                                                 | pending, expiry_date?, message}` |
| `renew(Domain, int $years, bool $restore)`                                            | `RegistrarResult{expiry_date}`                                                                                     |
| `info(Domain)`                                                                        | `DomainInfo{nameservers, locked, expiry_date, status, id_protection, contact_change_pending, transfer_lock_until}` |
| `setNameservers`, `setLock(bool)`, `setIdProtection(bool)`, `setContacts(ContactSet)` | `RegistrarResult`                                                                                                  |
| `getContacts(Domain)`                                                                 | `ContactSet`                                                                                                       |
| `getEppCode(Domain)`                                                                  | `{code?: string, emailed: bool}`                                                                                   |
| `sync(Domain)`                                                                        | `SyncResult{active, cancelled, transferred_away, expiry_date}`                                                     |
| `transferSync(Domain)`                                                                | `TransferSyncResult{completed, failed, reason, expiry_date}`                                                       |
| `testConnection()`                                                                    | `bool + message`                                                                                                   |

- Unsupported calls throw `CapabilityNotSupported`; transient errors throw `RegistrarException(retryable: true)`; UI hides actions not in `capabilities()`. **MUST**
- All calls go through a wrapper that writes `registrar_command_log` and redacts secrets (EPP, passwords, API keys). **MUST**
- Drivers v1: `log` (deterministic demo: names containing "taken" are registered) **MUST**; `manual` (emails staff, staff completes) **SHOULD**.

## 6. Lookup & suggestions

- `GET /api/domains/lookup?q=` — normalise (trim, lowercase, strip scheme/`www.`/path, IDN → punycode if `domains.allow_idn: false` rejects non-ASCII). **MUST**
- No TLD in query → check spotlight TLDs (max 8) + `domains.lookup_tlds`. Unknown TLD → `unsupported`. **MUST**
- Provider = `domains.lookup_registrar` (driver with `availability`), batch call; results cached `domains.lookup_cache_seconds: 300`. **MUST**
- Suggestions: driver `suggest` if capable; else generate SLD × `domains.suggestion_tlds` filtered to available, max `domains.suggestion_limit: 10`. **SHOULD**
- Rate limit 30/min per IP; captcha hook when `security.captcha_on_domain_lookup: false`. **MUST**
- Response includes per-term prices for the requesting client's currency/group. **MUST**

## 7. Admin capabilities

- TLD CRUD, pricing grid per currency × client-group slab × years (register/transfer/renew), bulk "set years 2–10 = N × 1-year", bulk grace/redemption config. **MUST**
- Registrar accounts: configure, test connection, enable/disable. **MUST**
- Domain list (filters: status, registrar, TLD, expiring within N days, client) & detail: edit dates/amounts/period/status, "recalculate recurring amount" checkbox, toggles (auto-renew, addons), nameservers, lock, contacts, EPP, run commands (register, transfer, renew, sync now), command log, reminder log, move to client. **MUST**
- Staff tasks for manual actions (failed registration, free-domain renewal, manual registrar). **SHOULD**

## 8. Client-area capabilities (client_user permission `domains`)

- List domains (status, expiry, auto-renew badge); detail page. **MUST**
- Edit nameservers (2–5), toggle lock, toggle auto-renew, get EPP code (confirm dialog), edit contacts, toggle ID protection (if purchased). **MUST**
- Renew now (cart renewal) when enabled; transfer-in and register via cart. **MUST**
- Bulk: nameservers / auto-renew / lock for selected domains. **SHOULD**
- Actions unavailable in `pending*`, `expired`, `transferred_away`, `cancelled`, `fraud` statuses except viewing. **MUST**

## 9. Automation (scheduler; all jobs idempotent, overlap-locked)

| Job                        | Schedule                            | Behaviour                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------------- | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `domains:sync`             | hourly                              | `pending_transfer`: all, every `domains.transfer_sync_interval_hours: 1`; `active` & `pending_registration`: batch `domains.sync_batch_size: 50`, oldest `last_synced_at` first, each domain at most every `domains.sync_interval_hours: 4`. `domains.sync_enabled: true`, `domains.sync_notify_only: false` (report diffs, change nothing). Staff digest `DomainSyncReport` if any change/error.    |
| `domains:lifecycle`        | daily (`automation.daily_run_time`) | apply date-based transitions §3 (fallback when no sync), grace/redemption invoice swap §4.5                                                                                                                                                                                                                                                                                                          |
| `domains:invoice-renewals` | daily                               | §4.3                                                                                                                                                                                                                                                                                                                                                                                                 |
| `domains:send-reminders`   | daily                               | offsets `domains.reminder_offsets: [30, 7, -3]` (≤5 entries, negative = days after due); skips if renewal invoice paid, auto-renew off and `domains.remind_when_auto_renew_off: true` decides, free domains unless `domains.remind_free_domains: false`; aggregates multiple domains per client per day into one email; writes `domain_reminders`. Default meets "2 before + 1 after" ICANN minimum. |

- Sync never moves `expiry_date` backwards by more than 1 year without flagging for staff review. **MUST**
- Sync updates `next_due_date` only when no open renewal invoice exists for the current due date. **MUST**

## 10. Events emitted

Existing: `DomainRegistered`, `DomainRenewed`, `DomainTransferred`, `DomainExpiring(daysLeft; negative = after due)`, `DomainExpired`, `RegistrarActionFailed`.
New: `DomainTransferInitiated`, `DomainTransferFailed(reason)`, `DomainStatusChanged(from,to)`, `DomainTransferredAway`, `DomainNameserversUpdated`,
`DomainContactsUpdated`, `DomainEppCodeRetrieved(actor)`, `DomainAutoRenewChanged`, `FreeDomainRenewalBlocked`, `DomainSyncReport(changes[])`.
All dispatched after DB commit.

## 11. Edge cases & pitfalls

- Registrar success but our DB write fails (or timeout) → duplicate registration/charge on retry; always reconcile via `info` before re-submitting.
- Manual staff date edits are overwritten by sync — show "last synced" and warn on save.
- Free domain bundled with monthly hosting: domain due dates drift from the service → guard in §4.3.
- Price changes don't reprice existing domains (by design); bulk repricing is out of scope.
- Multi-year transfers are mostly unsupported by registries; sell one term only.
- 10-year registry cap: a 10-year registration can renew at most 9 years later.
- Paying an invoice for an already expired/unrecoverable domain — invoice must be cancelled/adjusted at expiry (§4.5).
- Exchange-rate conversion of grace/redemption fees changes over time; compute at invoice creation.
- IDN: uniqueness and lookups always on punycode; display unicode.
- Contact edits can trigger a 60-day transfer lock — warn before saving.
- Some registrars email the EPP code instead of returning it; UI must handle `emailed`.
- Transfer-away detection only via sync; stop invoicing immediately on `transferred_away`.

## 12. Acceptance criteria

1. Given a TLD with base and client-group prices, When a group member prices `.com` register 1y, Then the group price is returned; non-members get base.
2. Given no price row for EUR, When a EUR client looks up `.com`, Then the TLD is reported not sellable.
3. Given a register line unpaid, When the order is placed, Then no registrar call is made.
4. Given `auto_register` on, When the register invoice is paid, Then a `RegisterDomain` job is queued once (paying twice/webhook replay does not queue twice).
5. Given the driver times out on register, When the job ends, Then status is `pending_registration` and no automatic re-register occurs.
6. Given an active domain with next_due 2027-01-31 and lead 30, When the daily job runs on 2027-01-01, Then exactly one renewal invoice is created; re-running creates none.
7. Given `auto_renew=false`, When the job runs, Then no renewal invoice is created.
8. Given a free domain whose linked service is suspended or has a different due date, When renewal is due, Then no renewal is sent and `FreeDomainRenewalBlocked` is emitted.
9. Given a paid renewal of 2y, When the driver succeeds, Then expiry and next_due advance 2 years and `DomainRenewed(years=2)` fires.
10. Given a partially paid renewal invoice, Then no renew command is sent.
11. Given expiry yesterday and grace 30 days with fee 0, When lifecycle runs, Then status = grace and the renewal invoice is untouched.
12. Given grace fee > 0, When the domain enters grace, Then the original invoice is cancelled and a new one with renewal + fee is created.
13. Given grace & redemption = 0 and an unpaid single-line renewal invoice, When the day after expiry is processed, Then status = expired and the invoice is cancelled.
14. Given a multi-line invoice containing an expired domain, Then only the domain line is removed and `InvoiceModified` is emitted.
15. Given expiry 2030-06-01 today 2026-10-17, When renewal terms are listed, Then terms exceeding 2036-10-16 are hidden.
16. Given TLD `epp_required`, When a transfer is added without EPP, Then validation fails.
17. Given `pending_transfer`, When transfer sync returns completed with expiry X, Then status = active, expiry = X, EPP code wiped, `DomainTransferred` fired.
18. Given transfer sync returns failed, Then status = pending, reason stored, `DomainTransferFailed` fired.
19. Given `sync_notify_only=true`, When sync finds a different expiry, Then nothing changes and the diff appears in `DomainSyncReport`.
20. Given offsets [30,7,-3], When reminders run on each matching day, Then one log row per offset per expiry cycle exists and reruns send nothing.
21. Given a client with three domains due in 30 days, Then one aggregated reminder email lists all three.
22. Given a client without `domains` permission, When they call the nameserver update endpoint, Then 403.
23. Given a driver lacking `lock`, Then the lock toggle is hidden and the API returns 422 `capability_not_supported`.
24. Given lookup `HTTPS://WWW.Example.COM/path`, Then the normalised query is `example.com`.
25. Given EPP retrieval by client or staff, Then an activity log entry with actor and domain is written.

## 13. Out of scope for v1

DNS record hosting/management, email forwarding management, child nameserver registration, TLD & pricing import from registrar,
bulk repricing of existing domains, backorders/auctions, WHOIS-server lookup provider, AI name spinning, IRTP opt-out flows,
domain release/push (registry-specific tags), registrar balance monitoring, SSL monitoring of domains.
