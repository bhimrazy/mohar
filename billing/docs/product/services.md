# Services — Product Spec (v1)

Status: draft for engineering · Owner: Services workstream · Refines FEATURE_MAP §4
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

Services owns the client's _instance_ of a product (a "service") and its addons through their whole life: creation (from Orders via
`ServiceRegistry`), provisioning through drivers, renewal invoicing (through `Invoicer`), due-date bookkeeping, overdue suspension/termination,
upgrades/downgrades, cancellation requests, servers & server groups, usage snapshots and the module command log/queue.
Domains have their own lifecycle (Domains module) but share the renewal pipeline conventions defined here.

## 2. Entities & key fields

### services

`id, client_id, product_id, order_id, status, cycle (BillingCycle), currency, quantity, domain, server_id, username, password (encrypted),
dedicated_ip, assigned_ips (json), first_payment_amount, recurring_amount (net of client-group discount, gross of promo), promotion_id,
promotion_cycles_remaining (null = unlimited), registration_date, billing_anchor_day (1–31), next_due_date, next_invoice_date,
renewals_invoiced (int), activated_at, suspended_at, suspension_reason (overdue|addon_overdue|staff:<text>), suspend_exempt_until (date|null),
do_not_renew (bool), terminate_at_period_end (bool), termination_date, payment_gateway, config_options (json {option_id: value_id|qty} + snapshot),
custom_fields (json {field_id: value}, passwords encrypted), driver_data (json, driver-owned), admin_notes, timestamps, soft deletes`

- Dates are **calendar dates** (no time) interpreted in the company timezone `core.timezone`. **MUST**
- `next_due_date`: start of the first **unpaid** period ("paid through"). `next_invoice_date`: start of the first **not yet invoiced** period ("invoiced through"). Invariant: `next_invoice_date ≥ next_due_date`. **MUST**
- Free / one-time services: `next_due_date = next_invoice_date = null`. **MUST**

### service_addons

Same lifecycle columns as services (status, cycle, amounts, dates, anchor day, suspension fields, driver_data) + `service_id, addon_id (nullable for custom), name (custom)`. **MUST**

### servers / server_groups

- `servers: id, name, hostname, ip_address, assigned_ips (json), driver, username, password (enc), api_token (enc), access_hash (enc), secure (bool), port (nullable → driver default), max_accounts (0 = unlimited), is_active, nameservers (json ≤5), monthly_cost, datacenter, status_url, timestamps` **MUST**
- `server_groups: id, name, fill_type (least_full|fill_sequentially)`; pivot `server_group_server (sort_order)`. **MUST**

### service_changes (upgrades/downgrades)

`id, service_id | service_addon_id, kind (product|config_options|cycle), from_snapshot (json), to_snapshot (json), credit_amount, charge_amount,
amount_due, promotion_id, invoice_id, order_id, status (pending_payment|applied|apply_failed|cancelled|expired), expires_at, applied_at` **MUST**

### cancellation_requests

`id, service_id, type (immediate|end_of_period), reason, requested_by_user_id | staff_id, status (open|processed|voided|failed), process_after (date), processed_at, timestamps` **MUST**

### module_commands (log) & module_queue

- `module_commands: id, service_id | service_addon_id | server_id, driver, command, initiator (system|staff:<id>|client:<id>), attempt, success, error, request (redacted json), response (redacted json), duration_ms, created_at` **MUST**
- `module_queue: id, subject_type, subject_id, command, args (json), last_error, attempts, status (open|resolved|dismissed), resolved_by, timestamps` **MUST**

### service_usage_snapshots

`service_id, disk_used_mb, disk_limit_mb, bw_used_mb, bw_limit_mb, captured_at`. **SHOULD**

## 3. Status machine (services and addons)

```mermaid
stateDiagram-v2
  [*] --> pending: ServiceRegistry::createPending
  pending --> active: create succeeds (or manual activate)
  pending --> cancelled: order cancelled
  pending --> fraud: order marked fraud
  active --> suspended: overdue / staff / addon_overdue
  suspended --> active: unsuspend (payment or staff)
  active --> terminated: overdue termination / staff terminate / fixed term
  suspended --> terminated: overdue termination / staff terminate
  active --> cancelled: cancellation request processed
  suspended --> cancelled: cancellation request processed
  active --> completed: recurring_cycles_limit reached and last period ended / one-time job done (staff)
  fraud --> pending: order set back to pending
  terminated --> active: staff re-create (SHOULD; runs create)
```

- Status changes for driver-backed services happen **only** after a successful driver call (or explicit staff "set status without module" with audit log). **MUST**
- `terminated`/`cancelled`/`completed`/`fraud` never invoice. **MUST**

## 4. Dates, renewals and payment application

### 4.1 Period arithmetic (anchored; MUST)

`advance(date, cycle, anchor_day)`: add `cycle.months()` to (year, month), then `day = min(anchor_day, days_in_month)`.
Example monthly, anchor 31: Jan 31 → Feb 28 (Feb 29 leap) → Mar 31 → Apr 30. Annual anchor Feb 29 → Feb 28 next year → Feb 29 in next leap year.
`billing_anchor_day` = registration day (or prorata day, catalog.md §3.4). Changing `next_due_date` manually updates the anchor to the new day.
⚠ The current `App\Enums\BillingCycle::advance()` adds months from the _previous_ date with no-overflow, which drifts (Jan 31 → Feb 28 → Mar 28). It MUST take the anchor day.

### 4.2 Initial state at checkout

`registration_date = today`; first period `[today|prorata start, first_period_end)`; `next_due_date = registration_date` (unpaid) and
`next_invoice_date = first_period_end` because the order invoice covers the first period. **MUST**

### 4.3 Renewal generation — job `services:generate-renewals` (daily, step 1 of pipeline)

Eligible: status ∈ {pending, active, suspended}, recurring cycle, `do_not_renew = false`, `terminate_at_period_end = false`,
`recurring_cycles_limit = 0 or renewals_invoiced < limit`.
Due when: `next_invoice_date − lead_days(cycle) ≤ today`, `lead_days = services.renewals.days_before_due.<cycle> ?? services.renewals.days_before_due` (default **14**).
Rules:

1. **Non-continuous mode** (`services.renewals.continuous: false`, default): skip if the service has any **open** (unpaid/payment_pending) renewal item. ⇒ at most one open renewal per service. **MUST**
2. **Continuous mode**: generate regardless of open items, but **at most one period per service per run** (catch-up is gradual, prevents invoice floods). **SHOULD**
3. Period `[next_invoice_date, advance(next_invoice_date))`; line amount = `recurring_amount` (+ addon lines on the same invoice when addons share due date/currency/gateway — Billing groups by client/date/gateway). Promotion: if `promotion_id` and `promotion_cycles_remaining ≠ 0`, add a negative promotion line and decrement remaining. **MUST**
4. Inside one transaction per client: lock service rows → issue invoice → set `next_invoice_date = period_end`, `renewals_invoiced += 1`. The Billing unique index `(type, billable_id, period_start)` on non-cancelled invoices is the last-resort guard against double invoicing. **MUST**
5. Due date of the renewal invoice = period start (= `next_due_date` in normal flow). **MUST**
6. Paying late never moves the anchor (renewal dates stay contiguous). **MUST**

### 4.4 On `InvoicePaid` (listener)

For every service/addon line on the invoice with a period: recompute `next_due_date` = start of the **earliest period not fully paid**
(or the end of the latest paid period when none is open). In the default single-open-invoice mode this equals `max(next_due_date, period_end)`.
Recomputing (instead of incrementing) keeps replays idempotent. Then:

- Initial order invoice and status `pending` → provisioning according to product auto-setup (`on_payment`) unless order is fraud/review. **MUST**
- Status `suspended` with `suspension_reason ∈ {overdue, addon_overdue}` and `services.unsuspend_on_payment: true` and the service no longer matches the suspension rule (§4.5) → unsuspend. Staff-reason suspensions are never auto-lifted. **MUST**
- Driver `renew()` called if the driver supports it. **SHOULD**
- If `renewals_invoiced == recurring_cycles_limit` and the paid period is the last one → set `completed` when `today ≥ next_due_date` (handled by job). **SHOULD**
- On `PaymentReversed` with `billing.reversal.rollback_due_dates`: recompute `next_due_date` = earliest period_start among this service's lines that are no longer fully paid (suspension follows via §4.5). **SHOULD**

### 4.5 Overdue suspension & termination (jobs, daily)

- `days_overdue = today − next_due_date` (only if an **open, non-cancelled** invoice item exists whose period_start = `next_due_date`; a cancelled renewal invoice never triggers suspension — staff must adjust dates instead). **MUST**
- Suspend when `services.suspend.enabled: true` and `days_overdue ≥ product.suspend_days_override ?? services.suspend.days_overdue (7)` and status = active and (`suspend_exempt_until` is null or < today). Reason `overdue`. **MUST**
- Terminate when `services.terminate.enabled: false` (off by default) and `days_overdue ≥ product.terminate_days_override ?? services.terminate.days_overdue (30)`, status ∈ {active, suspended}, not exempt. Resulting status `terminated`. **MUST**
- Addons: same rules on their own dates; addon with `suspend_parent_on_overdue` also suspends the parent with reason `addon_overdue`. Parent suspension/termination cascades to its active addons. **MUST** (cascade terminate) / **SHOULD** (cascade suspend)
- Fixed term (`products.fixed_term_days > 0`): terminate when `today ≥ registration_date + fixed_term_days`, send the configured email. **SHOULD**

## 5. Upgrades / downgrades

Allowed when: upgrade path exists (`PriceBook::canUpgrade`) or config option change allowed; service is active; no other `pending_payment` change for it; `next_due_date > today` (not overdue). **MUST**
Proration (day-based, integer minor units, round half-up at each product):

```
period_end        = next_due_date                                  (paid-through)
cycle_days        = days(period_end − retreat(period_end, cycle))  (actual calendar days of the current period)
remaining_days    = max(0, days(period_end − today))
old_amount        = net amount actually invoiced for the current period (service line + promo line); fallback recurring_amount
new_amount        = PriceBook quote for target product/options, same cycle, client currency, after client-group discount
credit            = round(old_amount × remaining_days / cycle_days)
charge            = round(new_amount × remaining_days / cycle_days)
amount_due        = charge − credit
```

- `amount_due > 0` → order (`kind=upgrade`) + invoice due today with an `service_upgrade` line describing from→to and the date span; promotion (if `allow_upgrades`) discounts `amount_due`. **MUST**
- `amount_due ≤ 0` (downgrade) → no invoice; apply immediately; `|amount_due|` credited to the client if `services.upgrades.credit_on_downgrade: false` is enabled, else forfeited. **MUST**
- **Billing-cycle change**: new period starts today: `charge = full new-cycle price`, `credit` as above, `next_due_date = advance(today, new_cycle)`, anchor = today's day. **SHOULD**
- Free → paid: `charge = full price`, `credit = 0`, `next_due_date = advance(today, cycle)`. **MUST**
- Setup fees are not charged on upgrades (`services.upgrades.charge_setup_fee: false`). `next_due_date` is otherwise unchanged. **MUST**
- Application happens **only when the upgrade invoice is paid** (or immediately for ≤ 0): update product/options/`recurring_amount` (lifetime promos re-computed on the new price), run driver `change_package`; on driver failure status `apply_failed` + module queue (billing changes stay applied). **MUST**
- If an **unpaid renewal** invoice for the next period exists when the change is applied, cancel those renewal lines (Billing item-cancellation adjustment) and reset `next_invoice_date = next_due_date` so the next run re-invoices at the new price. **MUST**
- Unpaid upgrade changes expire after `services.upgrades.unpaid_expiry_days: 7` (invoice cancelled, change `expired`). **MUST**

## 6. Cancellation requests

- Client (if `services.cancellation.client_can_request: true`) or staff submits `{type, reason}`; at most one open request per service. **MUST**
- On submit: `do_not_renew = true`; if `services.cancellation.cancel_unpaid_invoices: true` cancel open renewal lines for the service and its addons (whole invoice if nothing else remains); prompt to disable auto-renew of the matching domain (emit event for Domains). **MUST**
- `process_after` = today (immediate) or `next_due_date` (end_of_period). Job `services:process-cancellations` processes due requests when `services.cancellation.auto_process: true`; otherwise they wait for staff approval. Processing = driver terminate → status `cancelled`, addons cancelled, `termination_date = today`. **MUST**
- Staff "terminate at end of period" toggle = same mechanism (`terminate_at_period_end`). **MUST**
- Void: request `voided`, `do_not_renew = false`, `next_invoice_date = next_due_date` (so cancelled renewal periods are re-invoiced). **MUST**

## 7. Provisioning driver contract (MUST unless tagged)

The contract is in the kernel (ADR 0009): `App\Contracts\Services\Provisioning\ProvisioningDriver` plus capability
interfaces, registered with `App\Support\Services\ProvisioningDriverRegistry::register()`.

- Core: `key()`, `name()`, `metadata(): DriverMetadata` (`requires_server`, default ports, product and server settings
  as `SettingField`s, optional `UsernamePolicy` and command timeout), `create`, `suspend(context, reason)`,
  `unsuspend`, `terminate`, each taking a `ProvisioningContext` snapshot (service, server connection with decrypted
  credentials, client summary, product settings, options and custom fields by `module_key`) and returning a
  `DriverResult` (completed, rejected, retryable or unknown).
- Capabilities (detected with `instanceof`, listed for the UI by the registry): `ChangesPackage`, `ChangesPassword`,
  `ReportsUsage` (per server, daily, SHOULD), `TestsServerConnection`, `ProvidesServiceSso` / `ProvidesServerSso`
  (SHOULD), `ListsAccounts` (COULD), `RenewsAccounts` (SHOULD), `ProvidesFieldOptions` (remote-filled selects),
  `ReconcilesCommands` (settle unknown outcomes). `customActions()` admin/client buttons stay COULD.
- Drivers are stateless, never read or write the host's tables, report changes only through `DriverResult::$data`, throw
  `RetryableProvisioningException` only when the request provably did not run, and are idempotent where the panel allows
  (e.g., `create` for an existing identical account → success). Every driver passes
  `tests/Support/Contracts/ProvisioningDriverContract.php`.
- Ship drivers: `none` (manual: status changes only, welcome email), `log` (demo: records calls, configurable failure injection for tests).

## 8. Command execution, logging, retries

- Every command runs as queued job `RunModuleCommand` on queue `provisioning`, unique per `(subject, command)` and serialized per subject with a lock (suspend/unsuspend/terminate never overlap). Staff-triggered commands run synchronously with timeout `services.module.sync_timeout_seconds: 60` and fall back to queued. **MUST**
- Retries: retryable failures up to `services.module.max_attempts: 3` with backoff `[60, 300, 900]` s; non-retryable fail immediately. After final failure: `module_queue` entry (open), event `ModuleCommandFailed`, staff notification; service status unchanged. **MUST**
- An unknown outcome (the provider may have acted, ADR 0026) is not a failure: it opens a verify entry and sends staff `staff.provisioning_unknown` ("being checked") instead of `staff.provisioning_failed` (#147). When a server check confirms the command, it is applied with no further mail. **MUST**
- Retry / Mark resolved / Dismiss in the queue UI; a later successful run of the same command auto-resolves open entries. **MUST**
- Logging: every attempt logged in `module_commands` with request/response **redacted** (through `App\Support\Integrations\Redactor`: keys naming a secret, including `password`, `secret`, `token`, `api_key`, `hash` and `authorization` in any spelling, and the current credential values, replaced by `[redacted]`). Retention `services.module_log.retention_days: 30` (daily prune). **MUST**
- Username generation: from domain: lowercase letters/digits, starts with a letter, max 8 chars **including** any numeric suffix, unique per server (truncate and append digits on collision); `services.random_usernames: false`. Password: 16 chars, mixed classes. Generation is overridable by driver. **MUST**

## 9. Servers & server groups

- Server chosen **at provision time**: product `server_group_id` → group fill type; no group → driver's default server (one marked default per driver). **MUST**
- `least_full`: lowest `active_accounts / max_accounts` (unlimited treated as 0 % full), tie → lowest sort/id. `fill_sequentially`: first server in group order with free capacity. Inactive servers skipped. **MUST**
- `active_accounts` = services on the server with status ∈ {pending, active, suspended} (cached, recomputed hourly + on change). **MUST**
- No capacity → `create` fails non-retryably with "no server capacity", event `ServerCapacityExhausted`. **MUST**
- A server cannot be deleted while any service (any status) references it; offer bulk reassignment. **MUST**
- Round-robin fill (mentioned in FEATURE_MAP) is **COULD**; category leaders ship least-full and fill-until-full.

## 10. Automation — daily pipeline (company tz, `automation.daily_run_time: "09:00"`)

Single orchestrator command `automation:daily` runs steps in order; each step records `automation_runs(task, run_date, status, stats)` unique per day; re-running skips completed steps unless `--force`. Per-entity operations are idempotent.
1 services:generate-renewals (+ domains renewals) → 2 billing late fees → 3 billing auto-capture → 4 billing reminders → 5 services:process-cancellations →
6 services:auto-suspend → 7 services:auto-terminate → 8 services:fixed-term → 9 services:complete-limited → 10 billing auto-cancel invoices →
11 services:usage-sync (SHOULD) → 12 module log prune. Capture before suspension gives auto-pay clients a chance before being suspended. **MUST**

## 11. Admin capabilities

Services list (filters: status, product, server, overdue, next due range); service detail: edit fields (with "recalculate recurring amount" toggle),
module buttons (create/suspend with reason/unsuspend/terminate/change package/change password/SSO/custom), module log tab, invoices,
change next due date (audit), suspension exemption date, terminate-at-period-end, transfer to another client (open invoices stay with old owner; next renewal goes to new owner),
add addon (with/without invoice), upgrade/downgrade preview & order, delete (local only; warns if not terminated). Cancellation request list (approve/void).
Servers & groups CRUD, test connection, account counts. Module queue. **MUST** (transfer SHOULD)

## 12. Client-area capabilities

Services list & detail (status, next due date, amounts, addons, usage), change password (if supported), SSO login (if supported), request cancellation,
upgrade/downgrade with live proration preview, order addons, view/download related invoices. **MUST** (SSO, usage SHOULD)

Show password (#44; welcome emails carry no password): a live service with a username and a stored password shows a
Password row with "Show password". The value is fetched on demand from `POST client/services/{service}/password/reveal`
(JSON, `Cache-Control: no-store`), never put in page props, and hidden again after 60 seconds, with a copy button. The
request needs `password.recent` (ADR 0018 §4; a login without a password is offered "Set a password"), the services area
of the account (owner, or a user granted `services`), and is limited to 5 reveals per 10 minutes per login across all
services. Each reveal writes "Service password revealed in the client area" to the activity log (who, which service,
when; never the value). Panels that sign in only by SSO store no password, so the row is not shown. **MUST**

## 13. Events emitted

`ServiceCreated`, `ServiceActivated` (first activation → welcome email), `ServiceProvisionFailed`, `ServiceSuspended` (reason), `ServiceUnsuspended`,
`ServiceTerminated`, `ServiceCancelled`, `ServiceCompleted`, `ServiceRenewalInvoiced` (period), `ServiceRenewed` (next_due_date advanced),
`ServiceOverdue` (first day), `CancellationRequested`, `CancellationVoided`, `ServiceChangeOrdered`, `ServiceUpgraded`, `ServiceDowngraded`,
`ServicePackageChanged`, `ServicePasswordChanged`, `ServiceTransferred`, `ModuleCommandSucceeded`, `ModuleCommandFailed`, `ServerCapacityExhausted`,
and `Addon*` equivalents (`AddonActivated`, `AddonSuspended`, …).

## 14. Edge cases & pitfalls

- **Double invoicing** (job overlap, retries, deleted invoices) → `next_invoice_date` pointer + row lock + DB unique index + `automation_runs`.
- **Month-end drift** with naive add-month → anchored arithmetic.
- **Timezone**: "today" in company tz; a UTC server at 23:30 must not bill tomorrow's renewals early.
- **Cancelled renewal invoice ⇒ unexpected suspension** in naive systems → suspension requires an open invoice for the overdue period.
- **Paying the newer of two overdue invoices** (continuous mode) must not advance past the older unpaid period → `max(period_end)` is only applied once all earlier periods are paid: `next_due_date` = start of earliest unpaid period. **MUST**
- **Manual status change** without module call leaves orphaned remote accounts → separate "set status only" action with warning.
- **Terminate failure** leaves an account running while billing stopped → stays in module queue until resolved.
- **Concurrent payment during suspension job** → per-service lock; unsuspend listener re-checks state.
- **Upgrade while renewal already invoiced** → renewal lines re-issued after change (§5).
- **Downgrade credit abuse** (upgrade→downgrade loops) → credit only when setting enabled; credits logged with reason `downgrade`.
- **Leap-year anchors** (Feb 29) and short months handled by anchor day.
- **Server credentials in logs** → redaction; never log decrypted secrets.
- **Transfer of ownership** with open invoices → invoices stay; documented in UI.

## 15. Acceptance criteria

1. Given monthly anchor 31 and next_due Jan 31, when advanced 3 times, then dates are Feb 28, Mar 31, Apr 30 (non-leap year).
2. Given annual anchor 29 Feb 2028, when advanced, then 2029-02-28; advanced 4 years from 2028-02-29 gives 2032-02-29.
3. Given a monthly service with next_invoice_date 2026-11-15 and lead 14, when the job runs on 2026-11-01, then one invoice for [11-15, 12-15) is created and next_invoice_date becomes 12-15.
4. Given the job runs twice the same day, then only one renewal invoice exists.
5. Given non-continuous mode and an open renewal invoice, when the next period becomes due, then no new invoice is created.
6. Given continuous mode and two periods due, when the job runs once, then exactly one period is invoiced.
7. Given a cancelled renewal invoice for a period, then the job does not regenerate that period automatically.
8. Given a renewal invoice for [11-15,12-15) paid on 11-20, then next_due_date = 12-15 (not 12-20).
9. Given `InvoicePaid` replayed, then next_due_date does not advance twice.
10. Given suspend days 7 and an open invoice due 7 days ago, when the suspend job runs, then the service is suspended with reason overdue and the driver suspend was called once.
11. Given the overdue invoice was cancelled, then the service is not suspended.
12. Given suspend_exempt_until in the future, then neither suspension nor termination happens.
13. Given a service suspended for overdue, when the invoice is paid, then it is unsuspended; given a staff-reason suspension, then it stays suspended.
14. Given termination disabled (default), then no service is ever auto-terminated.
15. Given an addon with suspend_parent_on_overdue overdue past suspend days, then addon and parent are suspended (parent reason addon_overdue).
16. Given parent terminated, then all its active addons are cancelled with termination_date today.
17. Given a 30-day period, old 10.00, new 20.00, 15 days remaining, then credit 5.00, charge 10.00, amount_due 5.00.
18. Given the upgrade invoice unpaid, then the service is unchanged; when paid, then product, recurring_amount are updated and change_package runs once.
19. Given an unpaid upgrade older than 7 days, then the change is expired and its invoice cancelled.
20. Given a downgrade with amount_due −4.00 and credit_on_downgrade on, then client credit +4.00 and change applied immediately with no invoice.
21. Given an unpaid renewal at old price when an upgrade is applied, then the renewal lines are cancelled and the next run invoices the new price.
22. Given a free service upgraded to monthly 10.00 on Jan 1, then the invoice is 10.00 and next_due_date Feb 1.
23. Given an end_of_period cancellation request with next_due Dec 1, when the job runs on Nov 30, then nothing happens; on Dec 1 the service is terminated → cancelled.
24. Given a cancellation request with cancel_unpaid_invoices on and a renewal invoice containing this service and another, then only this service's lines are cancelled.
25. Given a voided request, then do_not_renew is false and the cancelled period is re-invoiced on the next run.
26. Given auto_process off, then requests stay open until staff approve.
27. Given group least_full with S1 (50/100) and S2 (10/100), then provisioning picks S2.
28. Given fill_sequentially with S1 (100/100) and S2 (0/50), then S2 is picked; with both full, create fails "no server capacity".
29. Given a driver throwing a retryable error twice then succeeding, then 3 attempts are logged and no module queue entry remains open.
30. Given a non-retryable failure, then one attempt is logged, a module queue entry is open and status is unchanged.
31. Given a module call whose request contains the server password, then the stored log contains `[redacted]` instead of it.
32. Given suspend and terminate dispatched concurrently for one service, then they execute sequentially.
33. Given domain "example-shop.com" on a server where "examples" exists, then the generated username is "example1" (8 chars, unique on that server).
34. Given recurring_cycles_limit 3, after 3 renewals invoiced no 4th is generated and the service becomes completed after the last period ends.
35. Given the daily pipeline completed today, when re-run without --force, then no step executes again.

## 16. Out of scope for v1

Metric/usage-based billing and overage invoicing; server account import/sync UI (COULD via listAccounts); on-demand early renewals; client-initiated
addon-only cancellation; per-service gateway subscriptions; VPS/cloud orchestration specifics (drivers own them); WordPress/SSL/marketplace product
specifics; status monitoring of servers; bulk service migration between servers (beyond reassign); scheduled (future-dated) staff actions.
