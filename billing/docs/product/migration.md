# Migration (WHMCS importer) — Product Spec (v1)

Status: draft for engineering · Owner: Migration workstream (new module `app/Modules/Migration`) · Shared-kernel changes need CTO approval (§12)
Clean-room: based only on the public WHMCS developer docs (External API reference) and the public docs of other products' importers.
We use no WHMCS source code, encoded files or licence internals, and no information about protections. The only data we read is data the
operator owns, through the documented API, using credentials the operator creates.

> **Amended by `client-import.md` (2026-10-20).** Client import builds the client slice of this pipeline first and adds a
> CSV connector. It changes this spec in eight points, listed in `client-import.md` §2 (A1–A8): the `imports` queue
> instead of `migration`, imported fields instead of notes for unmapped custom fields, paged `GetContacts`, marketing
> consent with evidence, importers setting `email_changed_at`, the database "gap reader" as an owner decision, a
> `source_kind` on runs, and `ImportOutcome::$changes`. Everything else here stands.

## 1. Purpose & scope

An operator moves **their own** business data from **their own** WHMCS installation into our platform. Competitors offer the same
interoperability: Blesta Import Manager, HostBill's WHMCS import, Paymenter `app:import-from-whmcs`, and Upmind's API-based importer. v1 aims for
a **repeatable, verifiable, side-effect-free** import (initial load, then delta syncs, then a final delta at cutover), so the operator can
switch over in a single maintenance window without double billing.

**In scope (v1):** currencies, client groups, clients, contacts, client logins (users), staff (optional), catalog (groups, products, prices,
configurable options, product custom fields, addons), servers (stubs), TLD pricing, promotions, orders (history), services and addons, domains,
tokenised pay-method references, invoices with items, transactions, credit history, quotes, support departments, tickets with replies, notes and
attachments, and announcements. Affiliates are a **COULD**.
**Never:** raw card or bank numbers, client or staff login passwords, CC encryption keys, EPP codes, or anything that requires decryption on
the WHMCS side.

## 2. Source connectors

### 2.1 WHMCS External API connector (v1, MUST)

- Transport: `POST {base_url}/includes/api.php` with `identifier`, `secret`, `action`, `responsetype=json` and the optional `accesskey`. The
  connector uses only API credentials. The legacy admin-username + MD5-password mode is **refused**. HTTPS is required, with TLS verification
  on. Plain `http://` is accepted only for `localhost` or a private address, and only when `--allow-insecure` is passed.
- Operator prerequisites, which the wizard shows: create an API role that grants **only** the actions listed below, generate the credential,
  add our egress IP under _Setup → General Settings → Security → API IP Access Restriction_ (or set an API access key), and allow the
  credential's admin user API access.
- **Read-only allowlist** (hard-coded; any other action throws `ForbiddenActionException` before any network call): `WhmcsDetails,
GetCurrencies, GetPaymentMethods, GetClientGroups, GetClients, GetClientsDetails, GetContacts, GetUsers, GetUserPermissions, GetAdminUsers,
GetProducts, GetServers, GetRegistrars, GetTLDPricing, GetPromotions, GetOrders, GetClientsProducts, GetClientsAddons, GetClientsDomains,
GetPayMethods, GetInvoices, GetInvoice, GetTransactions, GetCredits, GetQuotes, GetSupportDepartments, GetSupportStatuses, GetTickets,
GetTicket, GetTicketNotes, GetTicketAttachment, GetAnnouncements, GetAffiliates`. Write actions, `DecryptPassword`, `GetClientPassword` and
  registrar-touching actions such as `DomainGetNameservers` are **never** called.
- Pagination: `limitstart`/`limitnum` (default page size is 25 at the source; `migration.whmcs.page_size: 100`). Where `orderby` exists
  (`GetClients`, `GetInvoices`), use `orderby=id&order=asc`. After each pass, check that the number of unique ids matches `totalresults`.
  Offset paging over live data can skip rows; the id map plus delta runs make this harmless (§6). Endpoints with no documented paging
  (`GetTransactions`, `GetCredits`, `GetPayMethods`, `GetContacts` per client, `GetClientsAddons`) are always fetched **per client**.
- Response normalisation (`WhmcsResponseNormalizer`, unit-tested against fixtures): numbers sent as strings; nested lists of the form
  `{clients:{client:[…]}}` where a single item may arrive as an object; flattened keys such as `addons[addon][0][id]` (documented in some
  examples); `"0000-00-00"` and `"0000-00-00 00:00:00"` become `null`; `-1.00` prices mean "cycle disabled". Any `result=error` becomes a typed
  error. HTTP 403 or an IP rejection is reported with an actionable hint ("allow-list 203.0.113.7").
- Rate control: `migration.whmcs.max_rps: 5`, `max_concurrency: 2`, `timeout_seconds: 30`, enforced per source by a `RateLimiter` key. HTTP 429
  or 5xx responses and timeouts are retried with backoff `[10, 30, 60, 120, 300]` s.
- Known gaps in the classic API: there is no product-group list (groups are taken from `gid` and the `groupname` field of
  `GetClientsProducts`), no addon catalog (taken from the `addonid` and name of client addons), no config-option group structure, no
  knowledge-base API, and client custom-field definitions are absent (only id/value pairs). Each gap is covered by an operator **mapping**
  ("map to existing" or "create stub", §5.4). WHMCS 9+ also has a session-authenticated REST API v2 that exposes product groups, addons and
  configurable options for the storefront. It is a **v1.1 COULD** catalog supplement, not a dependency.

### 2.2 Direct database connector — decision: **out of scope for v1**

Reasons: (a) table and column knowledge would come from outside the public API docs, which weakens the clean-room position; (b) other importers
using this route ask for the install's encryption hash in order to decrypt stored card data, which is exactly the behaviour we refuse; (c) the
schema changes between versions. The `SourceConnector` interface (§12.3) keeps the option open for a later, clearly labelled, read-only "advanced"
connector limited to the API gaps listed above. A generic **JSONL file connector** (operator-supplied exports for KB articles or custom-field
definitions) is a **COULD**.

## 3. Pipeline stages (dependency order)

`ImportRegistry::ordered()` produces the order by topological sort of `dependsOn`. Every stage runs in phases:
**fetch → stage → validate → commit → finalize**.

| #   | Type key (owner)                                                                                                         | WHMCS actions                                                                      | Notes                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| 0   | — (Migration)                                                                                                            | WhmcsDetails, GetPaymentMethods, GetRegistrars, GetServers, GetSupportStatuses     | version, inventory, mapping candidates; no writes                          |
| 1   | `core.currency`                                                                                                          | GetCurrencies                                                                      | match by ISO code; create only when missing                                |
| 2   | `core.client_group`                                                                                                      | GetClientGroups                                                                    | discount %, `susptermexempt`, `separateinvoices`                           |
| 3   | `core.client`                                                                                                            | GetClients → GetClientsDetails                                                     | custom fields via mapping                                                  |
| 4   | `core.contact`                                                                                                           | GetContacts (per client)                                                           | email preferences                                                          |
| 5   | `core.user`                                                                                                              | GetUsers (8.1+), GetUserPermissions                                                | logins ↔ clients pivot, owner flag; pre-8.1: client email becomes the user |
| 6   | `core.staff` (optional)                                                                                                  | GetAdminUsers                                                                      | created **disabled + invited**; role via mapping                           |
| 7   | `catalog.product_group` → `catalog.product` → `catalog.config_option` → `catalog.product_custom_field` → `catalog.addon` | GetProducts, GetClientsProducts, GetClientsAddons                                  | map or stub (§5.4); prices per currency × cycle                            |
| 8   | `services.server` (optional)                                                                                             | GetServers                                                                         | stub without credentials                                                   |
| 9   | `domains.tld`                                                                                                            | GetTLDPricing (once per currency id)                                               | base price slab only                                                       |
| 10  | `orders.promotion`                                                                                                       | GetPromotions                                                                      | usage counts preserved                                                     |
| 11  | `orders.order`                                                                                                           | GetOrders                                                                          | historic snapshot; raw fraud output **dropped** (PII)                      |
| 12  | `services.service` → `services.service_addon`                                                                            | GetClientsProducts, GetClientsAddons                                               | §5.3, §7                                                                   |
| 13  | `domains.domain`                                                                                                         | GetClientsDomains                                                                  | no registrar calls; nameservers filled by our sync after cutover           |
| 14  | `billing.pay_method`                                                                                                     | GetPayMethods                                                                      | remote tokens only (§9)                                                    |
| 15  | `billing.invoice` → `billing.transaction` → `billing.credit_entry`                                                       | GetInvoices → GetInvoice, GetTransactions, GetCredits                              | finalize = reconciliation (§5.2)                                           |
| 16  | `billing.quote`                                                                                                          | GetQuotes                                                                          |                                                                            |
| 17  | `support.department` → `support.ticket`                                                                                  | GetSupportDepartments, GetTickets → GetTicket, GetTicketNotes, GetTicketAttachment | messages, notes and attachments are children of the ticket                 |
| 18  | `support.announcement`                                                                                                   | GetAnnouncements                                                                   | parent rows only (no translations in v1)                                   |
| 19  | `affiliates.affiliate` (COULD)                                                                                           | GetAffiliates                                                                      | accounts + balances; no referral history                                   |

KB articles have no documented API and are **out of scope** for the API connector (possible later via the JSONL connector).

## 4. Data model (Migration module, prefix proposal `98xxxx`)

- `migration_sources: id, driver ('whmcs'), name, base_url, credentials (encrypted json: identifier, secret, access_key), source_timezone,
options (json: page_size, max_rps, flags), whmcs_version, status (draft|connected|importing|cut_over|archived), cutover_at, purged_at, timestamps`
- `migration_id_map: id, migration_source_id (fk), source_type (e.g. 'whmcs.invoice'), source_id (string; composite keys allowed, e.g.
'com:USD'), target_type ('billing.invoice'), target_id (bigint), checksum (char 64, sha256 of the canonical normalised record), first_run_id,
last_run_id, synced_at, target_fingerprint (char 64, hash of the target's `updated_at` + version at sync time), source_state
(present|missing), timestamps`. Unique `(migration_source_id, source_type, source_id)`; index `(target_type, target_id)`. **MUST**
- `migration_records` (staging): `migration_source_id, source_type, source_id, payload (encrypted json, raw), normalized (encrypted json),
checksum, fetched_at, validation (ok|warning|error)`; unique on the source key. Purged after `migration.staging_retention_days: 30` from cutover.
- `migration_runs: id, migration_source_id, mode (dry_run|commit|delta|final_delta), status (queued|running|paused|failed|completed|cancelled),
only (json types), started_by, started_at, finished_at, report (json)`
- `migration_run_steps` (checkpoints): `run_id, type, phase, cursor (json, e.g. {limitstart, client_id}), source_total, fetched, staged, committed,
unchanged, skipped, failed, status, last_error, updated_at`; unique `(run_id, type, phase)`.
- `migration_issues: run_id, severity (info|warning|error|blocking), code, source_type, source_id, message, context (json, redacted)`
- `migration_value_maps: migration_source_id, kind (gateway|product|product_group|addon|server|registrar|department|staff_role|module|
status.<entity>|cycle|invoice_item_type|config_option_type|client_custom_field|product_custom_field), source_value, target_value, auto (bool)`;
  unique `(source, kind, source_value)`. Exportable/importable as JSON.
- Target modules add (in their own migrations) `imported_at` (timestamp, nullable) on aggregate roots (`clients, services, service_addons,
domains, invoices, transactions, orders, tickets, quotes`), and `import_hold` (bool, default false) on `services, service_addons, domains` (§7).

## 5. Mapping rules

### 5.1 Money, currency, dates, cycles

- **Minor units:** parse the decimal _string_ with `Money::fromDecimalString($value, $decimals)`, using no floats:
  `minor = round_half_up(value × 10^decimals)`. Decimals come from the ISO-4217 code of the target currency (e.g. JPY = 0). Fractional
  remainders in zero-decimal currencies raise `W_CURRENCY_FRACTION` and are rounded. Exchange `rate` maps to `exchange_rate` (both mean "units
  per 1 default"); the source currency with rate 1 must equal our default currency, otherwise `BLOCKING_DEFAULT_CURRENCY_MISMATCH`.
- Records whose currency is not shown (services, domains, credits, transactions with `currency: 0`) take the **client's currency**.
- **Dates:** date-only fields (`regdate, nextduedate, nextinvoicedate, expirydate, duedate, date`) are copied as calendar dates with no timezone
  shift. Datetimes (`datepaid`, ticket and reply `date`, `created_at`) are read in `source_timezone` (the operator confirms it in the wizard;
  default `core.timezone`) and stored as UTC. Display formats (`17/05/2019 10:01`, expiry `02/25`) use per-field parsers.
- **Cycles:** `Free Account→free`, `One Time→onetime`, `Monthly→monthly`, `Quarterly→quarterly`, `Semi-Annually→semiannually`,
  `Annually→annually`, `Biennially→biennially`, `Triennially→triennially`. Product pricing keys `monthly…triennially` and setup keys
  `m/q/s/a/b/tsetupfee` map to `prices(cycle, amount, setup_fee)`; `-1` means no row. Unknown values go to the `cycle` value map; if unmapped
  → `error`.
- **Anchor day:** `billing_anchor_day = regdate.day` if `nextduedate.day == min(regdate.day, daysInMonth(nextduedate))`, else `nextduedate.day`.

### 5.2 Entity → our fields (highlights; anything unlisted is kept only in staging)

| WHMCS                                                                                                                          | Ours                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| client firstname/lastname/companyname/email/address1-2/city/state/postcode/countrycode/phonenumber/tax_id                      | `clients.*` (country ISO-2); `datecreated` → `created_at`                                                                                                                                                                                                                               |
| client currency, groupid, language, notes                                                                                      | `currency` (code), `client_group_id` (map), `language`, `admin_notes`                                                                                                                                                                                                                   |
| taxexempt, latefeeoveride, overideduenotices, separateinvoices, email_preferences                                              | `tax_exempt`, `apply_late_fees = !latefeeoveride`, `send_overdue_reminders = !overideduenotices`, `separate_invoices`, `email_preferences`                                                                                                                                              |
| client customfields `[{id,value}]`                                                                                             | mapped field → value; unmapped → appended under an "Imported fields" block in admin notes (`W_UNMAPPED_CUSTOM_FIELD`)                                                                                                                                                                   |
| client `credit` (GetClientsDetails)                                                                                            | reconciliation target for `credit_ledger` (below)                                                                                                                                                                                                                                       |
| user `clients[{id,isOwner}]` + permissions                                                                                     | `client_user` pivot (`is_owner`, areas billing/services/domains/support/profile/contacts via a fixed permission map)                                                                                                                                                                    |
| product type hostingaccount/reselleraccount/server/other                                                                       | `shared_hosting/reseller_hosting/server/other`; `paytype` → `payment_type`; `module` → `provisioning_driver` via `module` map (default `none`, `driver_config` empty, `W_DRIVER_UNMAPPED`)                                                                                              |
| service domain/dedicatedip/assignedips/username/notes/qty                                                                      | `domain, dedicated_ip, assigned_ips (split lines), username, admin_notes, quantity`; `password` only with secrets opt-in (§9)                                                                                                                                                           |
| service firstpaymentamount/recurringamount/paymentmethod/promoid/subscriptionid                                                | minor amounts; `payment_gateway` (gateway map); `promotion_id`; `driver_data.legacy_subscription_id`                                                                                                                                                                                    |
| domain domainname/regtype/registrar/regperiod/donotrenew/idprotection/dnsmanagement/emailforwarding                            | `name` (lowercase, punycode), `sld/tld` (longest matching `tlds.extension`), `type`, `registrar_key` (map), `period_years`, `auto_renew = !donotrenew`, flags                                                                                                                           |
| invoice invoicenum or id, date, duedate, datepaid, subtotal, credit, tax, tax2, taxrate, taxrate2, total, paymentmethod, notes | `number` (= invoicenum, or id if empty; `--number-source`), `issue_date, due_date, paid_at, subtotal, credit_applied, tax1_amount, tax2_amount, tax1_rate, tax2_rate, total, payment_gateway, notes`; tax names, compound and inclusive flags come from operator settings in the wizard |
| invoice item type/relid/description/amount/taxed                                                                               | `invoice_items(type via invoice_item_type map, billable_id via id map, description, quantity 1, unit_amount = amount, taxable)`; `tax_amount = null` (invoice-level tax is authoritative)                                                                                               |
| transaction gateway/date/description/amountin/amountout/fees/rate/transid/invoiceid/refundid                                   | `transactions(type: payment, or refund when amountout > 0, or manual_income/manual_expense when there is no invoice; gateway_txn_id = transid, or `whmcs:{id}` when empty; parent via refundid)`                                                                                        |
| credit log date/description/amount                                                                                             | `credit_ledger` rows in date order, `reason=manual` (`applied_to_invoice` when the description references an invoice), recomputed `balance_after`                                                                                                                                       |
| ticket tid/c/deptid/userid/contactid/subject/priority/flag/service `Sx`/`Dx`/cc                                                | `number = tid`, new `access_key`, `department_id`, `client_id`, `contact_id`, `subject`, `priority` lowercase, `assigned_to` (staff map), `service_id`/`domain_id`, `cc` (json)                                                                                                         |
| reply/note message/date/admin/requestor_type/rating/attachments                                                                | `ticket_messages(type reply                                                                                                                                                                                                                                                             | note, author_type staff when admin is set, else client or guest, body = message, rating)`and`ticket_attachments`(files fetched with GetTicketAttachment; skipped when`attachments_removed`) |

- **Invoices are imported as recorded:** lines, tax amounts, totals and numbers are never recalculated, and `Invoicer::issue` is **not** used.
  `kind=add_funds` when the only line maps to `add_funds`. Mass-payment invoices that combine other invoices are imported as historic
  `multi_pay_wrapper` documents (`W_MASSPAY_INVOICE`; Reports must exclude wrappers from invoiced totals; open question for the Billing and
  Reports owners). `client_snapshot` is built from the client **at import time**, because the API exposes no historic address (documented
  limitation).
- **Reconciliation (Billing `finalize`):** for each invoice, `amount_paid = Σ amountin − Σ amountout` of the linked imported transactions. If
  our derived balance differs from the source `balance`, a `manual` adjustment `"Migration reconciliation"` is added and `W_BALANCE_RECONCILED`
  is logged. For each client, the final `credit_ledger` balance must equal the source `credit`; otherwise one reconciliation ledger row is added
  and a warning is logged. After import, Billing moves its number sequence to `max(imported numeric number) + increment`.

### 5.3 Status mapping (unknown or custom values go to the `status.<entity>` map; unmapped → `error`)

| Entity          | WHMCS → ours                                                                                                                                                                                                                                    |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Client          | Active→active · Inactive→inactive · Closed→closed                                                                                                                                                                                               |
| Service / addon | Pending→pending · Active→active · Suspended→suspended (`suspension_reason=staff:<suspensionreason>`) · Terminated→terminated · Cancelled→cancelled · Fraud→fraud · Completed→completed                                                          |
| Domain          | Pending→pending · Pending Registration→pending_registration · Pending Transfer→pending_transfer · Active→active · Grace→grace · Redemption→redemption · Expired→expired · Transferred Away→transferred_away · Cancelled→cancelled · Fraud→fraud |
| Invoice         | Draft→draft (number null, source number kept in `proforma_number`) · Unpaid→unpaid · Payment Pending→payment_pending · Paid→paid · Cancelled→cancelled · Refunded→refunded · Collections→collections ("Overdue" is derived, never stored)       |
| Order           | Pending→pending · Active→active · Cancelled→cancelled · Fraud→fraud                                                                                                                                                                             |
| Quote stage     | Draft→draft · Delivered→delivered · On Hold→on_hold · Accepted→accepted · Lost→declined · Dead→expired                                                                                                                                          |
| Ticket          | Open→open · Answered→answered · Customer-Reply→customer_reply · On Hold→on_hold · In Progress→in_progress · Closed→closed · custom → create a custom `ticket_statuses` key (slug) or map                                                        |
| Promotion type  | Percentage→percent · Fixed Amount→fixed · Price Override→price_override · Free Setup→free_setup                                                                                                                                                 |

### 5.4 Operator mappings ("Mappings required" step; a blocking dry-run issue lists each missing one)

These maps cover gateways (WHMCS module names → our gateway keys; required for every value in use), product groups and products (map to an
existing product, or create a stub: services that reference a product missing from `GetProducts` create a **retired** stub named from
`GetClientsProducts.name/groupname`), addons (the same approach, using client-addon names; stub addons have no prices and existing addon
services keep their stored amounts), provisioning modules and registrars (→ driver keys or `none`), servers, departments (support departments
need a unique email, which the operator enters), staff roles, invoice item types (seed suggestions: Hosting→service, Addon→service_addon,
Domain*→domain, Setup→service_setup, LateFee→late_fee, AddFunds→add_funds, Promo*→promotion, otherwise manual), config-option type codes,
and custom fields. The dry-run report lists every **distinct observed value** next to its proposed mapping.

## 6. Idempotency, delta sync and conflicts

- Every commit looks up `migration_id_map`. If the row is missing, the record is created. If the checksum is equal, the record is
  `unchanged` (no write). If the checksum differs, the importer's `upsert` runs with the existing target id. Re-running any run is safe. **MUST**
- Delta (`mode=delta`, nightly until cutover): list endpoints are re-scanned, and detail calls are made only when the list row's checksum (or
  `updated_at`/`lastreply`) changed. Example: `GetInvoice` is called only for invoices whose `GetInvoices` row changed. Records that have
  disappeared at the source get `source_state=missing` and are **reported, never deleted**.
- Before cutover, **source wins**. If `target_fingerprint` shows a local edit since the last sync, the update still applies, but
  `W_LOCAL_EDIT_OVERWRITTEN` records both values. Imported invoices remain updatable **only** by importers and **only** before cutover, which is
  an explicit exception to invoice immutability. After cutover, delta and commit are refused (`source.status=cut_over`).

## 7. Automation safety (MUST — imported data must never act like new business)

| Risk                                                    | Mechanism                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Welcome, order or invoice emails; affiliate commissions | Importers write with `saveQuietly()` and dispatch **no** `App\Events\{Core,Billing,Services,Domains,Orders,Support,Affiliates}\*` events. Only `App\Events\Migration\*` are dispatched, and Notifications ignores those apart from staff summaries. `activated_at` is set for services that are or were active, so "first activation" can never fire later.                                             |
| Provisioning or registrar calls                         | Importers never resolve driver contracts (an arch test enforces this). `module_queue` items are not imported. Domain sync starts only after cutover (`migration.enable_domain_sync_after_cutover: true`).                                                                                                                                                                                               |
| Platform automation before go-live                      | The first commit sets `automation.paused=true` (reason `migration`). `automation:run` refuses to run while paused. Staff clear the flag after `cutover`, once they have reviewed the impact report (runbook step 3(g)).                                                                                                                                                                                 |
| Double renewal invoicing                                | `next_invoice_date = advance(next_due_date)` when an imported unpaid or draft invoice has a line for the billable with `due_date == next_due_date`. That line gets `period_start = next_due_date`, so `hasInvoiceForPeriod` blocks it. Otherwise `next_invoice_date = next_due_date`. Addons use the source `nextinvoicedate`.                                                                          |
| Catch-up invoices for long-stale items                  | Active or suspended items with `next_due_date < cutover − migration.stale_due_threshold_days (60)` and no open invoice get `import_hold=true` and a `staff_tasks` entry. Renewal, suspension and termination tasks skip held items until staff release them.                                                                                                                                            |
| Back-dated late fees, reminders, captures, auto-cancel  | Billing `cutover()` handles imported invoices with `due_date < cutover`: it sets `late_fee_applied_at = cutover_at` and seeds `reminder_log` with every rule whose trigger date is earlier than cutover. With `migration.capture_overdue_after_cutover: false`, it blocks auto-capture by stamping `last_capture_attempt_at`. A pre-go-live impact report counts invoices that auto-cancel would touch. |
| Mass suspension on day 1                                | `overideautosuspend`/`overidesuspenduntil` → `suspend_exempt_until` (an indefinite override becomes `9999-12-31`, `W_INDEFINITE_EXEMPTION`). Services with overdue imported invoices get `suspend_exempt_until = max(current, cutover + migration.post_cutover_suspension_grace_days (3))`.                                                                                                             |
| Promotions discounting twice                            | The imported `recurring_amount` is the source's current recurring price, and `promotion_cycles_remaining = 0`. Limited-recurrence promotions that WHMCS would have ended later are reported (`W_PROMO_RECURFOR_LOST`).                                                                                                                                                                                  |
| Domain expiry reminders                                 | Domains `cutover()` seeds `domain_reminders` for offsets already in the past.                                                                                                                                                                                                                                                                                                                           |
| Gateway subscriptions still billing                     | Services and domains that have a `subscriptionid` are counted in the report. The runbook requires a decision before cutover: cancel the subscription at the gateway, or keep WHMCS receiving its callbacks.                                                                                                                                                                                             |

## 8. Dry run & validation report

`--dry-run` fetches, stages and validates, and writes nothing to module tables (only `migration_*` tables). The Migration module checks
references itself: each ref must be in staging or already mapped, otherwise `W_ORPHAN_REF`. The report (stored JSON plus an HTML view, with
CSV export) contains:

1. Per type: `source_total` (totalresults), fetched, valid, warnings, errors, plus create/update/unchanged estimates from the id map.
2. **Per-currency reconciliation**: invoice count and Σ total by status, Σ unpaid balance, Σ transactions in/out/fees, Σ client credit, and
   Σ recurring amount of active services and domains, normalised to monthly. After a commit run the same table is recomputed from **our**
   tables; any difference above 0 minor units is `blocking` for cutover.
3. Unmapped values (§5.4) with counts; missing mappings are blocking.
4. Warnings by code, with samples (max 50 per code); security counts (local cards skipped, tokens importable, service passwords present but
   not imported); first-run automation impact (§7).
   Exit code: 0 when clean, 1 when there are warnings only, 2 when anything is blocking.

## 9. Security & credentials

- Source credentials are stored in `encrypted` casts (APP_KEY), never logged, and never passed on the command line: the CLI prompts, or
  reads `WHMCS_API_IDENTIFIER`/`WHMCS_API_SECRET`. The HTTP log middleware redacts `identifier, secret, accesskey, password`.
  `import:whmcs purge` deletes the credentials and staging data. Post-cutover runbook: revoke the WHMCS credential and allow-list entry.
- **Passwords:** none are imported. Users are created with a random unusable hash. After cutover, `invite-users` sends "set your password"
  links through a dedicated password broker (`migration.invite_expiry_days: 14`, throttle `migration.invite_per_hour: 500`). Staff re-enrol in 2FA.
  Note: the public docs show `GetClientPassword` returning a bcrypt-format hash, but that action is deprecated since 8.0 and does not cover
  the 8.x user model. Hash import is therefore **out of scope** (it would also widen our attack surface).
- **Pay methods:** only `RemoteCreditCard`/`RemoteBankAccount` entries with a `remote_token` are imported. This requires the gateway map to
  point at a driver with the `tokenize` capability, **and** the operator must confirm "same merchant account". Brand, last4 and expiry are
  copied, and the token is encrypted. Locally stored cards and bank accounts are **never** fetched or decrypted; their count is reported and
  clients re-add them.
- **Service credentials (optional):** `--with-service-passwords` requires the `migration.secrets.import` permission and a typed confirmation. It
  stores `GetClientsProducts.password` in `services.password` (encrypted) and skips invalid UTF-8 (`W_INVALID_SECRET`). Server credentials are
  not available through the API; the operator enters them.
- **Permissions** (group _Setup_): `migration.sources.manage`, `migration.mappings.manage`, `migration.runs.view`, `migration.runs.execute`,
  `migration.cutover.execute`, `migration.secrets.import`, `migration.data.purge`. Every action writes `activity_log` events
  (`migration.source_connected`, `migration.run_started`/`completed`, `migration.cutover`, `migration.secrets_imported`, `migration.purged`).
- SSRF guard: `base_url` must resolve to a public IP unless `--allow-private` is passed (for self-hosted LAN sources). Redirects are not followed.

## 10. Interfaces & cutover runbook

**CLI** (primary). Note that this sits in Laravel's `migrate:*` namespace; `whmcs:import` is registered as an alias.
`import:whmcs connect --url= [--source-timezone=] [--allow-insecure] [--allow-private]` · `plan` (inventory + required mappings) ·
`map --export=|--import=file.json` · `run --dry-run|--commit [--only=types] [--resume]` · `delta` · `report [--run=] [--format=json|csv]` ·
`cutover --at=` · `invite-users [--dry-run]` · `purge`. **Admin wizard** (Setup → Migration): Connect → Inventory → Mappings → Dry-run report →
Commit → Delta schedule → Cutover checklist (each runbook step must be ticked, with timestamp and actor).

**Runbook:**

1. **T−14 d:** connect, dry run, resolve mappings and data issues, commit the initial import. Staff review it with automation paused, and
   nightly `delta` runs are scheduled.
2. **T−1 d:** announce the maintenance window and lower the DNS TTL. Configure gateways and registrars on our side in test mode. Decide what
   happens to gateway subscriptions.
3. **T0:** (a) put WHMCS in maintenance mode and **disable the WHMCS cron/automation** (no invoice generation, captures or suspensions);
   (b) run `run --commit` in `final_delta` mode; (c) the reconciliation report must have no blocking items; (d) run `cutover`, which applies
   the §7 baselines and sets `source.status=cut_over` (automation **stays paused**); (e) switch gateway webhook/callback URLs and
   registrar/API allow-lists, and enter server credentials; (f) switch DNS for the client area (redirecting old WHMCS URLs is a COULD);
   (g) review the first-run impact report, then unpause automation (`automation.paused=false`); (h) run `invite-users`.
4. **T+1..90 d:** keep WHMCS read-only for reference, revoke the API credential, and run `purge`.
5. **Rollback:** possible until step 3(e) (re-enable the WHMCS cron, leave maintenance mode). After that it is a manual process.

## 11. Errors, resumability, performance

- Jobs run on queue `migration` (so they never starve provisioning). `FetchPageJob` (one page or one client) and `CommitBatchJob`
  (`migration.batch_size: 100`) run in one `DB::transaction` per batch, covering module writes and id-map writes together. Settings:
  `$tries = 5`, backoff as in §2.1. Checkpoints in `migration_run_steps.cursor` are updated after each batch; `--resume` continues from the last
  checkpoint.
- A per-record error (validation or importer exception) creates a `migration_issues` row and the run continues. Circuit breaker: more than 2%
  failed records in a stage, or 20 consecutive HTTP failures, sets the run to `paused` and notifies staff (`MigrationRunPaused`). Runs on the
  same source are serialised with a lock.
- Targets for the reference dataset (5k clients, 15k services, 50k invoices, 10k tickets without attachments, `max_rps=10`): initial import
  ≤ 4 h, of which commit ≥ 100 records/s per worker on MySQL/PostgreSQL. A no-change delta takes ≤ 30 min, and the final delta during the
  freeze ≤ 60 min. Peak memory is ≤ 256 MB per job. Attachments are streamed to the `objects` disk with a size limit equal to Support's limit.

## 12. Cross-module contracts (shared-kernel proposal for the CTO)

The Migration module never imports another module's classes. It translates WHMCS data into **our vocabulary** (our enums, minor units, ISO
dates), and each owning module writes its own tables through an importer it registers. Target modules know nothing about WHMCS, so a future
Blesta or HostBill connector can reuse every importer. Importers live in `app/Modules/<Module>/Import/*Importer.php` and are registered in
`bootModule()`, for example:
`app(ImportRegistry::class)->register('billing.invoice', InvoiceImporter::class, dependsOn: ['core.client', 'services.service', 'domains.domain']);`
The registry is implemented by Core (in memory, like `MergeFieldRegistry`). Each module's spec documents the attribute schema for its types.

```php
namespace App\Contracts\Migration;

interface RecordImporter
{
    /** @return array<string, mixed> Laravel validation rules applied to ImportRecord::$attributes (and children.*) */
    public function rules(): array;

    /** Domain validation beyond rules (uniqueness, allowed enum values). Must not write. In dry runs $record->links may be null. @return list<ImportIssue> */
    public function validate(ImportRecord $record, ImportContext $context): array;

    /** Create (when $existingTargetId is null) or update the target aggregate. Idempotent; uses saveQuietly();
     *  dispatches no domain events, sends no mail, calls no drivers, never recalculates money. Runs inside the caller's transaction. */
    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome;

    /** Once per run after the type is committed: sequences, caches, reconciliation. @return list<ImportIssue> */
    public function finalize(ImportContext $context): array;

    /** At cutover: apply automation baselines to imported records (§7). Idempotent. Returns affected count. */
    public function cutover(ImportContext $context): int;
}

interface ImportRegistry
{
    /** @param class-string<RecordImporter> $importer @param list<string> $dependsOn */
    public function register(string $type, string $importer, array $dependsOn = []): void;
    public function has(string $type): bool;
    public function importer(string $type): RecordImporter;
    /** @return list<string> dependency order; throws on cycles or unknown dependencies */
    public function ordered(): array;
}

interface ImportIdResolver
{
    public function targetId(string $type, string $sourceId): ?int;
}
```

```php
namespace App\Data\Migration;   // all final readonly

final readonly class ImportRecord {
    /** @param array<string, mixed> $attributes @param array<string, int|null> $links e.g. ['client_id' => 42]
     *  @param array<string, list<array<string, mixed>>> $children e.g. ['items' => [...], 'messages' => [...]]
     *  @param list<array{path: string, name: string, mime: string, size: int, sha256: string}> $files staged on the objects disk */
    public function __construct(public string $type, public string $sourceSystem, public string $sourceId, public array $attributes,
        public array $links = [], public array $children = [], public array $files = [], public string $checksum = '') {}
}
final readonly class ImportContext {
    /** @param array<string, scalar|null> $options */
    public function __construct(public int $runId, public int $sourceConnectionId, public string $sourceSystem, public bool $dryRun,
        public CarbonImmutable $importedAt, public ?CarbonImmutable $cutoverAt, public ImportIdResolver $ids, public array $options = []) {}
}
final readonly class ImportOutcome {
    /** @param list<ImportIssue> $issues */
    public function __construct(public ?int $targetId, public ImportAction $action, public array $issues = []) {}
}
final readonly class ImportIssue {
    /** @param array<string, scalar|null> $context */
    public function __construct(public ImportSeverity $severity, public string $code, public string $message, public array $context = []) {}
}
// app/Enums: ImportAction (Created|Updated|Unchanged|Skipped), ImportSeverity (Info|Warning|Error|Blocking)
// app/Events/Migration: MigrationRunCompleted(runId, mode, status), MigrationRunPaused(runId, reason), MigrationCutoverCompleted(sourceId, cutoverAt)
```

Module-internal (not kernel): `App\Modules\Migration\Contracts\SourceConnector { key(): string; test(): array; fetch(string $sourceType, array $cursor): iterable }`
plus a `SourceMapper` per source that converts raw payloads into `ImportRecord`s.

Arch tests to add: `App\Modules\*\Import` must not use `Illuminate\Support\Facades\{Event,Mail,Notification}`, any `App\Events` namespace other
than `App\Events\Migration`, or driver contracts. `App\Modules\Migration` may use only kernel namespaces.

## 13. Acceptance criteria (Pest; `Http::fake()` with WHMCS JSON fixtures under `tests/Fixtures/Whmcs/`)

1. Given valid credentials, when `connect` runs, then `WhmcsDetails` is called with identifier/secret in the POST body, the source becomes `connected`, and the secret appears in no log or exception.
2. Given a connector, when code requests `AddClient` or `DecryptPassword`, then `ForbiddenActionException` is thrown and `Http::assertNothingSent()` passes.
3. Given an HTTP 403 "Invalid IP", when connecting, then the error names the egress IP to allow-list.
4. Given 230 clients and page size 100, when clients are fetched, then 3 pages are requested (`limitstart` 0/100/200) and 230 staged rows equal `totalresults`.
5. Given fixtures using flattened keys (`addons[addon][0][id]`) and a single object in place of a list, when normalised, then the output matches the nested equivalent.
6. Given a dry run, then no rows exist in clients/services/invoices tables and the report shows per-type counts and per-currency totals.
7. Given price `"12.95"` in USD and `"1000.00"` in JPY, then the stored amounts are 1295 and 1000; given `"1000.50"` JPY, then 1001 and `W_CURRENCY_FRACTION`.
8. Given a committed import, when the same commit runs again with unchanged fixtures, then every outcome is `unchanged` and no `updated_at` changes.
9. Given an invoice that became Paid at the source, when `delta` runs, then only that invoice is re-fetched with `GetInvoice`, its status becomes `paid`, and its transaction is added.
10. Given a full fixture import under `Event::fake()`, `Mail::fake()` and `Queue::fake()`, then no non-Migration domain events are dispatched, no mail is sent, and no provisioning or registrar job is queued.
11. Given a Monthly service with regdate 2026-01-31, nextduedate 2026-10-31 and an unpaid invoice due 2026-10-31 with a Hosting line for it, then `next_invoice_date = 2026-11-30`, `billing_anchor_day = 31`, and after cutover `services.renewals` issues no invoice for 2026-10-31.
12. Given an imported invoice due 30 days before cutover, when `automation:run` executes on the cutover date, then no late fee is applied and no reminder is sent; given one due 5 days after cutover, the normal reminder is sent.
13. Given an active service with nextduedate 200 days in the past and no open invoice, then `import_hold=true`, a staff task exists, and renewal and suspension tasks skip it.
14. Given WHMCS tax 2.00 at a 20% rate on a 10.00 subtotal, when imported, then `tax1_amount=200` and `total=1200`, with no recalculation even if our tax rules differ.
15. Given transactions summing less than the invoice's paid total, then Billing finalize adds one reconciliation adjustment and `W_BALANCE_RECONCILED`; per-currency totals match afterwards.
16. Given client credit 15.00 and a credit log that sums to 10.00, then the ledger ends at 1500 with one reconciliation row.
17. Given pay methods (RemoteCreditCard with a token, local CreditCard), then only the remote one is imported (token encrypted, last4 kept) and the report counts one local card skipped.
18. Given `--with-service-passwords` without `migration.secrets.import`, then the command is refused; with it, the password is stored encrypted and an audit entry is written.
19. Given a ticket with 2 replies, 1 note and 1 attachment, then the messages are imported in order, the note has `type=note`, the attachment's sha256 matches the fixture, and `ticket.number = tid`.
20. Given a missing gateway mapping, then the dry run exits with code 2 and `commit` refuses to start.
21. Given a run that fails on page 3 of invoices, when `run --resume` executes, then fetching starts at `limitstart=200` and no duplicates are created.
22. Given cutover completed, when `delta` or `commit` runs, then it is refused; `invite-users` sends one invite per user, capped by the hourly throttle.
23. Staff without `migration.runs.execute` receive 403 on wizard actions, and every migration action writes an `activity_log` entry.

## 14. Out of scope (v1)

The direct DB connector; card or bank numbers and local stored pay methods; client and staff password hashes; EPP codes and live registrar
lookups; KB articles (no documented API); email templates, the email log (`GetEmails`), the activity log, projects, to-dos, the module queue;
announcement translations; client-group domain price slabs; affiliate referral history; WHMCS customisations and third-party module data;
two-way sync after cutover; automatic redirects of old WHMCS URLs (COULD); other source products (the contracts support them later).

## 15. Sources

- WHMCS API: overview https://developers.whmcs.com/api/ · getting started https://developers.whmcs.com/api/getting-started · authentication
  https://developers.whmcs.com/api/authentication/ · access control https://developers.whmcs.com/api/access-control/ · response types
  https://developers.whmcs.com/api/response-types · API index https://developers.whmcs.com/api/api-index/
- WHMCS API reference (`https://developers.whmcs.com/api-reference/<action>/`): getclients, getclientsdetails, getcontacts, getusers,
  getclientgroups, getadminusers, getproducts, getclientsproducts, getclientsaddons, getclientsdomains, gettldpricing, getpromotions, getorders,
  getinvoices, getinvoice, gettransactions, getcredits, getpaymethods, getpaymentmethods, getcurrencies, getquotes, getsupportdepartments,
  gettickets, getticket, getticketnotes, getticketattachment, getannouncements, getservers, getmodulequeue, getclientpassword (deprecated 8.0)
- WHMCS REST API v2 (9.0+): https://developers.whmcs.com/rest-api/introduction · https://developers.whmcs.com/rest-api/authentication
- WHMCS docs (8.13) — domain statuses: https://whmcsdocs.devops.cpanel.net/8-13/domains/domain-statuses · order statuses:
  https://whmcsdocs.devops.cpanel.net/8-13/orders/order-statuses
- Blesta Import Manager: https://docs.blesta.com/integrations/plugins/import-manager (DB-based; asks for the CC encryption hash; fresh
  install only; CLI most reliable)
- HostBill: https://hostbill.atlassian.net/wiki/x/lwDtgw (DB-based; tool on request; "no guarantee" with third-party modules)
- Paymenter: https://paymenter.org/docs/guides/whmcs-importer (dump import into a temporary DB; wipes target; artisan command)
- Upmind: https://docs.upmind.com/setup-and-configuration/importing-data/importing-from-whmcs.md (API-based; IP allow-list; mapping step;
  staged preview then commit; does not store hosting passwords)
- FOSSBilling: no importer as of the June 2025 forum answer — https://forum.fossbilling.org/thread-329.html
