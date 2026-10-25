# Platform Admin (Core) — Product Spec (v1)

Status: draft for engineering · Owner: Core workstream · Refines FEATURE_MAP §0
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## Deferred (v1.1)

Deferred by the CTO in the PR #3 review; each item gets a follow-up issue:

- **Client delete / anonymise** (§11, AC19). Closing is the only lifecycle exit in v1.
- **Maintenance mode** (§7, AC13). Use `php artisan down` (with `--secret`) meanwhile.
- **Password history** (§4, `password_histories`, AC8).
- **Auto-inactive job** (§11, AC21). (Exchange-rate auto-update shipped with the Currencies workstream, §6.)
- **Staff tasks** (§2) and **staff API tokens** (§4).

Implementation notes (v1 deviations from this spec):

- Setting keys: `core.company_name`, `core.company_email`, `core.default_country`, `core.date_format`,
  `core.automation_time` (spec: `company.*`, `automation.daily_run_time`). Platform keys:
  `security.staff_2fa_required`, `security.login_max_attempts`, `security.login_lockout_minutes`,
  `security.login_ip_allowlist`, `platform.impersonation_minutes`, `platform.invitation_expiry_days`,
  `platform.activity_log_retention_days`.
- Sensitive permissions (impersonate, merge, close, `core.users.*`, `core.staff.*`, `core.roles.*`, `core.settings.security`) are
  never granted by wildcards and need a recent password confirmation (see `docs/security/standards.md`).
- Currencies: v1 supports 2-decimal currencies only (ADR 0002); the `decimals` column is kept for later.
- Date format: one `core.date_format` for both areas (spec: `localization.admin_date_format` and
  `localization.client_date_format`, default `Y-m-d`). Allowed values are `Core\Support\DateFormats::OPTIONS`; the
  default is `M j, Y` ("Oct 24, 2026"), and any stored value off the list falls back to it.
- Client merge: Core moves contacts and logins and dispatches `ClientMerged` inside the transaction; every other module
  re-points its own tables in a listener and can preview or veto a merge through `ClientMergeRegistry`.
- AC18: Core closes the client and emits `ClientClosed`. Cancelling invoices and services and turning off domain
  auto-renew are owned by the Billing, Services and Domains listeners, so the criterion is met once those listeners land.

## 1. Purpose & scope

Cross-cutting administration: staff accounts, roles & permissions, staff 2FA and login security, client groups, currencies & exchange rates,
the settings catalog, activity log, admin global search, login-as-client (impersonation), client close/merge, maintenance mode and staff tasks.

## 2. Entities & key fields (extends existing Core migration)

- `users` (staff when `is_staff`): add `first_name, last_name, is_active, language, timezone, ticket_signature, private_notes, two_factor_secret (encrypted), two_factor_recovery_codes (encrypted, hashed codes), two_factor_confirmed_at, password_changed_at, last_login_ip, invited_at`. **MUST**
- `staff_invitations (id, email, role_id, token_hash, expires_at (7 days), accepted_at, invited_by)`. **MUST**
- `roles` (existing `name, permissions json`): add `description, email_categories json (system|account|support), is_system`. **MUST**
- `password_histories (user_id, password_hash, created_at)` — last `security.password_reuse_limit: 4`. **MUST**
- `client_groups` (existing): add `exempt_from_suspension (bool), separate_invoices (bool)`. **MUST**
- `currencies` (existing): add `decimals (0|2|3, default 2), format (1234.56|1,234.56|1.234,56|1 234,56), rate_updated_at, is_auto_rate`. **MUST** (existing table lacks decimals/format)
- `clients`: add `merged_into_client_id (nullable), closed_at, email_preferences json, send_overdue_reminders, apply_late_fees`. **MUST**
- `activity_log` (existing): add `actor_type (staff|user|system|api), impersonator_id (nullable), event (machine key), user_agent`. **MUST**
- `staff_tasks (id, title, description, due_at, assigned_to, status (open|done|dismissed), related_type, related_id, source (manual|system), created_at)` — target for "manual action required" items from Domains/Services. **SHOULD**
- `impersonation_sessions (id, staff_id, client_id, user_id, started_at, ended_at, ip)`. **MUST**

## 3. Roles & permissions

- Permission strings `module.area.action`; wildcards `*`, `module.*`, `module.area.*`. A staff user has exactly one role. **MUST**
- Seeded roles: `Full Administrator` (`*`), `Billing`, `Support`, `Sales` (read clients/orders, accept orders, quotes). System roles editable but not deletable. **MUST**
- At least one active staff with `*` must always exist (block demote/disable/delete of the last one). **MUST**
- `view` grants list + detail; mutating actions are separate. Controllers authorise via Gates/Policies mapped 1:1 to the catalog. **MUST**
- Note: the current seeder uses `core.clients.manage`, which is not in the catalog below — replace with explicit actions (no implicit "manage" alias). **MUST**

### Permission catalog (v1)

| Module        | Permissions                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| core          | `core.clients.view` `core.clients.create` `core.clients.update` `core.clients.delete` `core.clients.close` `core.clients.merge` `core.clients.impersonate` `core.clients.export` `core.contacts.manage` `core.users.manage` `core.users.reset_2fa` `core.client_groups.manage` `core.currencies.manage` `core.currencies.rates` `core.settings.view` `core.settings.update` `core.staff.view` `core.staff.manage` `core.roles.manage` `core.activity_log.view` `core.automation.view` `core.automation.run` `core.tasks.manage` `core.maintenance.toggle` |
| catalog       | `catalog.products.view` `catalog.products.manage` `catalog.addons.manage` `catalog.config_options.manage`                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| orders        | `orders.orders.view` `orders.orders.create` `orders.orders.accept` `orders.orders.cancel` `orders.orders.mark_fraud` `orders.orders.delete` `orders.promotions.manage` `orders.fraud.configure`                                                                                                                                                                                                                                                                                                                                                           |
| billing       | `billing.invoices.view` `billing.invoices.create` `billing.invoices.update` `billing.invoices.cancel` `billing.invoices.delete` `billing.invoices.send` `billing.payments.record` `billing.refunds.create` `billing.credit.manage` `billing.transactions.view` `billing.transactions.manage` `billing.quotes.view` `billing.quotes.manage` `billing.gateways.configure` `billing.tax.configure` `billing.income.view`                                                                                                                                     |
| services      | `services.services.view` `services.services.update` `services.services.provision` (create/suspend/unsuspend/terminate/change package/password) `services.services.delete` `services.cancellations.manage` `services.servers.manage` `services.module_log.view`                                                                                                                                                                                                                                                                                            |
| domains       | `domains.domains.view` `domains.domains.update` `domains.domains.registrar_commands` `domains.domains.epp_view` `domains.domains.delete` `domains.tlds.manage` `domains.registrars.configure`                                                                                                                                                                                                                                                                                                                                                             |
| support       | `support.tickets.view` `support.tickets.access_all` `support.tickets.reply` `support.tickets.note` `support.tickets.assign` `support.tickets.update` `support.tickets.close` `support.tickets.merge` `support.tickets.merge_cross_client` `support.tickets.edit_messages` `support.tickets.delete` `support.departments.manage` `support.canned_replies.manage` `support.kb.manage` `support.announcements.manage` `support.network.manage` `support.spam.manage` `support.inbound_log.view`                                                              |
| notifications | `notifications.templates.view` `notifications.templates.manage` `notifications.email_log.view` `notifications.email.send` `notifications.webhooks.manage`                                                                                                                                                                                                                                                                                                                                                                                                 |
| affiliates    | `affiliates.affiliates.view` `affiliates.affiliates.manage` `affiliates.commissions.manage` `affiliates.withdrawals.process` `affiliates.settings.configure`                                                                                                                                                                                                                                                                                                                                                                                              |
| reports       | `reports.dashboard.view` `reports.{report_key}.view` (one per report, see reports.md) `reports.exports.create`                                                                                                                                                                                                                                                                                                                                                                                                                                            |

- Dashboard widgets and global-search groups are filtered by these permissions. **MUST**

## 4. Staff accounts & authentication

- Create staff by invitation (email link, 7-day expiry, resend/cancel) or with a password set by an admin (forced change on first login). **MUST**
- Email = login; unique among staff. Disable (`is_active=false`) instead of delete when the staff has history; disabled staff sessions & API tokens are revoked immediately. **MUST**
- Password policy: `security.password_min_length: 12` (12–64), must contain a letter and a digit, reuse of last N blocked, breach-list check **COULD**. **MUST**
- Lockout: `security.login_max_attempts: 5` per email+IP per `security.login_lockout_minutes: 15`; `security.login_ip_allowlist: []` exempt; after 10 failures for one account in 1 h emit `StaffLoginFailuresThreshold`. **MUST**
- Sessions: `security.staff_idle_timeout_minutes: 120`; regenerate on login; logout-everywhere from profile. **MUST**
- 2FA (TOTP, RFC 6238, 6 digits, 30 s, ±1 step) with 10 single-use recovery codes (stored hashed, regenerable). Uses the starter kit's Fortify 2FA. **MUST**
- `security.staff_2fa_required: true` → staff without 2FA are forced to enrol after password login before any admin route. **MUST**
- Staff with `core.users.reset_2fa` can reset a client user's 2FA; `core.staff.manage` can reset staff 2FA (not their own); all resets logged and the affected user is emailed. **MUST**
- Staff API tokens (Sanctum) carry abilities ⊆ the staff role's permissions; revoked on role change. **SHOULD**

## 5. Client groups

- Fields: name, colour, discount_percent (0–100, applied by Billing to recurring/new invoice lines at invoice generation), exempt_from_suspension, separate_invoices. **MUST**
- Domain pricing slabs reference groups (domains.md). Group with clients cannot be deleted. **MUST**

## 6. Currencies & exchange rates

- Exactly one default currency (rate fixed 1, not editable). Default can only change while no invoices/transactions exist; changing it rebases every rate exactly (twelve decimals) and records the rebase in the rate history. **MUST**
- `exchange_rate` = units of this currency per 1 default unit (decimal 24,12, so a weak default such as NPR keeps
  enough significant digits; gateway rates on payments use the same precision). Conversion: `target_minor = round_half_up(source_minor × rate_target/rate_source × 10^(dec_target − dec_source))`. **MUST**
- Zero-decimal currencies (JPY, KRW, HUF in practice, …) use `decimals=0`. Formatting from prefix/suffix/format/decimals. **MUST**
- A client's currency can change only while they have no invoices/transactions (else staff must use a forced flow with warning). **MUST**
- Currency in use by clients, prices, invoices, quotes or transactions cannot be deleted; deleting clears its rate
  history so a currency re-created with the same code starts fresh. **MUST**

### 6.1 Display (built 2026-10-18)

- Every amount people read uses the currency's configured display: prefix, suffix and number format. Formats:
  `1234.56`, `1,234.56`, `1.234,56`, `1 234,56` and `1,23,456.78` (Indian lakh/crore grouping).
  Seeds: USD `$1,234.50`; INR `₹1,23,456.00`; NPR `Rs 1,23,456.00` (prefix "Rs ", replacing "रू ").
  The migration changes INR/NPR rows only where they still hold the old seed values.
- Negatives put a true minus (U+2212) before the symbol: `−Rs 1,200.00`, `−$12.00`; ledgers may sign credits `+$12.00`.
- One source of truth: `App\Support\Currencies\MoneyFormatter` (server: dashboard cards and list meta, search
  results, merge fields, user-facing messages, PDFs) and `formatMoney` / `<Money>` (browser), which read the same
  settings from the shared, cached `currencyFormats` Inertia prop. Currencies that are not configured fall back to
  the Intl narrow symbol. `Money::format()` ("24.99 USD") is for logs, audit and CLI only.
- ISO codes stay where they identify a currency, not an amount: switchers and selects, settings, CSV columns, API,
  logs and audit. Amount inputs label the currency as "Amount (Rs, NPR)".
- Invoice and quote PDFs print symbols on amounts and state the currency once under the totals ("All amounts in
  NPR"). The PDF font has no Devanagari, so a customised Devanagari symbol prints as the ISO code there.

### 6.2 Exchange-rate sources (built 2026-10-18)

- Each non-default currency has a rate source: **Manual** (staff enter the rate) or a registered source. Sources
  implement `App\Contracts\Currencies\ExchangeRateSource` and register in `ExchangeRateSources`; a keyed commercial
  provider fits the same contract with its key in a secret setting. A source is offered only for currencies it quotes.
    - **ECB reference rates** (`ecb`): `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml`, euro-based,
      about 30 currencies including USD and INR, not NPR; published on working days around 16:00 CET. Source page:
      https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html
    - **Nepal Rastra Bank** (`nrb`): FOREX API v1, `GET https://www.nrb.org.np/api/forex/v1/rates?page&per_page&from&to`
      (all four required; `per_page` ≤ 100), daily `buy`/`sell` in NPR per `currency.unit` (e.g. 100 INR); we take the
      latest day in the last week and use the mid rate. Docs: https://www.nrb.org.np/api-docs-v1 . The live response
      names the code `currency.iso3` (the docs say `ISO3`); both are read.
- Fetches use Laravel's Http client with a 5 s connect and 10 s total timeout, no redirects and a 1 MB response
  cap, and never run on page loads. Feed values must be plausible (positive, at most 12 significant digits; NRB
  buy ≤ sell) and the derived rate must lie between 0.000000001 and 1,000,000,000 per default unit; anything else fails
  that currency with an audit entry and keeps its rate. Every rate change (fetches, manual edits, review
  decisions, default-currency rebases) runs under one lock, and the currency row is locked and re-read before
  old → new is computed. A currency has at most one pending review (unique `pending_currency`). Each source
  is fetched once per update; every rate is derived through the default currency as an exact fraction
  (`units × base_default ÷ (base × units_default)`), rounded half up to twelve decimals without floats (arbitrary-
  precision decimal strings; bcmath is not required).
- **Schedule:** the `platform.exchange-rates` automation task runs first in the daily pipeline (stage
  `EXCHANGE_RATES`), so renewals and converted prices use the day's rates. Setting `platform.rates_update_time`
  (HH:MM) moves the fetch to that time: the pipeline task records "skipped: rates update at HH:MM" and the
  scheduled `platform:update-exchange-rates` command fetches and replaces that record, once per date. Admin → Currencies →
  **Update rates now** runs it on demand.
- **Safety:** a fetched rate that moved more than `platform.rates_max_change_percent` (default 10; 0 = off) from the rate
  in force is not applied. It becomes a pending review (one per currency, replaced by newer proposals), shows on the
  currencies page and as a dashboard "Needs attention" item, and staff approve or keep the current rate. A manual rate
  change supersedes a pending review. Auto-sourced currencies whose last successful fetch is older than
  `platform.rates_stale_after_days` (default 3), and manual rates not changed for
  `platform.rates_manual_stale_after_days` (default 30), are flagged as stale on the page and in "Needs attention". A failed
  fetch fails the automation task with the reason, keeps the old rates and is audit-logged.
- **Audit and history:** every rate change writes `exchange_rate_history (currency, rate, source, effective_at,
  user_id)` and an activity entry (`currency.rate_changed`, old → new, source, who; system for automation). Held,
  approved and rejected changes, source/rule edits and failed fetches are logged too. The currency edit page charts
  the rate in force for each of the last 90 days.
- `ExchangeRatesUpdated(currencies, source)` is dispatched after changes (spec §14).

### 6.3 Converted prices (built 2026-10-18)

- Per currency: an FX buffer (0–50 %, two decimals) and a rounding rule: none, nearest whole unit, nearest 10,
  nearest .99. Converted price = default price × rate × (1 + buffer), rounded once to the minor unit, then the rounding
  rule (`Currency::priceFromDefault()`). A rule applies only from its unit up (whole and .99: 1.00; tens: 10.00);
  smaller amounts keep their exact minor units and zero stays zero.
- Modules expose conversion through `App\Contracts\Currencies\PriceConverter` (registered in `PriceConverters`):
  Catalog (products, addons, configurable options) and Domains (TLD prices, base and client-group slabs). Admin →
  Currencies → **Converted prices** previews each one (count of changes, unchanged and manual rows, and a sample of
  before → after) and applies it after confirmation. With `catalog.auto_convert_prices` / `domains.auto_convert_prices`
  on, a rate change queues one `ConvertPricesAfterRateChange` job (after commit, unique per currency set until it starts, so a change during a running conversion queues another; a module's
  failure is reported and audited, never failing the rate save), and each module's daily convert-prices task is the
  backstop.
- A price set manually in a non-default currency is never converted: the Catalog price matrix has a lock per cell
  (typing a price in another currency locks it), and the TLD price grid has a "Set manually" switch per currency and
  slab. Converted rows whose default-currency price was removed are turned off, never deleted. On upgrade, existing
  TLD prices in other currencies (and Catalog prices when automatic conversion was off) are marked "set manually".
- Existing services and domains are never repriced: they renew at their stored recurring amount. Issued invoices keep
  their amounts, and payments converted by a gateway keep the rate stored on the transaction (refunds use it).

### 6.4 Permissions

- `core.currencies.manage`: currencies list and display settings.
- The currency forms warn before leaving with unsaved changes; making a currency the default asks for
  confirmation and explains that every rate is recalculated.
- `core.currencies.rates` (sensitive tier, granted only explicitly or by `*`; every change needs a recent password):
  creating and deleting currencies, rate source and manual rate, rounding and buffer, update now, approve/reject,
  change the default currency, converted prices, and the "Exchange rates", "Converted product prices" and
  "Converted TLD prices" settings sections. Roles that held only `core.currencies.manage` lose rate editing on
  upgrade; grant `core.currencies.rates` explicitly.

## 7. Settings catalog

Typed registry (`SettingDefinition: key, type, default, rules, is_secret, owner_module`), stored in `settings` (json), cached, invalidated on write; changes activity-logged with old/new (secrets masked). Owning modules define their keys; the admin UI groups by module. **MUST**

| Group        | Keys (default)                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| company      | `company.name ("Hosting Co")`, `company.email`, `company.url`, `company.logo_url`, `company.address`, `company.tax_id`, `company.pay_to_text`                                                                                                                                                                                                                                                                                                                       |
| system       | `system.url` (client area base URL), `system.timezone ("UTC")`, `system.records_per_page (25)`, `system.maintenance_mode (false)`, `system.maintenance_message`, `system.maintenance_redirect_url`, `system.allow_client_registration (true)`, `system.activity_log_retention_days (365)`                                                                                                                                                                           |
| localization | `localization.default_language ("en")`, `localization.default_country ("US")`, `localization.admin_date_format ("Y-m-d")`, `localization.client_date_format ("Y-m-d")`, `localization.language_menu (true)`                                                                                                                                                                                                                                                         |
| mail         | `mail.from_name`, `mail.from_email`, `mail.system_from_name`, `mail.system_from_email`, `mail.disabled (false)`, `mail.bcc ([])`, `mail.header_html`, `mail.footer_html`, `mail.css`, `mail.signature`                                                                                                                                                                                                                                                              |
| billing      | `billing.invoice_number_format ("{NUMBER}")`, `billing.invoice_prefix ("")`, `billing.next_invoice_number (1)`, `billing.invoice_lead_days (14)`, `billing.payment_reminder_days_before (7)`, `billing.overdue_reminder_days ([1, 7, 14])`, `billing.late_fee_type ("percent")`, `billing.late_fee_amount (0)`, `billing.late_fee_after_days (7)`, `billing.auto_cancel_unpaid_after_days (0)`, `billing.capture_days_before_due (0)`, `billing.pdf_enabled (true)` |
| services     | `services.suspend_enabled (true)`, `services.suspend_after_days (5)`, `services.unsuspend_on_payment (true)`, `services.terminate_enabled (false)`, `services.terminate_after_days (30)`                                                                                                                                                                                                                                                                            |
| automation   | `automation.daily_run_time ("09:00")`, `automation.client_auto_inactive ("no_active_items")` (disabled                                                                                                                                                                                                                                                                                                                                                              | no_active_items), `automation.module_log_retention_days (30)` |
| security     | `security.password_min_length (12)`, `security.password_reuse_limit (4)`, `security.login_max_attempts (5)`, `security.login_lockout_minutes (15)`, `security.login_ip_allowlist ([])`, `security.staff_2fa_required (true)`, `security.staff_idle_timeout_minutes (120)`, `security.email_verification (true)`, `security.captcha_provider ("none")`, `security.captcha_on_*` (checkout, register, login, ticket_submit, domain_lookup)                            |
| others       | `orders.*`, `domains.*`, `support.*`, `notifications.*`, `webhooks.*`, `affiliates.*`, `reports.*` — see module specs                                                                                                                                                                                                                                                                                                                                               |

- Maintenance mode blocks client area & checkout (503 page or redirect); staff, API and webhooks continue. **MUST**

## 8. Activity log

- Writes for: staff/user login success & failure, logout, impersonation start/end, settings change, role/permission change, staff create/disable, client create/update (diff)/close/merge/delete, financial actions (payments, refunds, credits, invoice cancel), provisioning & registrar commands, EPP retrieval, data export, 2FA changes. **MUST**
- Entry: actor (type + id), impersonator_id, client_id, subject, event key, human description, properties (diff, redacted), IP, user agent. **MUST**
- Retention: entries without client pruned after `system.activity_log_retention_days`; client entries kept until the client is deleted. Read-only (no edit/delete UI). **MUST**
- The list pages of the activity log, gateway log and module log page with previous and next only (`simplePaginate`), so
  no `count(*)` runs over the log (#49).
- Housekeeping of the application's own tables (#49), each at most 100,000 rows a run (`ChunkedDelete::LIMIT`):
  automation runs after `platform.automation_log_retention_days` (365, at least 90; each task keeps its latest run;
  `automation:run --date` refuses older dates, ADR 0013 Amendment (#460)),
  failed queue jobs and finished or cancelled job batches after `platform.failed_jobs_retention_days` (30, at least 7;
  unfinished batches are kept), and expired rows of the database cache and its locks. The settings are in Settings →
  Security.
- Admin view: filter by date, actor, client, event, IP, text; client profile "log" tab. **MUST**

## 9. Global admin search

- `GET /admin/search?q=` (min 2 chars, 300 ms p95 target, rate-limited) returns up to 5 results per group: clients, contacts/users, services, domains, invoices, orders, tickets, transactions. **MUST**
- Matching: integer → client id, invoice id/number, order number, service id; masked ticket number exact; email exact/prefix; domain/hostname prefix; name/company prefix; transaction reference exact; IP exact (clients' last login, orders). **MUST**
- Results only for groups the staff can view; tickets limited to accessible departments. **MUST**
- Keyboard shortcut & "see all" link to the group's filtered list. **SHOULD**

## 10. Login as client (impersonation)

- Staff with `core.clients.impersonate` starts a client-area session as the account owner (or a chosen user of that account) for that client account. **MUST**
- Session flagged; persistent banner "Signed in as … — return to admin"; 60-minute cap; ends on return/logout. **MUST**
- While impersonating: cannot change password, email, 2FA, security settings, or manage users; can place orders and open tickets (attributed "by staff X on behalf of"). **MUST**
- All actions logged with `impersonator_id`; staff users cannot be impersonated. **MUST**

## 11. Client close, reopen, delete, merge

- Close (`core.clients.close`): status `closed`, `closed_at`; unpaid invoices → cancelled; services → cancelled **without** driver termination; domains auto-renew off; users can no longer select this account; emits existing `ClientClosed`. Optional checkbox "also run termination" **COULD**. **MUST**
- Reopen: status → active; nothing else restored. **MUST**
- Delete: only when the client has no invoices/transactions (otherwise close); removes users linked solely to that client when requested. **MUST**
- Merge (`core.clients.merge`): source → target, same currency required (block otherwise); preview of counts; one DB transaction moves services, addons, domains, invoices, quotes, transactions, credit ledger, orders, tickets, contacts, files, notes, affiliate referrals; source users become non-owner users of the target; source becomes `closed` with `merged_into_client_id` (tombstone; old URLs redirect); if both are affiliates, block unless one affiliate has no ledger entries. Emits `ClientMerged`. **MUST**
- Auto-inactive job: clients older than 2 days with no active/suspended services, domains or addons → `inactive` (setting). **SHOULD**

## 12. Admin & client capabilities summary

Admin: staff/roles/invitations, own profile (password, 2FA, signature, language, timezone), client groups, currencies (update rates now), settings, activity log, search, impersonation, close/merge/delete, maintenance toggle, staff tasks. Client area: own security settings (password, 2FA), account switcher for multi-account users. **MUST**

## 13. Automation

- Exchange rates: the `platform.exchange-rates` pipeline task (§6.2). **SHOULD** (built) · `core:prune-activity-log` daily. **MUST** · `core:client-auto-inactive` daily. **SHOULD**
- `core:expire-invitations` daily. **MUST** · Scheduler heartbeat written every minute (`automation.last_heartbeat_at`) for the dashboard. **MUST**

## 14. Events emitted

Existing: `ClientRegistered`, `ClientUpdated`, `ClientClosed`. New: `ClientReopened`, `ClientMerged(sourceId, targetId)`, `ClientDeleted`, `StaffInvited`, `StaffCreated`,
`StaffDisabled`, `StaffLoggedIn`, `StaffLoginFailed`, `StaffLoginFailuresThreshold`, `StaffPasswordResetRequested`, `RoleUpdated`, `TwoFactorEnabled`, `TwoFactorReset`,
`SettingsUpdated(keys)`, `ImpersonationStarted`, `ImpersonationEnded`, `ExchangeRatesUpdated`, `MaintenanceModeToggled`, `AutomationRunCompleted`, `StaffTaskCreated`.

## 15. Edge cases & pitfalls

- Removing the last full administrator locks everyone out — enforced.
- Role changes must invalidate cached permissions and API token abilities at once.
- Exchange-rate edits silently change prices if auto-update prices is on — show impact preview.
- Changing a client's currency with existing invoices breaks credit/ledger math — blocked.
- Impersonation used to change a client's email/password = account takeover vector — blocked actions.
- Merging clients with different currencies or tax settings — currency mismatch blocks; tax profile of target wins (warn).
- Closing a client doesn't stop servers — explicit warning; termination is a separate action.
- Global search leaking tickets of departments the staff can't access.
- Settings cache stale across workers — cache keyed with a version bumped on write.
- Secret settings (API keys) must never be returned to the UI after save (write-only fields).

## 16. Acceptance criteria

1. Given a role with `support.*`, When the staff opens `/admin/invoices`, Then 403; `support.tickets.view` routes succeed.
2. Given role `billing.invoices.*`, Then `billing.invoices.cancel` is allowed and `billing.refunds.create` is denied.
3. Given only one active staff holds `*`, When an admin tries to disable them or change their role, Then 422.
4. Given an invitation older than 7 days, When accepted, Then it fails and can be resent.
5. Given `staff_2fa_required=true` and staff without 2FA, When they log in with a correct password, Then every admin route redirects to 2FA enrolment.
6. Given a valid recovery code used once, When used again, Then login fails.
7. Given 5 failed logins for one email+IP within 15 min, Then the 6th attempt is rejected even with the right password until the lockout ends.
8. Given a password equal to one of the last 4, Then change fails.
9. Given a disabled staff with an active session/token, Then their next request is 401.
10. Given currencies USD (default, 2 dp) and JPY (0 dp, rate 150), When converting 10.00 USD, Then result is ¥1500 (minor 1500).
11. Given invoices exist, When changing the default currency, Then 422.
12. Given a setting is updated, Then the next read returns the new value in another worker and an activity entry shows old/new with secrets masked.
13. Given maintenance mode, When a guest opens the client area, Then 503 page; an admin and the API still work.
14. Given a search for an exact invoice number, Then the invoice is the first invoice result; staff without `billing.invoices.view` get no invoice group.
15. Given a ticket in a department the staff can't access, Then it never appears in search results.
16. Given impersonation, When the staff tries to change the client's password, Then 403; placing an order succeeds and is logged with impersonator_id.
17. Given impersonation started 61 minutes ago, Then the session is ended and redirected to admin.
18. Given closing a client with 2 unpaid invoices and 3 active services, Then invoices = cancelled, services = cancelled, no driver calls, `ClientClosed` emitted.
19. Given a client with transactions, When delete is attempted, Then 422 suggesting close.
20. Given merging clients with different currencies, Then 422; with the same currency, all related records move and the source becomes closed with `merged_into_client_id`.
21. Given a client older than 2 days with no active items, When the auto-inactive job runs, Then status = inactive; a client with an active domain stays active.

## 17. Out of scope for v1

SSO (SAML/OIDC) for staff, WebAuthn/Duo/YubiKey, multi-company/brands, per-staff permission overrides, IP-restricted admin area, admin calendar/notes widgets,
translation editor, theme settings UI, getting-started wizard, GDPR export/delete tooling beyond client export report, data-retention auto-deletion of clients.
