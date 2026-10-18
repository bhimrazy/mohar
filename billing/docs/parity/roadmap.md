# Parity roadmap

Consolidated from the three WHMCS parity studies (2026-10-18). It is deduplicated and grouped into
workstreams in build order:

- [admin-and-client.md](admin-and-client.md): 664 items and 246 pages.
- [developer-architecture.md](developer-architecture.md): API, hooks and module contracts; all 567
  developers.whmcs.com pages.
- [evolution-and-pain-points.md](evolution-and-pain-points.md): 8.x–9.1 features, community complaints and
  competitors.

## Where we stand

- Functional surface: 251 of 287 P0 items are built or partial. Of the rest, 20 are specified, 7 in progress
  and 9 missing.
- Pages: 134 of 246 WHMCS pages have a Mohar equivalent; 102 are missing (12 P0, 49 P1, 41 P2).
- Developer surface: the capability behind 122 of WHMCS's 162 API actions exists, but there is no public API
  yet. Provisioning and registrar contracts are internal to their modules, so outside packages cannot ship a
  driver.
- The pattern: the core business logic is in place. What blocks a host from switching is integrations
  (control panels, registrars, card gateways, mailbox import), the importer, and extension points.

## In flight (wave 2)

Notifications (incl. branded email for every mail), Nepal payments (#26), Currencies (symbols and exchange
rates), Dashboard v2 (incl. the Automation page summary), Product polish sweep 1. Queued: Affiliates,
Registrars, Social sign-in.

## Wave 3: switch blockers (P0)

| #   | Workstream                   | Scope                                                                                                                                                                                                                                                                     | Depends on    |
| --- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------- |
| 3.1 | Kernel extension contracts   | Move the provisioning and registrar driver contracts and DTOs into the shared kernel, with `register()` on their registries; add the specified events (`ModuleCommandSucceeded/Failed`, `OrderPaid`, `InvoicePublished`, `FraudCheckCompleted`, `ServicePackageChanged`). | —             |
| 3.2 | Control panel drivers        | cPanel/WHM first (full lifecycle, usage, SSO, list and import existing accounts), then DirectAdmin, then Plesk. Client-area "Log in to control panel".                                                                                                                    | 3.1           |
| 3.3 | Registrars                   | Prerequisites PR (#12, C1–C5), then the `.np` manual flow, ResellerClub and Namecheap.                                                                                                                                                                                    | 3.1           |
| 3.4 | Global gateways              | Stripe (cards, wallets, saved methods), Razorpay (UPI, RBI-compliant cards), PayPal, on the existing gateway contracts.                                                                                                                                                   | —             |
| 3.5 | Importers                    | WHMCS API importer (`migration.md`, with the extra API actions from developer-architecture.md) and a CSV client import.                                                                                                                                                   | 3.2, 3.3      |
| 3.6 | Mail transport and mailboxes | Mail provider settings (SMTP, Mailgun, SES, Postmark, SendGrid) with test send; support mailbox import over IMAP/POP3, with Google and Microsoft OAuth after.                                                                                                             | Notifications |
| 3.7 | Client users and sign-in     | Client-side user management (invite, permissions, remove), staff "send reset link", social sign-in (Google first), captcha (Turnstile, hCaptcha, reCAPTCHA) on signup, checkout, login and tickets.                                                                       | —             |
| 3.8 | Reports                      | Reports library and CSV exports (aging, tax liability, income, new clients, renewals).                                                                                                                                                                                    | —             |
| 3.9 | Credit and debit notes       | Numbered documents as the only way to change an issued invoice; PDF, admin list, client list.                                                                                                                                                                             | —             |

## Wave 4: expected soon after (P1)

- **Public REST API:** Sanctum tokens whose abilities are permission strings, `/api/v1`, problem-details
  errors, cursor paging, rate limits, audit, OpenAPI; endpoints for the 39 P0 actions first, `Idempotency-Key`
  on money-moving calls; single-use client SSO links.
- **System health and automation watchdog**: alerts when the daily run is late or missed, plus queue, scheduler,
  storage and mail checks. Dashboard v2 covers the Automation page summary.
- **WhatsApp and SMS** notification channels.
- **Safe upgrades:** contract versioning, extension compatibility manifest, upgrade command with backup and
  rollback.
- **E-invoicing and tax checks:** India IRN, Nepal CBMS, live VIES, through a pluggable reporter contract.
- **Translations** (Nepali and Hindi first) and language overrides.
- **Extension depth:** veto and modify hooks (checkout validation, price adjustment, before-send), UI
  extension slots, metric (usage) billing.
- **Catalog and renewals:** dependent configurable options, longer cycles, early renewal, multiple client
  groups.
- Already planned: AI module, observability (OpenTelemetry), Pest browser E2E, security architecture pass.

## Owner decisions

- Licence and price model (for comparison, Blesta sells an owned licence with unlimited clients).
- Support model.
- A published security policy.

## Pace

The codespace runs two to three builders at a time. Waves 3 and 4 are about 20 workstreams, so the order
above is the order of work. Kernel contracts (3.1) go first because control panels, registrars and the
importer depend on them.
