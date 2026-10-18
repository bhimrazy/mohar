# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Progress, status and screenshots: [docs/progress.md](docs/progress.md).

## [Unreleased]

### Upgrade notes

- **Exchange rates are a separate, sensitive permission.** `core.currencies.rates` now guards creating and deleting
  currencies, rate sources and manual rates, rounding and buffer rules, "Update rates now", approving or rejecting
  held rate changes, changing the default currency, converted prices and their settings. Wildcards such as
  `core.*` never grant it, and each change needs a recent password. Roles that held only `core.currencies.manage`
  keep currency display settings; grant `core.currencies.rates` explicitly to staff who maintain rates.
- Exchange-rate columns widen to `decimal(24,12)` (currencies, rate history, reviews, transactions, payment
  batches and attempts). Existing values are kept; rolling the migrations back rounds rates to six decimals.
- Existing prices in non-default currencies are marked "set manually" (TLD prices always; product prices when
  automatic conversion was off), so the first price conversion leaves them unchanged.

### Platform foundation

- Laravel 13 app on the official React starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui),
  Fortify auth (2FA, passkeys), Wayfinder, Pest 5, Larastan level 7, Laravel Boost AI guidelines & skills.
- Modular monolith: auto-discovered `app/Modules/*` with conventions for config, migrations, routes, views,
  commands and scheduled tasks; `MODULES=` filter for isolated test runs (ADR 0001).
- Shared kernel: `Money` (minor units, ADR 0002), `BillingCycle`, `BillableType`, cross-module contracts
  (`Invoicer`, `TaxCalculator`, `CreditLedger`, `PriceBook`, `ServiceRegistry`, `DomainRegistry`,
  `DomainPricing`, `MergeFieldRegistry`), DTOs and 37 domain events.
- Server-driven sidebar navigation and dashboard-card registries; admin (`/admin`) and client (`/client`) areas.
- Object storage: `objects` disk backed by RustFS via `compose.yaml` + `objects:ensure-bucket` command (ADR 0004).

### Themes & brand

- Themes module: theme token schema (`billing-theme` v1, `ThemeSchema`) and `ThemeRegistry` kernel for
  presets from modules or theme packs. Four presets ship: Calm Fintech (default), Operator Pro, Hosting Warm and
  Enterprise Slate. CSS variables are generated per theme version and injected with the CSP nonce.
- Admin → Settings → Appearance (`themes.manage`): theme per area (admin, client area, storefront), default
  density, optional per-staff theme/density. Custom themes come from an editor with a light/dark live preview
  and a WCAG AA contrast guard that blocks saving. JSON export/import is strictly validated. Rename and delete
  are supported, and a theme that is in use cannot be deleted.
- Brand (Mohar by default): name, tagline, logos (light/dark), mark and favicon, with uploads on the objects
  disk (SVG rejected) and reset to default. `BrandProvider` contract for pages, mail layout and invoice PDFs.
  Default SVG logos, favicon.ico and apple-touch icon replace the starter-kit logo.
- UI: shadcn Chart (Recharts 3.10.1) with themed area/bar charts and KPI sparklines; sidebar with collapsible
  groups, badges, ⌘K command palette and quick-create; admin dashboard renders kernel widgets, deferred widgets
  and "Needs attention" todos.

### Services

- Services, service addons, servers and server groups (least full / fill until full, max accounts, encrypted
  credentials), per-service billing dates with anchored cycles and a local invoice ↔ period mirror.
- Provisioning driver contract (`create/suspend/unsuspend/terminate/changePackage/changePassword/usage/testConnection`,
  optional SSO) with a driver registry and `none` (manual) and `log` (demo, failure injection) drivers.
- Module commands as queued jobs on `provisioning`: per-service lock, unique per attempt, retryable failures with
  backoff (60/300/900 s, 3 attempts), redacted module log, failed-actions queue (retry / resolve / dismiss),
  `ProvisioningFailed` event; staff actions run synchronously first.
- `ServiceRegistry` implementation (createPending, createPendingAddon, provision, cancelPending).
- Automation tasks: renewal invoices N days before the period (idempotent via `hasInvoiceForPeriod` + pointer),
  cancellations, unpaid-upgrade expiry, overdue suspension/termination (per-service overrides, exemptions,
  addon → parent), cycle-limit completion, usage sync, module log pruning.
- `InvoicePaid` handling: due-date recomputation (replay-safe), auto-setup on payment, unsuspend on payment,
  upgrades applied on payment; `InvoiceCancelled` / `OrderPlaced` keep the invoice mirror in sync.
- Prorated upgrades/downgrades (upgrade invoice or downgrade credit), billing-cycle changes, cancellation
  requests (immediate / end of period, void, staff approval).
- Admin: services list and detail (module actions, credentials reveal, set status, upgrades, addons, billing
  periods, module log), edit, servers & groups CRUD with test connection, cancellation requests, module log.
  Client area: my services, service detail with usage, plan change preview, cancellation, password change.
- Navigation, dashboard cards, `services.*` permissions, settings, global search, `service` merge fields, demo seeder.
- Provisioning runs on the kernel driver contracts (#62): drivers receive snapshots, never models. An exception or
  timeout during a driver call is an unknown outcome: never retried, and staff verify it. Upgrades on a driver that
  cannot change packages stay applied, with an activity note and no failure alert. A driver's username policy
  applies to generated and staff-typed usernames (manual services stay free-form). Long-running drivers get longer
  job timeouts (up to 180 s), and staff "run now" queues them instead of blocking the page. One broken driver no
  longer stops automation for other services. The queue `retry_after` default is now 240 s.
- An open "verify" item (unknown outcome) can no longer be turned into an ordinary failure by a later job failure,
  so create is never sent twice. Accepting an order whose provisioning is queued says "Provisioning queued for …"
  instead of reporting a failure.
- Servers store driver-declared settings (encrypted `servers.settings`), shown on the server form through the shared
  settings renderer that gateways and products now use too. Secret settings are never sent back, a blank one keeps its
  value, and a change is audited as "rotated". Custom fields without a module key are no longer sent to drivers (B2).

### Core

- Clients, client groups, contacts, multi-user client accounts (owner + per-area permissions), staff flag,
  roles with wildcard permissions, currencies, settings store, activity log.
- Admin: client list/search/filter, create, view, edit. Client area: dashboard, account details, account switcher.
- Registration creates an owned client account.
- Seeders: currencies (USD/EUR/GBP), roles, `admin@example.com` / `demo@example.com` (password: `password`).

### Platform admin

- Staff: invite by email (7-day link, resend/cancel) or create with a temporary password (forced change on first
  sign-in); edit, assign role, deactivate (sessions revoked, next request 401), reset 2FA; last sign-in time/IP.
- Staff 2FA enforcement (`security.staff_2fa_required`, default on) before any admin route.
- Roles CRUD with a permission picker built from `PermissionRegistry` (module/area wildcards); privilege-escalation
  guard (only grant what you hold) and last-full-administrator protection; system roles undeletable.
- Currencies CRUD with decimals/format, exchange rates, default switch (rebases rates; blocked once invoices exist),
  `Currency::convertTo()` (round half up across decimal places). Client groups CRUD (discount, suspension/invoice flags).
- Generic settings UI over `SettingsRegistry` (per-section permission, typed validation, secret write-only values,
  activity-logged diffs); Company and Security sections registered.
- Activity log viewer with filters; activity entries now record actor type, event key, impersonator and user agent.
- Automation run log with per-task status and "run now".
- Admin ⌘K global search (`GET /admin/search`, permission-filtered groups, max 5 per group); Contacts and Staff providers.
- Client profile: contacts CRUD, client logins (invite, per-area permissions, ownership transfer, removal),
  close/reopen (`ClientClosed`), merge with preview (same currency, one transaction, tombstone redirect).
- Login as client: signed single-use link, 60-minute cap, banner with "Return to admin", account-security routes blocked,
  audit trail in `impersonation_sessions` and `activity_log.impersonator_id`.
- Real UI for the admin dashboard, clients index/show/form, client dashboard and account details.
- Permission catalog: `core.clients.create|update` replace `core.clients.manage`.

- Product polish (sweep 1): breadcrumbs on every admin and client page; the Date format setting now applies to
  every screen and the invoice PDF (allowed formats in `Core\Support\DateFormats`; default "Oct 24, 2026", and
  values off the list fall back to it). Installs where Company settings were saved before have `Y-m-d` stored,
  so their dates switch to `2026-10-24` until an admin picks another format. Knowledge base, announcements and
  network status stay inside the client area for signed-in customers; the home page links to the store, domain
  search and support; registrar names and readable activity entries replace internal keys.

### Catalog

- Product groups and products with currency × billing-cycle pricing and setup fees (minor units), configurable options
  (dropdown, radio, yes/no, quantity) with a pricing-completeness guard, custom fields, addons and upgrade paths.
- `PriceBook` implementation: client-group pricing, atomic stock reservation and order-independent release on
  cancel/fraud, slug lookup; never throws for invalid selections.
- Provisioning binding with installed-driver settings; driver secrets encrypted at rest and never sent to the browser.
- Admin Setup pages, duplicate product, prorata calculator (`App\Support\Billing\Prorata`), opt-in price
  auto-convert, Catalog events, demo catalog in USD/INR/NPR.

### Domains

- TLD catalog with per-term register/transfer/renew prices per currency and client-group slab (no silent currency
  conversion), grace/redemption periods and fees, renew windows, EPP requirement and registrar assignment.
- `DomainPricing` (`price`, `terms`, `split` for multi-level TLDs such as `.co.uk`, renewal terms within the
  10-year registry horizon) and `DomainRegistry` (`createPending`, `process`, `cancelPending`) implementations.
- Domain lifecycle state machine (pending → active → grace → redemption → expired, transfers, cancellations) with
  activity log and events; IDN/punycode-safe name normalisation and validation; one live record per name.
- Registrar driver contract, driver registry and deterministic `log` demo registrar; every call goes through a
  gateway that checks capabilities and writes a redacted `registrar_command_log`.
- Queued register/transfer/renew jobs with retries; timeouts never re-register; renewals are never repeated.
- Automation: renewal invoicing (idempotent per cycle), grace/redemption invoice swap and expiry cancellation,
  ICANN-style reminders with per-client digest, registrar and transfer sync (hourly `domains:sync`), log pruning.
- Admin: domain list (status/registrar/TLD/expiry/client filters) and detail (dates, amounts, forced status,
  registrar commands, nameservers, lock, EPP, contacts, command log, move, delete), TLD pricing grid, registrars.
- Client area: my domains, domain management (nameservers, auto-renew, lock, EPP code, contacts, transfer retry).
- Public domain search with availability, suggestions and prices; JSON lookup endpoint (rate limited, cached).
- Navigation, dashboard cards, permissions, settings, global search and `domain` merge fields; demo seeder.
- Review fixes: exactly-once registrar submissions/renewals (row-lock claims, unique non-overlapping jobs, unknown
  outcomes flagged `needs_review` and verified, never re-sent), disabled registrars refused, zero-total renewal
  invoices handled, renewal chunking fixed, lookups cached as arrays, strict pricing-grid input, "Renew now" for
  clients and staff, needs-attention todos, `DomainPricing` v2, domain events promoted to `app/Events/Domains`,
  USD/INR/NPR demo prices.

### Orders

- Storefront and cart: guest carts merge into the account on login, every read re-quotes the cart, and carts expire
  after `orders.cart_ttl_days` (`orders:prune-carts`).
- Promotions of four types (percentage, fixed amount, price override, free setup) with usage restrictions; admin
  CRUD with a usage report (`orders.promotions.manage`).
- Checkout with inline registration and an idempotency key. The order, its items, pending services, addons and
  domains, stock reservation, promotion redemption and the invoice (`Invoicer::issue`) are written in one
  transaction; then the fraud check runs, `OrderPlaced` is dispatched and on-order provisioning starts.
- Fraud drivers `none` and `rules` (banned email domains, max orders per IP, max pending orders, skip for existing
  clients, timeout, fail-open), configured with `orders.fraud.configure`.
- Admin: order list and detail with accept (runs account creation per item), cancel, mark as fraud, set pending,
  delete, bulk actions and staff-placed orders (`orders.orders.view|create|accept|cancel|mark_fraud|delete`).
  Client area: order history.
- Settings for terms of service, auto-accept on payment, unpaid-order auto-cancel and checkout notes; stale pending
  orders are cancelled by `orders:cancel-stale-pending`.
- Public layout for storefront pages; `formatMoney` groups INR and NPR in the en-IN style. Navigation badge,
  quick-create, dashboard widget and todos; demo seeder in USD/INR/NPR.

### Billing

- Implements `Invoicer`, `TaxCalculator`, `CreditLedger`, `GatewayDirectory` and `InvoiceQueries`, and consults
  `PaymentGuard` before taking a payment.
- Invoices are immutable once issued; changes go through an adjustments ledger. Gap-free and proforma numbering,
  client snapshots, reopen, auto-cancel, and quotes; invoice and quote PDFs are cached on the `objects` disk.
- Payments: partial payments, overpayment to credit, credit ledger, add funds, mass pay, payment batches, refunds
  with their reverse effects, chargebacks, manual transactions and currency conversion.
- Tax: two levels, compound and inclusive rates, exemptions, EU reverse charge (VAT ID format check only, no
  VIES), India GST (CGST + SGST within a state, IGST across states by GSTIN), Nepal VAT 13 %, effective-dated rules.
- Gateway contracts in the shared kernel: `GatewayDriver`, `HostedPaymentDriver` (initiate, browser return as a
  hint, authoritative `lookup()`) and `GatewayRegistry`. An attempt is settled only through `lookup()` with amount,
  currency and reference checks; a reconciliation poller (`billing:reconcile-payments`) resolves open attempts and
  unresolved ones become staff todos. Drivers: `bank_transfer`, `manual` and `sandbox_card` (full hosted flow
  without network calls).
- Automation tasks: late fees, auto-capture with retries, payment reminders, auto-cancel, payment reconciliation
  and housekeeping.
- Admin and client UI for invoices, transactions, quotes, account credit and gateways; permissions
  `billing.invoices.*`, `billing.payments.manage`, `billing.refunds.manage`, `billing.transactions.*`,
  `billing.credit.manage|adjust`, `billing.quotes.*`, `billing.gateways.manage`, `billing.gateway_log.view`,
  `billing.tax.manage`, `billing.settings.manage`. Dashboard widgets, todos, badge, quick-create and a demo seeder
  (USD/INR/NPR).

### Nepal payments

- New `PaymentsNepal` module with hosted drivers for Khalti (KPG-2) and eSewa (ePay v2), registered with Billing's
  `GatewayRegistry`. Credentials are stored in the encrypted gateway config and are write-only in the admin form.
- A payment is marked paid only after the provider's status lookup agrees, including on a validly signed eSewa
  return. Amount, currency and reference are compared with the stored attempt; a mismatch goes to staff review.
  eSewa responses are checked with `hash_equals` over the provider's `signed_field_names`.
- Khalti: full refunds through the API; partial refunds stay off behind `payments_nepal.khalti.partial_refunds`.
  eSewa refunds are recorded manually. Both drivers have a "Test connection" button on the admin gateway form
  (`billing.gateways.manage`, throttled).
- Sandbox mode (`SandboxPolicy`) cannot be activated, offered or charged in production; a SANDBOX badge shows in
  admin and on the client pay panel.
- A started payment stays on the client and admin invoice, and a second one is refused until the provider session
  expires. Methods outside a provider's amount limits are listed disabled with the reason; a converting gateway
  shows the amount it will charge before the redirect.
- Form-post gateways now open a full-page checkout whose CSP allows exactly the provider origin
  (`SecurityHeaders::allowFormAction()`); before, the browser blocked the eSewa form.
- Provider logos on the pay panel and admin gateway pages; client pay requests are rate limited per user.

### Support

- Helpdesk: departments with staff scoping, masked ticket numbers (`support.ticket_number_format`), guest tickets
  through access-key links, replies and internal notes in sanitised Markdown, statuses with an audit trail, reply
  collision detection, canned replies, signatures, ticket merge and reply ratings.
- Attachments are private files on the `objects` disk with authorised downloads, extension, size and count limits,
  and pruning (`support:prune-attachments`).
- Inbound email: signed webhook `POST /api/v1/inbound/email/{provider}` (`generic` HMAC provider, secret in
  `support.inbound_secret`) with deduplication, loop, auto-reply, per-sender rate limit and spam filters and bounce
  handling; admin inbound log with import into a ticket; raw messages purged by `support:purge-inbound-raw`.
- Auto-close after `support.auto_close_hours` (`support:auto-close`, hourly and in housekeeping).
- Knowledge base (views, votes, search, private articles), announcements (scheduled, RSS feed) and network status
  incidents.
- Guest forms use a honeypot and rate limiting. Ticket events live in `app/Events/Support`.
- Tickets navigation badge, "New ticket" quick-create, dashboard widget and todos; `support.*` permissions for
  tickets, departments, canned replies, inbound email, knowledge base, announcements, network status and settings.

### Currencies

- One money display: prefix, suffix and number format per currency, including Indian lakh/crore grouping
  (`1,23,456.78`). `App\Support\Currencies\MoneyFormatter` on the server and `formatMoney` in the browser read the
  same settings; amount inputs label the currency, and invoice and quote PDFs state the currency under the totals.
  NPR's seeded prefix changes to "Rs ".
- Exchange-rate sources: Manual, ECB reference rates (`ecb`) and Nepal Rastra Bank (`nrb`), behind the
  `ExchangeRateSource` contract. Rates are derived through the default currency with twelve decimals and no floats.
- The `platform.exchange-rates` task runs first in the daily pipeline; `platform.rates_update_time` moves it to
  `platform:update-exchange-rates` at a set time, and Admin → Currencies → "Update rates now" runs it on demand.
- A fetched rate that moves more than `platform.rates_max_change_percent` (default 10) is held for staff review and
  shown in "Needs attention", as are stale automatic and manual rates. Every rate change writes rate history and an
  activity entry; the currency page charts the last 90 days.
- Converted prices: a per-currency FX buffer and rounding rule, a preview-and-apply page for Catalog and TLD prices
  (`PriceConverter` contract), and a queued conversion after a rate change when auto-convert is on. Prices set
  manually in a non-default currency are never converted; existing services and issued invoices are not repriced.
- New sensitive permission `core.currencies.rates` (see Upgrade notes).

### Dashboard and automation

- Admin dashboard v2: KPIs for MRR, income this month, outstanding and overdue with sparklines and trend pills; a
  counter strip; "Needs attention" with icons and context lines.
- Trends: 12-month income against the year before (with a 30-day range and table view), new orders per day by product
  type, new clients, and income and tax collected per currency.
- System health aggregated from modules (scheduler, daily automation, queue, provisioning, payment gateways,
  registrar commands); recent orders, payments, tickets, renewals and newest clients; an Activity card with staff
  sign-ins; quick actions.
- Reporting-currency switch: each currency on its own, or "All (est.)" converted at today's rates.
- Automation tasks have readable names and descriptions everywhere (`Automation::task()` takes `label` and
  `description`). The Automation page adds scheduler state, 7-day success rate, failures in 24 hours, next run,
  average run time, runs per day, time per stage and a cron fix banner when the scheduler is late or stopped.
- Charts expose a "Show as table" toggle and no longer take keyboard focus; theme chart palettes were adjusted to
  pass the colour-vision checks.
- Kernel: dashboard `breakdown` widget type, `health()` registry, `mirrorsTodo` cards and `Trend`; new index
  `invoices(status, paid_at)`.

### Install

- `php artisan app:install`: an idempotent production install. It runs pre-flight checks (APP_KEY, database and
  migrations block; queue, scheduler, objects disk, `APP_DEBUG` and the mailer warn), installs each module's reference
  data, creates the first full administrator and prints the next steps.
- The administrator password follows the production password rules in every environment and is never accepted as a
  CLI option. `--admin-email` creates the account with a one-time password printed once and a forced change at
  first sign-in; `--additional-admin` is required for a second administrator, and existing accounts are never
  promoted.
- Reference data comes from `InstallsReferenceData` installers in `ReferenceDataRegistry`. Core installs USD, INR
  and NPR (only while no currency exists) and the four system roles, matched by the new `roles.key`; a re-run
  resets system-role permissions and keeps staff-chosen names.
- `db:seed --force` in production installs reference data and skips demo data. Guide: `docs/devops/install.md`.

### Shared kernel

- Integrations area (ADR 0009, step 1): `IntegrationDriver` and an abstract `DriverRegistry` (keyed registration,
  lazy resolution, capabilities computed from the family's interfaces) for driver families to extend.
- `SettingField` DTO and `FieldType` enum for driver settings; Billing reads gateway settings through
  `SettingField::fromArray()`. `ConnectionCheck`, `ProvidesLogo` and `IntegrationLogo` moved to the Integrations
  namespace.
- `Redactor` moved to the kernel and is shared by Services, Domains and Billing's gateway log: one `[redacted]`
  mask (Services logs used `***`), registrar and cPanel key lists, `cpsess` token values and `excerpt()` for raw
  driver output.
- `OutboundAddressGuard` resolves a host once and refuses loopback, link-local, cloud metadata, multicast and
  reserved addresses; `pin()` connects to the checked address with `CURLOPT_CONNECT_TO`, bypasses proxies and turns
  redirects off. The policy is install-wide (`OUTBOUND_ALLOW_PRIVATE_ADDRESSES`, `OUTBOUND_ALLOW_LOOPBACK`; ADR
  0015).
- Provisioning driver contracts (ADR 0009, step 2, #62): `ProvisioningDriver` plus capability interfaces
  (`ChangesPackage`, `ChangesPassword`, `ReportsUsage`, `TestsServerConnection`, `ProvidesServiceSso` with
  `ssoTargets()`, `ProvidesServerSso`, `ListsAccounts`, `RenewsAccounts`, `ProvidesFieldOptions`,
  `ReconcilesCommands`), snapshot DTOs instead of models (`ProvisioningContext`, `ServiceSnapshot`,
  `ServerConnection`, `ClientSummary`), `DriverResult` with an unknown outcome, the `DriverOutcome` and
  `ReconcileStatus` enums, `UsernamePolicy` and per-driver command timeouts, and the final
  `ProvisioningDriverRegistry`. A package registers a driver from its own service provider without the Services
  module being loaded. Every driver passes a shared contract dataset.
- `PriceBook::moduleInputs()` (options and custom fields by module key, hidden, admin-only and retired included) and
  `PriceBook::productsForDriver()`; provisioning snapshots carry options by module key. `ProvisioningDrivers::all()`
  gains `fields` in the shared `SettingField` shape (`config_fields` deprecated) (#62 B2).
- `ServiceImported` event; `ConnectionCheck` gains an optional checklist (`ConnectionCheckStep`, `CheckStatus`) and
  details; `ProvisioningDrivers::remoteOptions()` for remote-filled selects; `GatewayDriver` extends
  `IntegrationDriver`.

### Security

- Password-reset and verification links no longer take their host from the request. Outside `local`, every generated
  URL, including queued mail, uses `APP_URL`, and production rejects requests for foreign hosts with 400.

### Database hardening (audit 1)

- Whole-database audit on PostgreSQL with 100k clients and 1M-row log tables:
  `docs/database/audit-2026-10-18.md` (findings per module, EXPLAIN before/after, queries per page).
- 55 indexes in eight migrations: every foreign key now has an index (PostgreSQL does not add them), plus the
  transaction list sort, client sign-up date and `lower(email)` sender matching. Built CONCURRENTLY through the
  new `OnlineIndex` helper (refuses to run inside a transaction or to reuse a differently defined index). The admin order list went from 0.7–9 s to 4 ms; client service and order lists,
  service addons, usage history, module and registrar logs, and user deletes stopped scanning whole tables.
- Constraints: one default currency, and one default-list TLD price per TLD, currency and years (partial
  unique indexes; NULL `client_group_id` had bypassed the unique key).
- Fixed: daily automation (suspensions, terminations, cancellations, limited-service completion, upgrade
  expiry, stale-order cancellation) skipped a page of matches after every 1,000 rows it changed; it now pages by
  id. Usage sync no longer stores a second snapshot when re-run for an earlier date.
- Order and transaction date filters use index ranges instead of `whereDate()` on timestamps; settings are read
  once per request instead of one cache query per lookup (33 per admin page); retention pruning deletes in
  batches (`ChunkedDelete`, which keeps rows that stopped matching after selection).
- Demo seeders refuse to run in production, also one by one with `db:seed --class` (they create demo accounts
  with a known password); production reference data comes from the install path in #47.

### DevOps

- PostgreSQL 18.6 (alpine, digest-pinned) in `compose.yaml` next to RustFS, with a `billing_test` database;
  `.env.example` documents switching `DB_CONNECTION=pgsql`. SQLite stays the default for dev and tests.
- CI moved to the repository root (`.github/workflows/ci.yml`, the kit's `billing/.github` workflow was
  ignored by GitHub): `lint` (postgres image drift check, Pint, vp check, tsc), `static` (Larastan),
  `tests-sqlite`, `tests-pgsql` (migrate:fresh --seed + full suite on a PostgreSQL service), `build`; cached
  Composer/npm, SHA-pinned actions, least-privilege permissions, superseded PR runs cancelled. Dependabot
  (target `develop`, 14-day cooldown) for actions, compose, Composer and npm.
- `composer ci` runs every CI gate locally, including `composer test:pgsql` (migrate:fresh --seed + suite on
  PostgreSQL; `DB_*` overridable per worktree). `.nvmrc` pins Node 24.21.0; `composer.json` requires PHP ^8.4.
  RustFS and PostgreSQL ports bind to 127.0.0.1. Local setup guide: `docs/devops/local-setup.md`.
- `vite-plus` 0.3.0 → 0.3.3 (fixes the critical `tinypool` advisory GHSA-85c8-ppgw-ccpr; `npm audit` clean);
  `vite` is now aliased to `@voidzero-dev/vite-plus-core@0.3.3` (dependency + override), as 0.3.3 requires.
- Fixed: admin client search was case-sensitive on PostgreSQL (`whereLike` now renders `ilike`); client,
  contact and new-user emails are stored lowercase.
- Dependency policy (ADR 0007, `docs/devops/dependency-policy.md`): exact versions for every direct Composer/npm
  dependency, `.npmrc` `save-exact` + native `min-release-age=7`, Composer `config.policy` (block advisories and
  malware), RustFS image digest-pinned. `scripts/check-dependency-age.mjs` fails CI and `composer ci` when a
  changed package, action, image or tool is younger than 7 days, an action SHA does not match its tag or
  anything is unpinned; `security-exception` label + CTO approval waives only the cooldown.
- Lockfiles brought into compliance: 94 packages younger than 7 days moved back to the newest older release
  (e.g. `laravel/framework` 13.37.0 → 13.34.0, Pest 5.3.2 → 5.3.0 with PHPUnit 13.3.6, symfony 8.1.9 → 8.1.8,
  `react` 19.3.1 → 19.3.0, `radix-ui` 1.8.1 → 1.7.0 and the `@radix-ui/*` family). Framework 13.35+ needs
  Wayfinder ≥ 0.1.22 (QUERY verb) and PHPUnit 13.4 needs framework ≥ 13.35, so both wait for the cooldown.
- New gates: `composer validate`, `composer audit --locked`, `npm audit --audit-level=high` (`composer deps:check`).
- CI minutes: one job instead of five (≈ 2 billed minutes per PR run instead of 5), no runs for drafts,
  feature-branch pushes or screenshot-only changes, docs-only PRs run only `vp check`, PHPStan result cache,
  15-minute timeout (`docs/devops/ci.md`).
- Dependency check hardening (PR #9 review): image digests must be the tag's registry digest (index or platform),
  npm `resolved`/`integrity` and Composer `dist`/`source` must match the registry for the checked name@version;
  offline unit tests for these rules run in `npm test`. Policy checks run before `composer install`/`npm ci`;
  push runs diff against `github.event.before`; `security-exception` waives only `Security-Exception:
name@version` items listed in the PR body (job summary lists them). Vitest (`npm test`) in CI and `composer ci`.
  No workflow `paths-ignore` (image/docs-only PRs take the in-job fast path). Follow-up #11: framework 13.36.x +
  Wayfinder 0.1.22 after 2026-10-22.
