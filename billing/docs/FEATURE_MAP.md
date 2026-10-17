# Feature Map

A clean-room hosting billing & automation platform. Features are derived from the
publicly described feature set of the category (WHMCS, Blesta, FOSSBilling, HostBill
and similar). No third-party source code is used; all schemas, APIs and naming are
our own design.

Each module lives in `app/Modules/<Module>` and is owned by one workstream.

| # | Module | Owns |
|---|--------|------|
| 0 | **Core** | Clients, contacts, users, staff, roles/permissions, currencies, settings, activity log, auth API |
| 1 | **Catalog** | Product groups, products, pricing, configurable options, product addons |
| 2 | **Orders** | Cart, checkout, orders, promotions/coupons, fraud/acceptance flow |
| 3 | **Billing** | Invoices, payments, transactions, gateways, credit, refunds, tax, quotes, late fees, dunning |
| 4 | **Services** | Client services lifecycle, servers, provisioning drivers, upgrades/downgrades, cancellations, usage |
| 5 | **Domains** | TLD pricing, registrations/transfers/renewals, registrar drivers, nameservers, contacts |
| 6 | **Support** | Departments, tickets, replies, attachments, canned replies, knowledge base, announcements, network status |
| 7 | **Notifications** | Email templates, merge fields, event→email routing, staff notifications, outbound webhooks, email log |
| 8 | **Affiliates** | Affiliate accounts, referral tracking, commissions, payouts |
| 9 | **Reports** | Dashboard stats, income, MRR/ARR, overdue, churn, ticket metrics, CSV exports |

---

## 0. Core
- Client accounts: company, name, address, country/state, phone, tax ID, status (active/inactive/closed), currency, language, notes, client groups (with discount %).
- Users ↔ clients many-to-many: a login can manage several client accounts; per-account owner flag and permissions (billing, services, domains, support, profile).
- Contacts (non-login) with email preferences (general, invoice, support, product, domain).
- Staff accounts with roles; permission strings (`module.area.action`) with `*` wildcards.
- Auth API (Sanctum tokens): register, login, logout, me, password change.
- Currencies with exchange rate vs default currency, formatting (prefix/suffix/decimals).
- Key/value settings store with caching (company name, default currency, invoice prefix, automation days, …).
- Activity log (who, what, subject, client, IP).

## 1. Catalog
- Product groups (name, slug, description, ordering, visibility).
- Product types: shared hosting, reseller, VPS/server, other/generic.
- Pricing per currency × billing cycle (monthly, quarterly, semi-annually, annually, biennially, triennially, one-time, free) + setup fee per cycle.
- Stock control, hidden/retired products, welcome email template key, auto-setup policy (on order / on payment / on accept / manual).
- Provisioning binding: driver key + server group + driver-specific config (package name etc.).
- Configurable options (dropdown/radio/quantity/yes-no) with per-cycle per-currency pricing, attached to products via option groups.
- Custom fields per product (text, dropdown, checkbox, textarea) with required/admin-only flags.
- Product addons (recurring, own cycles & pricing, applicable products).
- Upgrade paths (which products can be upgraded/downgraded to).
- `PriceBook` contract: price lookup for product/addon/options by cycle & currency.

## 2. Orders
- Session/token cart: products (+cycle, options, custom fields, domain), addons, domain registrations/transfers/renewals.
- Totals: recurring + setup, discounts, tax preview via Billing.
- Promotions: percent / fixed / price override / free setup; applies to products/cycles; recurring or first-period; usage limits, per-client once, new-clients-only, start/expiry dates, minimum cart value.
- Checkout: creates order, pending services/addons/domains, first invoice; optional new-client registration in same step.
- Order statuses: pending → active / cancelled / fraud; admin accept (run provisioning), cancel, mark fraud, delete.
- Basic fraud scoring hook (pluggable checker, default no-op + simple rules like max pending orders).
- Order number generation, IP capture, notes.

## 3. Billing
- Invoices: number sequence/prefix, draft/unpaid/paid/cancelled/refunded/collections, issue & due date, line items with billable references, subtotal/tax/total in minor units.
- Tax: rules by country/state, two tax levels (compound or not), inclusive/exclusive pricing, client tax-exempt, EU-style reverse charge flag via tax ID.
- Payments: apply payment, partial payments, overpayment → credit, transaction log (gateway, txn id, fees, amount in/out).
- Credit balance ledger: add/remove/apply to invoice; mass-pay.
- Refunds (to gateway or credit), cancellation, write-off.
- Payment gateway driver contract: offline bank transfer, manual/cheque, a sandbox card gateway; capture, refund, webhook/callback handling, stored payment methods (tokenised references only).
- Recurring automation: invoices are produced by Services/Domains via `Invoicer`; Billing handles reminders (before due, overdue 1st/2nd/3rd), late fees (fixed/percent, once), auto-capture of stored methods on due date, overdue status flagging.
- Quotes: draft/delivered/accepted/declined, convert to invoice.
- Invoice PDF rendering.
- Client area: list/view invoices, pay, download PDF, view credit; admin: full CRUD, apply payments, refunds, credit.

## 4. Services
- Service record: client, product, domain/hostname, username/password (encrypted), status (pending/active/suspended/terminated/cancelled/fraud), cycle, amounts, registration date, next due date, next invoice date, server, dedicated IP, config option selections, custom field values.
- Service addons with their own lifecycle.
- Provisioning driver contract: create, suspend, unsuspend, terminate, change package, change password, usage sync, test connection, SSO link (optional). Drivers: `none` (manual), `log` (demo, records calls).
- Servers & server groups (fill-type: least-full / round-robin), max accounts, credentials (encrypted).
- Lifecycle actions via queued jobs with retry & error recording (module command log).
- Renewal invoice generation N days before due (setting), advancing next invoice date; on `InvoicePaid` extend next due date and unsuspend if suspended for non-payment.
- Overdue automation: suspend after X days overdue, terminate after Y days (settings, per-product override).
- Upgrades/downgrades with prorated credit/charge invoice.
- Cancellation requests: immediate or end of billing period, reason; admin review.
- Usage (disk/bandwidth) snapshots.

## 5. Domains
- TLD catalog: per-TLD register/transfer/renew price per year-count per currency, EPP-code required, grace/redemption fees, registrar assignment.
- Domain record: client, name, TLD, status (pending, pending transfer, active, expired, grace, redemption, transferred away, cancelled, fraud), registration date, expiry, next due, auto-renew, registration period, ID protection, DNS management flags, nameservers, lock status.
- Registrar driver contract: check availability, register, transfer, renew, get/set nameservers, lock, EPP code, get/set contacts, sync status & expiry. Driver: `log` (demo).
- Availability lookup endpoint with suggestions across TLDs.
- Renewal invoicing ahead of expiry, renew on payment, expiry/sync automation, expiry reminder events.
- Client area: manage nameservers, auto-renew, lock, get EPP code, contact details.

## 6. Support
- Departments (email, visibility, assigned staff, auto-response).
- Tickets: masked ticket number, subject, department, priority, status (open/answered/customer-reply/on-hold/in-progress/closed), linked client & service, CC, guest tickets by email.
- Replies (client, staff, internal notes), attachments (storage disk, size/type limits).
- Staff assignment, flags, merging, tags, rating of staff replies.
- Predefined (canned) replies with categories.
- Auto-close after X hours inactive (setting).
- Inbound email endpoint (parsed payload → new ticket / reply).
- Knowledge base: categories, articles, votes, views.
- Announcements (published date, visibility).
- Network status / incidents (server, priority, status, start/end).

## 7. Notifications
- Email templates stored in DB: key, type (general/product/invoice/domain/support/admin), subject, body (Markdown/HTML) with `{{ merge.fields }}`, per-language variants, enabled flag, attachments of invoice PDF.
- Listens to shared domain events and sends the corresponding template to client (respecting contact email preferences) and/or staff.
- Email log (to, subject, body, related client, status).
- Outbound webhooks: endpoint subscriptions per event, signed payloads (HMAC), delivery log with retries.
- Admin API to manage templates, preview with sample data, resend.

## 8. Affiliates
- Activate affiliate per client; referral code & link; cookie/referral capture at checkout.
- Commission rules: percent or fixed, one-time or recurring, per product override, payout delay (days pending before clearing).
- Commissions created on `InvoicePaid` for referred services; reversed on refund.
- Balance, withdrawal requests, payout (to credit or manual), history.

## 9. Reports
- Admin dashboard: income today/month/year, outstanding, overdue, new orders, open tickets, active services.
- Income by period & gateway, MRR/ARR by product, new vs churned services, client growth, ticket response times.
- CSV export for each report.
