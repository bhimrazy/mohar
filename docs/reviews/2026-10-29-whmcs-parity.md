# WHMCS parity review, 2026-10-29

Asked by the owner: compare Mohar with WHMCS area by area and feature by feature. Say where we cover a feature,
where it is missing, and where we are better or worse. This is research only. Product manager agent, read-only on code.

## 0. Method, sources and limits

- **What Mohar has** comes from `origin/develop` at `a90717a3` (#514). I read the route files
  (`billing/app/Modules/*/routes/web.php`, `billing/routes/*.php`), the module folders, drivers, enums,
  `billing/app/Contracts`, `billing/app/Events` (118 event classes), the automation task keys, the console commands,
  `composer.json` and `resources/js/pages`. Specs were not taken as proof. When a spec says a feature exists and the
  code does not, the row says **Spec only**.
- **What WHMCS has** comes from the three parity studies already in the repo (`billing/docs/parity/*.md`,
  public docs read 2026-10-18) and these pages, re-read today:

| Source | Read | Used for |
| ------ | ---- | -------- |
| https://docs.whmcs.com/releases/ | 2026-10-29 | Current lines: 9.1, 9.0 (LTS to 2026-12-31), 8.13 |
| https://docs.whmcs.com/releases/9-1/9-1-release-notes/ | 2026-10-29 | Invoice immutability setting, credit and debit notes, unattended maintenance updates, Stripe Dynamic Payments, required MarketConnect sign-up, password history, REST v2 headless cart |
| https://docs.whmcs.com/releases/9-0/9-0-release-notes/ | 2026-10-29 | Nexus cart, AI domain spinning, immutable non-draft invoices, VAT validation for about 29 countries, Peppol e-invoicing (Belgium only), login rate limit, cron HTTP block, email import caps |
| https://developers.whmcs.com/api/api-index/ | 2026-10-29 | About 162 API actions in 16 categories |
| https://developers.whmcs.com/hooks/hook-index/ | 2026-10-29 | About 339 hook points in 19 categories |
| https://docs.whmcs.com/9-0/payments/payment-gateways/ | 2026-10-29 | 63 gateway modules documented |
| https://docs.whmcs.com/9-0/domains/domain-registrars/ | 2026-10-29 | 21 registrar modules documented |
| https://requests.whmcs.com/ | 2026-10-29 | Current top-10 feature requests and their votes |

- **Limits.** No app was run for this review. "Built and tested" means the code is on `develop` with feature tests
  (565 test files). It does **not** mean verified against a live provider. Every gateway, registrar, WHM and WHMCS
  connector is verified only against our own simulators (owner decision 2026-10-22). The WHMCS demo was not
  reachable on 10-18 and was not retried. Two docs URLs for WHMCS server modules returned 404 today, so the
  provisioning row uses the 10-18 study. All wording here is ours.

**Status key.** **Built and tested**: in code with tests. **Partial**: some of it is built and a named piece is
missing. **Spec only**: written in `billing/docs/product/`, no code. **Missing**: neither code nor spec. **Not
planned**: deliberately left out. **vs WHMCS**: Better, Same or Worse, with the reason. A dash means the
comparison is not meaningful until the feature exists.

## 1. Headline numbers

The matrix has 226 WHMCS features across 18 areas. The counts below come from a script over the §2 tables, not
from estimates.

| Status | Count | Share |
| ------ | ----- | ----- |
| Built and tested | 152 | 67% |
| Partial | 17 | 8% |
| Spec only | 15 | 7% |
| Missing | 39 | 17% |
| Not planned | 3 | 1% |

Of the 169 that are Built or Partial: Better 53, Same 104, Worse 12. Of the 39 Missing: Worse 33, Same 5 (WHMCS
lacks them too), 1 not comparable.

Built + Partial share by area:

| Area | Built + Partial / rows |
| ---- | ---------------------- |
| Automation and cron | 6 / 6 |
| Admin, staff and roles | 10 / 11 |
| Security, 2FA and audit | 9 / 10 |
| Client area, themes and storefront | 8 / 9 |
| Billing | 21 / 25 |
| Domains and registrars | 13 / 16 |
| Clients and users | 12 / 15 |
| Orders, cart, promotions and fraud | 11 / 14 |
| Support | 15 / 19 |
| Payment gateways | 10 / 13 |
| Products and catalog | 12 / 16 |
| Services and provisioning | 14 / 19 |
| i18n, multi-currency and ops | 9 / 13 |
| Notifications | 7 / 10 |
| API, hooks and extension surface | 5 / 9 |
| Import and migration | 4 / 8 |
| Reports | 3 / 8 |
| Affiliates | 0 / 5 |

The weak areas are affiliates, reports, import and the API. Integrations look complete by row but are thin by count,
and every driver is verified only against our own simulators: 1 control panel against WHMCS's roughly 25 server
modules, 2 registrar drivers (Namecheap not enabled) against 21, and 8 gateways against 63.

The pattern matches the 10-18 study and the CEO review (74/100). The business logic is at or above parity. What
stops a switch is getting data in (invoices, domains, tickets), getting mail out, a version to install, breadth of
panels, and proof against real providers.

## 2. Matrix by area

### 2.1 Clients and users

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Client accounts (company, address, tax ID, status, currency, notes) | Built and tested | `Core/routes` `resource clients`; `pages/admin/clients` | Same |
| Contacts without a login, with email preferences | Built and tested | Platform `contacts` routes | Same |
| One login managing several client accounts (users ↔ clients) | Built and tested | `Client::users()` pivot with `is_owner`, `permissions`; `account/switch` | Same. WHMCS 8 added the same model |
| Staff-side user management (invite, owner, reset link, 2FA reset) | Built and tested | Platform `users/{user}/invitation`, `password-reset`, `reset-two-factor` | Same |
| Client invites and manages its own sub-users in the client area | Missing | `pages/client/account` has only `edit.tsx` | Worse. WHMCS clients manage users and permissions themselves |
| Client groups with discount | Built and tested | `resource client-groups`; `client_group_id` | Same. One group per client in both (WHMCS request "multiple groups", 180 votes) |
| Merge clients | Built and tested | `merge`, `merge-preview`, `ClientMergeRegistry` | Better. Preview first, and every module registers what it moves |
| Close and reopen an account | Built and tested | `close`, `reopen` routes | Same |
| Login as client | Built and tested | `impersonate`, `impersonation/{id}`, banner and audit | Better. Audited, with a banner, and step-up for sensitive views |
| Admin client summary page | Built and tested | #481 | Same |
| Client custom fields (sign-up fields) | Missing | No client field table | Worse. Hosts use these for PAN, citizenship number and similar |
| Identity checks (KYC documents) | Built and tested | `app/Modules/Kyc`, #197 | Better. WHMCS has no built-in document review; we have encrypted storage, review rights and a 90-day deletion rule |
| One-off email to a client | Built and tested | `clients/{client}/email` | Same |
| Data export and erasure on request (privacy tools) | Missing | No export or erase action | Worse. WHMCS ships consent and data-retention tools |
| Marketing email consent | Partial | Client notification `preferences`; no marketing opt-in | Worse |

### 2.2 Products and catalog

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Product groups, ordering, visibility, slugs | Built and tested | `groups/reorder`, #478 slug URLs | Same |
| Product types (shared, reseller, server, other) | Built and tested | `ProductType` enum | Same |
| Price per currency × cycle, setup fees | Built and tested | `products/{product}/pricing`, `PriceBook` | Better. Integer minor units, one rounding rule (#457) |
| Cycles monthly to triennial, one-time, free | Built and tested | `BillingCycle` enum | Same |
| Cycles longer than 3 years | Missing | `BillingCycle` stops at triennial | Same. Also missing in WHMCS (request with 306 votes) |
| Configurable options (dropdown, radio, yes/no, quantity) | Built and tested | `option-groups.options`, `ConfigOptionType` | Same |
| Dependent configurable options | Missing | No condition fields | Same. A WHMCS request (365 votes), so a cheap way to be better |
| Product custom fields (text, dropdown, checkbox, textarea, password, link) | Built and tested | `products.fields`, `CustomFieldType` | Same |
| Product addons (own cycles, applicable products) | Built and tested | `resource addons`, `AddonKind` | Same |
| Upgrade and downgrade paths | Built and tested | `products/{product}/upgrades` | Same |
| Stock control | Built and tested | `products/{product}/stock`, `TrackStockReservations` | Same |
| Duplicate product | Built and tested | `products/{product}/duplicate` | Same |
| Free domain with product | Built and tested | `FreeDomain` enum; `FreeDomainRenewalBlocked` event | Same |
| Product bundles | Missing | No bundle model | Worse |
| Bulk price updater (including existing services) | Missing | `catalog:convert-prices` converts currency only | Worse |
| Auto-setup policy (on order, payment, accept, manual) | Built and tested | `AutoSetup` enum | Same |

### 2.3 Orders, cart, promotions and fraud

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Storefront, cart and checkout | Built and tested | Orders `cart/*`, `checkout`; #478 | Better. React cart without page reloads, inside the client area. WHMCS only reached a dynamic cart with Nexus in 9.0 |
| Domain search in the cart | Built and tested | `cart/domains`, `domains/search`, `lookup` | Same |
| Register at checkout | Built and tested | `checkout` with registration | Same |
| Promotion types: percent, fixed, price override, free setup | Built and tested | `PromotionType` | Same |
| Promotion rules: cycles, dates, uses, once per client, new or existing clients, minimum cart, recurring count, upgrades | Built and tested | `promotions` table columns | Same |
| Exclude configurable options from a promotion | Missing | No column for it | Same. Also a WHMCS request (386 votes) |
| Admin order list, accept, cancel, fraud, back to pending, delete, bulk | Built and tested | Orders admin routes | Same |
| Admin places an order for a client | Built and tested | Orders `create` | Same |
| Fraud screening with a rules checker | Built and tested | `Orders/Fraud/RulesFraudChecker` | Same |
| Fraud providers (MaxMind, FraudLabs Pro) | Missing | `FraudScreening::DRIVERS` holds only `none` and `rules` | Worse |
| Captcha on sign-up, checkout and tickets | Missing | No captcha code | Worse. Turnstile is WHMCS's #3 request; WHMCS has reCAPTCHA built in |
| Stale pending orders cancelled, carts pruned | Built and tested | `orders.cancel_stale_pending`, `orders.prune_carts` | Same. WHMCS can also cancel unpaid orders automatically |
| Order number format | Built and tested | `OrderNumbers` contract | Same |
| Paid order activates without staff "Accept" | Partial | #468 sweep: a paid order stays Pending until accepted | Worse |

### 2.4 Billing: invoices, tax, credit, refunds, quotes and dunning

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Invoices: draft, publish, cancel, reopen, bulk | Built and tested | Billing `invoices` routes | Same |
| Immutable issued invoices; changes through credit and debit notes | Built and tested | ADR 0024; #385, #482, #503 | Better. Built from the start. WHMCS made non-draft invoices immutable in 9.0 and added notes in 9.1 |
| Invoice PDF, attached to the email | Built and tested | `invoices/{id}/pdf`; #504 | Same |
| Nepal tax invoice (proforma until paid, PAN blocks, print count) | Built and tested | #369, #473, #482 | Better. WHMCS has no Nepal VAT handling |
| VAT sales register in IRD layout (CSV and PDF) | Built and tested | `vat-register/export`; #480 | Better. Not in WHMCS |
| Tax rules: country and state, 2 levels, compound, inclusive | Built and tested | `resource tax-rules`; `TaxCalculator` | Same |
| EU VAT number check (VIES) and automatic rates | Missing | No VIES client | Worse. WHMCS 9.0 validates VAT numbers for about 29 countries |
| E-invoicing (Peppol; India IRN; Nepal CBMS) | Not planned | Owner 2026-10-22: no CBMS for now | Same. WHMCS's e-invoicing is Belgium only |
| Partial payments, overpayment to credit | Built and tested | `invoices/{id}/payments` | Same |
| Credit balance: add, remove, apply, client add-funds | Built and tested | `credit`, `credit/add-funds`, `CreditLedger` | Same |
| Credit refund to the client | Built and tested | `credit/refund`; #464 | Same |
| Refund to the gateway, with checks and review of unclear results | Built and tested | `transactions/{t}/refund`, `refund/check`, `refund/resolve`; ADR 0026 | Better. An unclear provider result goes to staff review instead of a guess |
| Mass pay several invoices | Built and tested | Client `batches`, `batches/{batch}/pay` | Same |
| Merge invoices | Missing | No merge action | Worse |
| Quotes: create, deliver, accept, decline, convert, PDF | Built and tested | `resource quotes`, client accept/decline | Same |
| Late fees | Built and tested | `billing.late_fees` task | Same |
| Payment reminders before and after due | Built and tested | `billing.reminders` | Same |
| Auto-charge saved methods on the due date | Built and tested | `billing.auto_capture` | Same |
| Auto-cancel unpaid invoices | Built and tested | `billing.auto_cancel` | Same |
| Saved payment methods (tokens only) | Built and tested | Client `pay-methods` routes | Same |
| Disputes and chargebacks | Built and tested | `PaymentReversed`, `PaymentReinstated`, review queue | Better. Reversal and reinstatement are events with a review queue |
| Payment reconciliation and late-payment checks | Built and tested | `billing.payment_reconciliation`, `payment-attempts/*` | Better. A daily re-lookup of payments still in flight is not described in WHMCS's public docs |
| Expenses and balance tracking | Missing | Only a manual transaction type | Same. Also a WHMCS request (280 votes) |
| Invoice balance column | Partial | Balance shown on the invoice page; not checked as a sortable list column | Same |
| Change of billing cycle by the client | Built and tested | `ChangeKind::Cycle`, client `services/{s}/change` | Better. WHMCS's oldest open request (807 votes on 10-18) |

### 2.5 Payment gateways

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Gateway framework: settings, test, logo, broken drivers hidden | Built and tested | `GatewayDriver`, `GatewayRegistry`; `gateways/{g}/test`; #495 | Better. A broken driver is reported and hidden; it does not break the page |
| Gateway log | Built and tested | `gateway-log` | Same |
| Bank transfer and manual payment | Built and tested | `BankTransferDriver`, `ManualDriver` | Same |
| eSewa | Built and tested (simulator only) | `PaymentsNepal/Drivers/EsewaDriver` | Better for Nepal. Not built into WHMCS |
| Khalti | Built and tested (simulator only) | `KhaltiDriver` | Better for Nepal |
| connectIPS | Built and tested (simulator only) | `ConnectipsDriver`, #491 | Better for Nepal |
| NPR cards (NIC Asia Cybersource) | Built and tested (simulator only) | `PaymentsCybersource`, #492 | Better for Nepal |
| Fonepay, IME Pay | Spec only | `payments-nepal.md`; Fonepay waits for a bank spec | — |
| Stripe (cards, wallets, saved methods) | Built and tested (simulator only) | `PaymentsStripe`, #58 | Same |
| Razorpay (UPI, cards) | Built and tested (simulator only) | `PaymentsRazorpay`, #493 | Same. WHMCS needs a third-party module |
| PayPal | Spec only | Roadmap 3.4 | Worse. WHMCS ships PayPal Payments |
| Long tail (about 50 other gateways) | Missing | — | Worse on breadth |
| Credential expiry warnings (certificates, keys) | Built and tested | `billing.gateway_credentials`, `GatewayCredentialExpiring` | Better |

### 2.6 Services and provisioning modules

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Service lifecycle (pending, active, suspended, terminated, cancelled, fraud) | Built and tested | `ServiceStatus`; admin `commands`, `status` | Same |
| Servers and server groups (fill type) | Built and tested | `resource servers`, `server-groups`, `FillType` | Same |
| Renewal invoices ahead of the due date | Built and tested | `services.renewals` | Better. Anchored dates (no month-end drift) and a per-day run log |
| Overdue suspension and termination | Built and tested | `services.suspensions`, `services.terminations` | Same |
| Upgrades and downgrades with proration | Built and tested | `Support/Billing/Prorata`, `services/{s}/change` | Same |
| Cancellation requests (immediate or end of period) with review | Built and tested | `cancellations/*`, `services.cancellations` | Same |
| Client-side cancellation confirmation email | Partial | #146 open | Worse |
| Module command queue with retry, resolve, check | Built and tested | `module-log/queue/{item}/retry`, `resolve`, `check` | Better. Queued with retries; an unclear result is checked, not repeated |
| cPanel/WHM driver (create, suspend, terminate, package, password, usage, SSO) | Built and tested (simulator only) | `Cpanel/CpanelDriver`, #69, #73 | Same on features; Worse until a live WHM run |
| Import existing accounts from a server | Built and tested | `servers/{s}/import/*`, #453, #463 | Better. Review, match, ignore and priced cycles before apply |
| Usage sync (disk and bandwidth) | Built and tested | `services.usage-sync` | Same |
| Usage-based (metric) billing | Missing | No overage billing | Worse |
| Client "Log in to control panel" | Built and tested | `ProvidesServiceSso` | Same |
| DirectAdmin | Missing | Roadmap 3.2 | Worse. Common with Nepal and India hosts |
| Plesk | Missing | Roadmap 3.2 | Worse |
| VPS panels (Virtualizor, SolusVM, Proxmox) | Missing | — | Worse |
| About 20 long-tail modules (SSL, licences, InterWorx and others) | Not planned | Parity study P2 | Worse on breadth |
| Client reveals the service password behind step-up | Built and tested | `password/reveal`; #461 | Better |
| Third-party provisioning drivers | Partial | Kernel contracts (ADR 0009, 0012); no public package API or version check (ADR 0010) | Worse |

### 2.7 Domains and registrars

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| TLD pricing per year count per currency | Built and tested | `tlds/{tld}/prices` | Same |
| Register, transfer, renew | Built and tested | Domains admin and client routes | Same |
| Grace and redemption | Built and tested | `domains.lifecycle` | Same |
| Domain sync (status, expiry) | Built and tested | `domains:sync`, #449 | Same |
| Expiry reminders | Built and tested | `domains.reminders` | Same |
| Client manages nameservers, lock, EPP code, contacts, auto-renew | Built and tested | Client domain routes | Same |
| ID protection | Built and tested | `ManagesIdProtection` | Same |
| DNS management and email forwarding | Missing | Only a flag on `domains` | Worse |
| `.np` (self-registered by the client) | Built and tested | #199, #471, "Self-registered" status (#423) | Better. WHMCS has no `.np` flow |
| ResellerClub | Built and tested (simulator only) | `ResellerClubDriver` | Same |
| Namecheap | Built and tested (simulator only), not enabled | `NamecheapDriver`; sandbox check pending | Same |
| Other registrars (Enom, OpenSRS, CentralNic, about 18 more) | Missing | — | Worse on breadth |
| Domain suggestions (AI name spinning in WHMCS 9.0) | Partial | `lookup` across TLDs; no AI suggestions | Worse |
| Bulk domain actions | Missing | No bulk route | Worse |
| Link a registrar order to a domain, move to another client | Built and tested | `link-order`, `move` | Same. WHMCS admins set the registrar and order on the domain page |
| Registrar drivers from outside packages | Partial | `RegistrarDriverRegistry`; no versioned public contract | Worse |

### 2.8 Support, knowledge base, announcements and network status

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Departments | Built and tested | `resource departments` | Same |
| Tickets with priority, status, assignee, client and service links | Built and tested | Support ticket routes; `Ticket::assignee` | Same |
| Replies, internal notes, edit and delete messages | Built and tested | `replies`, `notes`, `messages/{m}` | Same |
| Attachments with limits and pruning | Built and tested | `attachments/{a}`, `support.prune_attachments` | Same |
| Canned replies with categories | Built and tested | `resource canned-replies` | Same |
| Merge and bulk actions | Built and tested | `tickets/merge`, `tickets/bulk` | Same |
| Guest tickets by email | Built and tested | Public ticket routes (`submitted`) | Same |
| Reply rating | Built and tested | `messages/{m}/rating` | Same |
| Auto-close | Built and tested | `support.auto_close` | Same |
| Staff signature | Built and tested | `signature` | Same |
| Email piping from an inbound provider | Built and tested | `api.php inbound/email/{provider}`, `GenericDriver` | Same |
| Mailbox import (IMAP, POP3, Google and Microsoft OAuth) | Missing | Roadmap 3.6 | Worse. Small hosts use Workspace or 365 mailboxes |
| Spam rules | Built and tested | `spam-rules` | Same |
| Escalation rules | Missing | No escalation model | Worse |
| Scheduled ticket actions | Missing | — | Worse (WHMCS 8.x feature) |
| Ticket tags and watchers | Missing | — | Worse |
| Knowledge base with categories and votes | Built and tested | `resource articles`, `{slug}/vote` | Same |
| Announcements with a feed | Built and tested | `announcements/feed` | Same |
| Network status with updates and resolve | Built and tested | `network/{incident}/updates`, `resolve` | Same |

### 2.9 Notifications, email, templates and webhooks

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Email templates in the database, edit, preview, test, restore default | Built and tested | Notifications `templates/*` incl. `restore` | Better. Restoring a default is one click; preview uses sample data |
| Branded layout on every email | Built and tested | #28, #72, `BrandOutgoingMail` | Better |
| Email log with resend | Built and tested | `emails/{m}/resend` | Same |
| Staff notification routing | Built and tested | `staff-alerts` | Same |
| Client notification preferences | Built and tested | Client `preferences` | Same |
| Signed outbound webhooks with rotate, ping, delivery log and resend | Built and tested | `webhooks/*`, `notifications:retry-webhooks` | Better. WHMCS's public docs describe hooks, not native signed outbound webhooks |
| Mail transport set in Admin (SMTP, Mailgun, SES, Postmark) with test send | Missing | Transport only in `.env` (CEO review §3); #443 | Worse. WHMCS sets this in admin |
| Mass mail and newsletters | Missing | — | Worse |
| SMS and WhatsApp channels | Missing | Roadmap wave 4 | Same. WHMCS has no WhatsApp either; it is its #1 request (563 votes) |
| Per-language template variants | Partial | Spec in `notifications.md`; English only in code | Worse |

### 2.10 Affiliates

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Affiliate accounts and referral links | Spec only | `affiliates.md`; only events in `app/Events/Affiliates` | — |
| Commissions (percent, fixed, recurring, per product) | Spec only | `affiliates.md` | — |
| Clearing delay and reversal on refund | Spec only | `affiliates.md` | — |
| Withdrawals and payouts | Spec only | `affiliates.md` | — |
| Affiliate reports | Spec only | `affiliates.md` | — |

Cut from the near plan by the CEO review of 10-29. That is fine for Nepal day one; a host that pays resellers
commission would be blocked.

### 2.11 Reports

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Admin dashboard (income, outstanding, orders, tickets, health) | Built and tested | `pages/admin/dashboard.tsx`, #41, #445 | Better. Refresh in the background, and a health card |
| Report framework with on-screen paging and CSV/PDF export | Built and tested | `Support/Reports`, ADR 0025, #401 | Same |
| VAT and credit-note registers | Built and tested | `VatRegisterReport`, `TaxNoteRegisterReport` | Better (Nepal) |
| Income by period and gateway | Spec only | `reports.md`; roadmap 3.8 | — |
| Aging / overdue invoices | Spec only | `reports.md` | — |
| MRR, churn, new clients, renewals due | Spec only | `reports.md` | — |
| Ticket and staff performance reports | Missing | — | Worse |
| Devanagari text in report PDFs | Missing | No Devanagari font (known limit, 10-23) | Worse for Nepal |

### 2.12 Admin, staff and roles

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Staff accounts and invitations | Built and tested | Platform `staff`, `staff-invitations` | Same |
| Roles with permissions, wildcards and sensitive grants | Built and tested | `Role`, `PermissionGuard`; sensitive only by exact key or `*` | Better. Finer than WHMCS's role checkboxes |
| Staff 2FA reset | Built and tested | `staff/{s}/reset-two-factor` | Same |
| Global search | Built and tested | `search`; `nav-search.tsx` | Same |
| Activity log | Built and tested | `activity`, `platform.prune_activity_log` | Same |
| Settings areas | Built and tested | `settings/{section}`, #475 | Same |
| Currencies with rates (NRB, ECB) and a review step | Built and tested | `currencies/*`, `NrbRateSource` | Better. Nepal Rastra Bank rates and a staff review before a big jump |
| Setup checklist on the dashboard | Partial | Dashboard to-do widget; no full setup wizard | Same. WHMCS 9.1 added a Setup Tasks widget |
| Staff online list | Missing | — | Worse (minor) |
| To-do list and calendar | Partial | Dashboard to-do widget only | Same |
| Department access for staff | Built and tested | `TicketStaffDirectory`, department assignment | Same |

### 2.13 Automation and cron

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| One daily run in ordered stages | Built and tested | `Automation` stages 5 to 90, 39 task keys | Better. Ordered stages, a lock, and safe re-runs |
| Automation page with history and "run now" | Built and tested | Platform `automation`, `automation/run` | Same. WHMCS also has an automation status page with daily counts |
| Locks and release of a stuck lock | Built and tested | `automation:release-locks`, ADR 0017 | Better |
| Alert when the daily run is late or missed | Partial | Dashboard health card; no alert sent | Better than WHMCS's silent cron, but not yet an alert |
| No HTTP-triggered cron | Built and tested | Scheduler only (`schedule:work`) | Better. WHMCS 9.0 had to block HTTP cron by default |
| Retention and pruning tasks | Built and tested | `*.prune*` tasks, #460 | Better |

### 2.14 API, hooks and extension surface

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Admin API (about 162 actions) | Missing | No `routes/api.php` beyond inbound email; no Sanctum in `composer.json` | Worse. The biggest gap for integrators |
| REST API v2 with headless cart and payment links (WHMCS 9.1) | Missing | — | Worse |
| Hooks (about 339 points) | Partial | 118 domain events in `app/Events`; no veto or modify hooks | Same for after-the-fact hooks; Worse for "before" hooks that change data |
| Outbound webhooks | Built and tested | `WebhookEvents` registry, #405 | Better |
| Addon modules with admin pages | Missing | No addon module loader | Worse |
| Gateway modules as separate packages | Built and tested | `PaymentsNepal`, `PaymentsStripe`, etc., #495 | Same |
| Templates and hooks to change the client area | Partial | Themes with JSON schema; no page slots | Worse for deep changes; Better for safe token-level theming |
| Contract versioning (`API_VERSION`, snapshot check) | Spec only | ADR 0010; not built (#83) | — |
| Readable source | Built and tested | No encoded files | Better. WHMCS ships ionCube-encoded files |

### 2.15 Client area, themes and storefront

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Client dashboard | Built and tested | `pages/client/dashboard.tsx`, #424 | Better. Due-invoice banner and panels |
| Services, domains, invoices, quotes, tickets, credit, notes | Built and tested | `pages/client/*` | Same |
| Client passkeys and 2FA | Built and tested | Fortify, `passkey-endpoints` | Better. WHMCS documents TOTP, Duo and YubiKey; the 9.0 and 9.1 notes add no passkeys |
| Themes: colours, brand, import and export | Built and tested | Themes `appearance/themes/*`, `schema.json` | Better. Safe to upgrade: tokens instead of overridden template files |
| Theme editor | Built and tested | `admin/settings/theme-editor.tsx` | Same |
| Marketing website builder (draft, preview, publish) | Built and tested | Storefront `website/*` | Better. WHMCS needs a separate site |
| Dark mode, phone-width layouts | Built and tested | Design system; #358 | Better |
| Social sign-in (Google) | Spec only | `social-sign-in.md`; no Socialite | Worse. WHMCS ships Google, Facebook and X sign-in |
| Order form templates (several styles) | Partial | One store layout | Worse on choice |

### 2.16 Import and migration

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Client CSV import with mapping, review and undo | Built and tested | Migration `imports/*`, #160 to #163, #184, #201 | Better. Review, re-runs and undo |
| Login invitations after import | Built and tested | `imports/{run}/invitations` | Better |
| Opening balances | Built and tested | `Billing/Import/OpeningBalanceImporter` | Same |
| Service import from WHM | Built and tested | Server import (§2.6) | Same |
| Unpaid invoices and domains import | Spec only | `invoice-domain-import.md`, #512 | — |
| Ticket import | Missing | — | — |
| WHMCS API connector (pull everything from a live WHMCS) | Spec only | `migration.md`; only the simulator exists (#409) | — |
| Import from other systems (Blesta, ClientExec) | Not planned | — | Same. WHMCS ImportAssist covers a few |

### 2.17 Security, 2FA and audit

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| TOTP two-factor | Built and tested | Fortify, `TwoFactorAuthenticationRequest` | Same |
| Passkeys | Built and tested | `passkey-endpoints` | Better |
| Step-up (recent password) for sensitive actions | Built and tested | ADR 0018, `password.recent` | Better |
| Sign-in rate limit and lockout | Built and tested | `LoginLockout` | Same. WHMCS added this in 9.0 |
| Password history and minimum rules | Partial | Length rules only; no history | Worse. WHMCS 9.1 blocks the last 4 passwords |
| Client data scoped per account (IDOR tests) | Built and tested | `tests/Feature/Security`, `ReviewProbes` | Better. WHMCS had an IDOR CVE in May 2026 |
| Log redaction of secrets | Built and tested | ADR 0023, #462 | Better |
| Key rotation and re-encryption | Built and tested | `security:reencrypt`, ADR 0022 | Better |
| Sign-in email change notifies the old address | Built and tested | #393 | Same |
| Admin IP allowlist | Missing | — | Worse |

### 2.18 i18n, multi-currency and ops

| WHMCS feature | Mohar status | Evidence | vs WHMCS |
| ------------- | ------------ | -------- | -------- |
| Several currencies with rates | Built and tested | Platform currencies | Same |
| Nepal formats: lakh grouping, BS date beside AD | Built and tested | #207, #293 | Better (Nepal) |
| Company time zone used everywhere | Built and tested | `CompanyClock`, ADR 0013 | Better. Dates follow the company, not the server |
| Translations (client area and email) | Missing | No `lang/` folder; English only | Worse. WHMCS ships about 20 languages |
| Translation editor and overrides | Missing | — | Worse |
| Installer | Built and tested | `app:install`, `billing/docs/devops/install.md` | Same |
| Docker production stack (PostgreSQL, S3 storage) | Built and tested | `deploy/`, #80, ADR 0004 | Better. Horizontal scale and private object storage |
| Shared-hosting install (cPanel cron mode) | Built and tested | #167, `docs/devops/cpanel.md` | Same |
| Tagged releases | Partial | Release tarball workflow (#412); no tag yet; #406 open | Worse until one is cut |
| Upgrade command with backup and rollback | Missing | Roadmap wave 4 | Worse. WHMCS 9.1 has unattended maintenance updates |
| System health checks | Partial | Dashboard health card; no full System Health page | Worse |
| Migration safety checks | Built and tested | `deploy:check-migrations`, #479 | Better |
| Tracing and metrics (OpenTelemetry, Pulse) | Spec only | ADR 0014; neither package is in `composer.json` | — |

## 3. Better than WHMCS: the evidence, and where it is weak

| # | Claim | Evidence | Strength |
| - | ----- | -------- | -------- |
| 1 | Correct money by construction: integer minor units, anchored renewal dates, safe re-runs | ADR 0002; `Support/Billing/Prorata`; `Automation` stage lock; #457 rounding | Strong. Tested on SQLite and PostgreSQL |
| 2 | Issued invoices are immutable and changed only through numbered credit and debit notes | ADR 0024; #385, #482, #503 | Strong, though no longer unique: WHMCS shipped the same in 9.0 and 9.1 |
| 3 | Nepal VAT: proforma to tax invoice on payment, PAN blocks, IRD sales register | #369, #473, #480 | Strong in code. Weak on sign-off: the accountant questions (CBMS, D2, D6, A1 to A11) are open since 10-19 |
| 4 | Nepal payments built in: eSewa, Khalti, connectIPS, NPR cards | `PaymentsNepal`, `PaymentsCybersource` | Weak until live. Simulator only; no real payment has gone through |
| 5 | Unclear provider results go to review, not to a guess (refunds, payments, module commands) | ADR 0026; `payment-attempts/*`, `module-log/queue/*` | Strong. It answers the WHMCS complaints about double charges and stuck payments |
| 6 | Automation that cannot run twice or out of order: ordered stages, a lock, history, run now, no HTTP cron | Platform `automation`; 39 tasks | Strong. Missed-run alerts are still missing |
| 7 | Security: passkeys, step-up, redaction, IDOR probes, sensitive permissions | ADR 0018, 0023; `tests/Feature/Security` | Strong. No external pentest yet |
| 8 | Signed outbound webhooks with rotate, ping and resend | Notifications `webhooks/*` | Strong |
| 9 | Client billing-cycle change | `ChangeKind::Cycle` | Strong. It was WHMCS's top request on 10-18. It is not in the top 10 on 10-29; its status there was not re-checked |
| 10 | Switching tools: client import with review and undo, WHM account import with review | Migration module; #453, #463 | Medium. Invoices, domains and tickets are not importable yet |
| 11 | Open, readable source; upgrade-safe theming | No encoded files; Themes JSON schema | Strong on readability. Weak on extension depth (no API, no veto hooks) |
| 12 | Modern UI: one design system, dark mode, no page reloads | Inertia + React + shadcn | Medium. No external usability test. WHMCS 9.1 previews a new admin theme (Iris) |

Claims in `product-vision.md` that the code does not support yet:

- "Safe AI built in": no AI package, no `app/Modules/Ai`. Spec only.
- "OpenTelemetry tracing, Laravel Pulse": neither is a dependency.
- "Fast, sub-200 ms p95": the small-profile load test measured p95 424 ms at 40 users, with dashboard p95 5.1 s
  (state.md, #205).
- "Translations" and "WCAG 2.1 AA": English only; no accessibility audit is recorded.

These should be said as "planned", not as "better", until they are built.

## 4. Gaps that would stop a host switching

Sizes: **S** up to 2 engineer-days, **M** 3 to 7, **L** 2 to 4 weeks, **XL** more than a month.

### 4.1 Nepal-first (the first market)

| Rank | Gap | Why it stops a switch | Size |
| ---- | --- | --------------------- | ---- |
| 1 | Import unpaid invoices and domains, with ResellerClub order ids (#338, #372) | Money owed and domains must otherwise be typed in again; the spec exists (#512) | M (5 to 6 days, CEO estimate) |
| 2 | Live proof for eSewa, Khalti, the ResellerClub demo and one real WHM | A failed first wallet payment is the most likely reason to leave; it needs the owner to reverse the "simulators only" decision | S engineering, owner-gated |
| 3 | Mail transport and test send in Admin, fix #443 | Day-1 setup would otherwise need shell access; adding a login 500s when mail is down | S (2 days) |
| 4 | First tagged release, then an upgrade command with backup and rollback | Without a tag there is nothing to install; without an upgrade path there is no safe second version | S for the release; M for the upgrade command |
| 5 | Month-end reports: aging, income by gateway, renewals due, Devanagari PDF font | The owner and the accountant need these every month | M |
| 6 | DirectAdmin driver | A common panel with budget hosts in Nepal and India; a host on DirectAdmin cannot move | M to L |
| 7 | SMS and WhatsApp notices through a local provider | Expected in South Asia; WHMCS's #1 request | M per channel |
| 8 | Client sub-users and client custom fields (PAN, citizenship number) | Agencies manage several clients; hosts collect local IDs at sign-up | M |
| 9 | Captcha (Turnstile) on sign-up, checkout and guest tickets | Open sign-up without one invites spam and card testing | S |
| 10 | Nepali translation of the client area and email | Not a day-one blocker, but expected | L |

Owner to confirm (local practice, not in any public doc):

- Nepali hosts send SMS through a local aggregator (Sparrow SMS or Aakash SMS) with a sender ID registered through
  the aggregator, rather than through Twilio. Before we spec SMS, confirm which provider hosts use.
- Nepali hosts mostly run cPanel or DirectAdmin on their own VPS; Plesk is rare. This decides the panel order after
  cPanel.

### 4.2 Global (India and beyond)

| Rank | Gap | Why | Size |
| ---- | --- | --- | ---- |
| 1 | Public REST API (scoped tokens, OpenAPI, headless cart, payment links) | Integrators, resellers and custom sites need it; WHMCS has about 162 actions and REST v2 | XL |
| 2 | WHMCS API connector importer (services, domains, invoices, tickets from a live install) | The cheapest switch path for a WHMCS host | L |
| 3 | Plesk and a VPS panel (Virtualizor or Proxmox) | Breadth of panels decides the switch for many hosts | L each |
| 4 | PayPal | Expected by global buyers | M |
| 5 | More registrars (Enom, OpenSRS, CentralNic) | ResellerClub and Namecheap cover only part of the market | M each |
| 6 | Mailbox import over IMAP with Google and Microsoft OAuth | Support for hosts without an inbound-mail provider | M |
| 7 | Veto and modify hooks, addon modules with admin pages, versioned contracts | Third-party developers cannot ship drivers safely yet | L |
| 8 | Affiliates | Resellers and referral programmes | M (spec exists) |
| 9 | EU VAT checks (VIES) and India GST e-invoicing | Needed for EU sellers and larger Indian hosts | M each |
| 10 | Fraud providers (MaxMind), privacy tools (export and erase), mass mail | Expected by larger hosts | S to M each |

### 4.3 Top 10 overall, ranked by impact on switching

1. Invoice and domain import (Nepal, M).
2. Live proof of wallets, a registrar and WHM (Nepal, S, owner-gated).
3. Mail transport in Admin and #443 (Nepal, S).
4. First release and a safe upgrade path (both, S then M).
5. Public REST API (global, XL).
6. DirectAdmin, then Plesk and a VPS panel (both, M to L each).
7. Month-end reports pack with a Devanagari font (Nepal, M).
8. WHMCS API connector for services, invoices and tickets (global, L).
9. SMS and WhatsApp notices (Nepal first, M).
10. Client sub-users and client custom fields (both, M).

## 5. What WHMCS users complain about, and whether we avoid it

From `evolution-and-pain-points.md` §3 (sources read 2026-10-18), with the current top requests re-read on 10-29.

| Complaint | Do we avoid it? | Evidence or gap |
| --------- | --------------- | --------------- |
| Licence price rises and per-client tier cliffs | Not decided | Licence and NPR price are open owner decisions |
| Owned licences lost their update path | Not decided | Same owner decision |
| Encoded source blocks customising and debugging | Yes | Readable source |
| Upgrades break add-ons, themes and invoices | Partly | Themes are tokens, not overridden templates, so they survive upgrades. No upgrade command or extension compatibility check yet |
| Cron fails silently: invoices not made, accounts wrongly suspended | Mostly | Scheduler only, ordered stages, per-day log, automation page. No missed-run alert yet |
| Slow with large data | Partly | Indexed queries, strict mode, 50k-client load test. Tail latency at 40 users (dashboard p95 5.1 s) is still open (#205) |
| Dated admin UI, steep learning curve | Yes, not proven | Modern design system; no usability test with a host |
| Bugs linger and features ship thin | Partly | Every PR is reviewed, with CI on SQLite and PostgreSQL. Breadth is thin in integrations |
| Mixed support quality | Not decided | Support model is an owner decision |
| Top requests ignored for years | Partly | Billing-cycle change built. WhatsApp (563), excluding options from promotions (386), Turnstile (369), dependent options (365), expenses (280), multiple client groups (180) are not built. Each is S to M and would be a visible win |
| Security incidents (2012 breach, 2013 SQL injection, 2026 IDOR CVE) | Yes, by design | IDOR probes, step-up, redaction, dependency cooldown. No external audit yet |
| Tax handling wrong or unchecked | Yes for Nepal | Nepal VAT mode and register. No VIES for the EU |
| Payment bugs (uncaptured Stripe payments, double charges) | Yes, by design | Unique provider reference per attempt (#489), review queue for unclear results (ADR 0026), daily re-lookup (#474). Proven only against simulators |
| Vendor upsell inside the product (MarketConnect required in 9.1) | Yes | No marketplace or upsell |
| Hard to leave or switch | Partly | Client and WHM imports are built; invoices, domains and tickets are not |

## 6. Contract changes the gaps imply

This is research only, so nothing here changes a contract. Building the gaps in §4 would touch the shared kernel
in these places. Each needs the CTO's approval under ADR 0008 and 0010:

- Public API: token abilities mapped to permission strings, and an `Idempotency-Key` contract for calls that move
  money.
- Veto and modify hooks: a new extension point next to the events (for example, checkout validation and price
  adjustment), versioned under ADR 0010.
- SMS and WhatsApp: a notification channel contract beside `SystemMailer`.
- Captcha: one verifier contract used by Orders, Support and Auth.
- Mail transport settings: a `mail` settings section and a test-send action. The kernel's `SystemMailer` stays
  as it is.
- Ticket import: a `RecordImporter` for Support (ADR 0019 already allows this).
