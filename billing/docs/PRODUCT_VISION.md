# Product Vision

**Bar: never less than the incumbent (WHMCS) on any core workflow, and clearly better where operators and
their customers feel it every day.** Every module spec, PR and review is measured against this document.

## 1. Parity — core workflows that must be at least as complete

| Area          | Parity means                                                                                                                             | Spec              |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| Catalog       | groups, product types, multi-currency × cycle pricing, setup fees, config options, addons, custom fields, upgrades, stock                | catalog.md        |
| Ordering      | storefront, cart, domains in cart, promotions (all types/restrictions), fraud checks, accept/cancel flows                                | orders.md         |
| Billing       | invoices, taxes (2 levels, compound, inclusive, EU VAT), partial payments, credit, refunds, quotes, late fees, reminders, gateways, PDFs | billing.md        |
| Services      | full lifecycle, provisioning drivers, servers/groups, renewals, overdue suspension/termination, upgrades with proration, cancellations   | services.md       |
| Domains       | TLD pricing, register/transfer/renew, registrar drivers, grace/redemption, expiry reminders, client management                           | domains.md        |
| Support       | departments, tickets, notes, attachments, canned replies, email piping, KB, announcements, network status                                | support.md        |
| Communication | template catalog, merge fields, per-contact preferences, staff notifications, email log                                                  | notifications.md  |
| Growth        | affiliates with commissions/payouts                                                                                                      | affiliates.md     |
| Admin         | staff, roles/permissions, settings, currencies, client groups, activity log, global search, login-as-client                              | platform-admin.md |
| Insight       | dashboard, income/MRR/churn/ticket reports, exports                                                                                      | reports.md        |
| Switching     | native, re-runnable import from WHMCS                                                                                                    | migration.md      |

A workflow is "at parity" only when its spec's MUST acceptance criteria are covered by passing tests on
SQLite **and** PostgreSQL and its pages pass design review.

## 2. Where we are clearly better (differentiators)

1. **Modern UX** — one coherent design system for admin and client area (Inertia + React + shadcn), dark mode,
   keyboard-first admin (⌘K global search), responsive and WCAG 2.1 AA, no full-page reloads.
2. **Correct money by construction** — integer minor units, anchored billing dates (no month-end drift),
   idempotent automation with a per-day run log, double-invoicing guards, adjustment ledger on locked invoices.
3. **Open and inspectable** — readable source (no encoded files), documented contracts/events, signed outbound
   webhooks, typed routes; extensions plug in through registries instead of global hook spaghetti.
4. **Secure by default** — least-privilege staff permissions with wildcard roles, 2FA/passkeys, strict client
   scoping (IDOR-tested), private object storage, signed callbacks, audit log, impersonation with banner + audit,
   dependency pinning and automated security review on every PR.
5. **Safe AI built in** — staff copilot and ticket assist via the Laravel AI SDK with permission-scoped,
   read-only-by-default tools, human confirmation for any change, budgets and full audit (ai.md).
6. **Operable** — OpenTelemetry tracing, structured logs, health checks, Laravel Pulse metrics, queue-based
   provisioning with retries and a visible module log, PostgreSQL + S3-compatible storage for horizontal scale.
7. **Fast** — sub-200 ms p95 for typical admin pages on reference data, no N+1 (enforced by strict mode),
   indexed automation queries, queued side-effects.
8. **Easy to switch to** — WHMCS importer with dry-run, reconciliation report and delta sync before cutover.

## 3. How we hold the bar

- Specs tag requirements MUST/SHOULD/COULD; parity = all MUST items done and tested.
- Every PR: staff review, QA, AI-slop review, plus security review for money/auth/files/AI, and design review
  of screenshots.
- Before each release: an end-to-end walkthrough (order → invoice → payment → provisioning → renewal →
  suspension → ticket) on PostgreSQL with screenshots in `docs/PROGRESS.md`.
