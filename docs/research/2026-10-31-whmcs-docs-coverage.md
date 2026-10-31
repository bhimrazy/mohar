# WHMCS docs coverage, 2026-10-31

Research only. Product manager agent. Public pages only; all wording ours; nothing copied beyond a few words.
Every source below was read on 2026-10-31. No code was decoded, no licence was touched, and nothing was run
against a WHMCS install. A companion note covers the per-patch change logs:
[`2026-10-31-whmcs-changelogs.md`](2026-10-31-whmcs-changelogs.md).

## 0. Answer in short

- **Page inventories.** docs.whmcs.com: 1,431 pages in the 9.1 sitemap. developers.whmcs.com: 569 pages (484 in its
  `llms.txt` index, plus 85 REST operation pages the index leaves out).
- **Before this pass.** docs: 552 of 1,431 pages cited (38.6%), or 552 of 1,167 once the 264 tag and category index
  pages are set aside (47.3%). Developers: 566 of 569 (99.5%). Every core product section (billing, payments,
  domains, products, orders, clients, servers, support, system, admins, addon modules, getting started) is already
  at 100%. The gap is five areas: troubleshooting (513 pages), customisation recipes (51), installation (15),
  MarketConnect (25) and index pages.
- **After this pass.** All 1,431 docs pages and all 569 developer pages have a verdict. Of the 879 uncited docs
  pages: 9 are (a), 87 are (b), 783 are (c). Of the 3 uncited developer pages: 2 are (a), 1 is (b). Pages I read in
  full or in part: about 120. The rest (mostly provider-specific troubleshooting) are triaged from the section,
  path and title, and the table says so.
- **The finding.** The first parity study missed little. The real new material is the 513-page troubleshooting
  section, which is a catalogue of how WHMCS fails in production, and three REST pages from 9.1 about headless
  payments. Section 4 lists what to build; section 5 lists ideas.

## 1. Method and limits

The request, URL and page-read counts below, and the "24 URLs" figure, are the author's own counts from this session.
No script or list is committed, so they cannot be reproduced from the repository.

- **docs.whmcs.com.** `robots.txt` names `/sitemap.xml`, which lists only 72 top-level pages. Each version has its own
  sitemap: [9.1](https://docs.whmcs.com/9-1/sitemap.xml) lists 1,431 pages (9.0: 1,412; 8.13: 1,368). I used 9.1 as
  the inventory. Pages were matched to our citations ignoring the version prefix, so `.../8-13/payments/x` and
  `.../9-0/payments/x` both count as `payments/x`. Our citations hold 589 docs URLs, 576 distinct after version
  stripping; 24 of those no longer exist at the same path in 9.1 (renamed or removed).
- **developers.whmcs.com.** A single-page app with no sitemap. The index at [`/llms.txt`](https://developers.whmcs.com/llms.txt)
  lists 484 pages and every page also serves as Markdown at `<path>.md`. The 85 REST operation pages (generated from
  the OpenAPI file) are missing from that index, so I found them by following links on the site (a partial crawl of
  about 270 pages, stopped once the index was found). 85 equals the operation count our REST table already records.
- **Fetching.** One request at a time per host with a 0.25 to 1 second pause, about 1,350 requests in total: sitemaps
  and indexes, 16 change logs, 16 release notes, 616 uncited docs pages, 9 developer pages, and two crawls (about 410
  docs pages, 270 developer pages) that I stopped once the sitemap and `llms.txt` were found.
- **Triage.** (a) a feature or behaviour we do not account for; (b) a detail that refines something we have; (c)
  irrelevant to Mohar, with a one-line reason. I read every (a) and (b) page. Provider-specific troubleshooting (a
  gateway or registrar error message) is (c) by rule; I read a sample across providers to confirm the format (Problem,
  Cause, Solution) and that the content is provider quirks. Those pages are marked "(c) ... not individually read".
- **What "covered" means.** A page counts as covered if one of our documents cites it. That says nothing about
  whether the behaviour is built. Status columns live in the parity documents.

## 2. Coverage

### 2.1 docs.whmcs.com (user and admin documentation), 9.1 inventory

| Section | Pages | Cited before | Uncited | (a) | (b) | (c) |
| --- | --- | --- | --- | --- | --- | --- |
| Billing and invoicing | 32 | 31 | 1 | 0 | 1 | 0 |
| Payments (gateways, tax, currencies) | 95 | 95 | 0 | 0 | 0 | 0 |
| Domains | 66 | 66 | 0 | 0 | 0 | 0 |
| Products and services | 49 | 49 | 0 | 0 | 0 | 0 |
| Orders | 12 | 12 | 0 | 0 | 0 | 0 |
| Clients | 47 | 47 | 0 | 0 | 0 | 0 |
| Servers | 41 | 41 | 0 | 0 | 0 | 0 |
| Support | 29 | 29 | 0 | 0 | 0 | 0 |
| System (settings, automation, logs, reports) | 128 | 128 | 0 | 0 | 0 | 0 |
| Admins and security | 21 | 21 | 0 | 0 | 0 | 0 |
| Addon modules | 20 | 20 | 0 | 0 | 0 | 0 |
| Getting started | 7 | 7 | 0 | 0 | 0 | 0 |
| Customization | 52 | 1 | 51 | 1 | 12 | 38 |
| MarketConnect | 25 | 0 | 25 | 0 | 0 | 25 |
| Installation guide | 16 | 1 | 15 | 0 | 1 | 14 |
| Releases | 6 | 2 | 4 | 0 | 3 | 1 |
| Troubleshooting | 515 | 2 | 513 | 8 | 69 | 436 |
| About and mobile app | 6 | 0 | 6 | 0 | 1 | 5 |
| Tag and category index pages | 264 | 0 | 264 | 0 | 0 | 264 |
| **Total** | **1431** | **552** | **879** | **9** | **87** | **783** |

Rows follow the site's own top-level sections; "Payments" includes tax and currencies, "System" includes automation,
logs, reports, localisation and utilities. Troubleshooting splits as: payments 76, domains 62, addon modules 49,
servers 41, authentication 32, support system 30, general 29, email 29, updates 25, database 21, invoices 18, cron 16,
MarketConnect 14, PHP 13, products and services 9, installation 7, orders 7, SSL 7, client area 7, licensing 6,
customisations 5, cloud 3 and 7 single pages.

### 2.2 developers.whmcs.com (developer documentation)

| Section | Pages | Cited before | Uncited |
| --- | --- | --- | --- |
| API reference (`/api-reference`, 162 actions plus the index page) | 163 | 163 | 0 |
| Classes (`/classes`) | 152 | 152 | 0 |
| REST API v2 operations (`/rest-api/reference`) | 86 | 86 | 0 |
| Hooks reference (21 categories) and hooks guides | 26 | 26 | 0 |
| Modules: provisioning | 20 | 20 | 0 |
| Modules: registrar | 17 | 17 | 0 |
| Modules: payment gateway | 16 | 16 | 0 |
| Modules: addon | 11 | 11 | 0 |
| Modules: mail providers | 4 | 4 | 0 |
| Modules: notification providers | 7 | 7 | 0 |
| Modules: overview and getting started | 6 | 6 | 0 |
| Themes and templates | 18 | 18 | 0 |
| Language (`/languages`) | 7 | 7 | 0 |
| Advanced | 11 | 11 | 0 |
| API guides (`/api`) | 8 | 8 | 0 |
| OAuth | 5 | 5 | 0 |
| REST API guides (`/rest-api`) | 8 | 5 | 3 |
| About | 4 | 4 | 0 |
| **Total** | **569** | **566** | **3** |

The developer site has no separate fraud-module or report-module section (fraud modules are under the module
overview; reports are not a module type). The three uncited pages are all REST guides added with 9.1:
[headless Stripe payments](https://developers.whmcs.com/rest-api/headless-stripe-payments),
[rate limits](https://developers.whmcs.com/rest-api/rate-limits) and
[stored payment methods](https://developers.whmcs.com/rest-api/stored-payment-methods).

### 2.3 Before and after

| | Pages | Cited before | With a verdict after |
| --- | --- | --- | --- |
| docs.whmcs.com | 1,431 | 552 (38.6%; 47.3% without index pages) | 1,431 (100%): 9 (a), 87 (b), 783 (c) |
| developers.whmcs.com | 569 | 566 (99.5%) | 569 (100%): 2 (a), 1 (b) |

## 3. Uncited pages and their triage

Every uncited docs page is listed below, collapsed by section. (a) and (b) pages carry a note; (c) pages are grouped
by reason. The three developer pages follow the docs lists.

<details>
<summary>troubleshooting: 513 pages (a 8, b 69, c 436)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `troubleshooting/general-troubleshooting/down-for-maintenance-errors` | a | Upgrade lock shown to clients, API callers, cron and other admins. Section 4 item 7. |
| `troubleshooting/troubleshoot-addon-modules/e-invoicing/e-invoicing-client-data-errors` | a | Per-client data completeness check before an invoice can be exported. Section 4 item 6. |
| `troubleshooting/troubleshoot-addon-modules/e-invoicing/invoice-already-submitted-errors` | a | A sent e-invoice becomes immutable; recipient refusal is a business state needing a new document. Section 4 item 6. |
| `troubleshooting/troubleshoot-addon-modules/e-invoicing/refused-e-invoice-status` | a | A sent e-invoice becomes immutable; recipient refusal is a business state needing a new document. Section 4 item 6. |
| `troubleshooting/troubleshoot-email/sending-aborted-by-hook` | a | Hooks debug switch that logs every hook call; the page shows how a hook blocks an email. We have no listener trace. Section 4 item 5. |
| `troubleshooting/troubleshoot-the-client-area/prices-incorrect-in-cart` | a | Cart option that shows the per-month equivalent of longer cycles. Not in our specs. Section 4 item 4. |
| `troubleshooting/troubleshoot-updates/permanently-failed-automatic-updates` | a | Unattended updates retry twice, then stay failed until an admin acts. Section 4 item 7. |
| `troubleshooting/troubleshoot-updates/update-dry-run-errors` | a | Pre-flight permission check before an update touches files. Section 4 item 7. |
| `troubleshooting/general-troubleshooting/module-command-unknown-errors` | b | Unknown module errors are only diagnosable from the module log; log provider replies. |
| `troubleshooting/general-troubleshooting/server-ip-address-in-logs` | b | Client IP read from the wrong header gives the server IP in logs. Trusted-proxy rule in ADR 0014. |
| `troubleshooting/module-queue` | b | Failed automated actions with retry, dismiss and retry-all. Specified in services section 8. |
| `troubleshooting/system-health` | b | Environment and security checklist plus update availability. Covered by system health in platform-admin and the watchdog in wave 4. |
| `troubleshooting/troubleshoot-addon-modules/importassist/client-import-failed-errors` | b | Batch result counters: processed, failed validation, duplicated. Already in client-import. |
| `troubleshooting/troubleshoot-addon-modules/importassist/import-validation-failures` | b | Batch result counters: processed, failed validation, duplicated. Already in client-import. |
| `troubleshooting/troubleshoot-addon-modules/importassist/missing-imported-clients` | b | Batch result counters: processed, failed validation, duplicated. Already in client-import. |
| `troubleshooting/troubleshoot-authentication/2fa-storage-migration-messages` | b | Migration of two-factor secrets to protected storage runs in the background with a status banner. Relevant to our key-rotation runbook. |
| `troubleshooting/troubleshoot-authentication/cloudflare-proxy-check-errors` | b | Behind a CDN, client IP and session rely on trusted-proxy settings; flagged in system health. Covered in ADR 0014. |
| `troubleshooting/troubleshoot-authentication/expired-admin-invite-links` | b | Admin invites last seven days; resending or deleting invalidates the old link. Copy these rules. |
| `troubleshooting/troubleshoot-authentication/frequent-logouts` | b | Frequent logouts trace to session storage, cleanup settings and proxies. |
| `troubleshooting/troubleshoot-authentication/oops-too-many-requests-errors` | b | Ten failed client logins in ten minutes lock out the source. Compare with platform-admin throttle. |
| `troubleshooting/troubleshoot-authentication/password-rejected-errors` | b | Password re-hashing happens at first login and requires the stored password to meet the policy. |
| `troubleshooting/troubleshoot-authentication/password-reuse-depth-out-of-range` | b | Password history depth is clamped to 4 to 40. Compare with platform-admin password history. |
| `troubleshooting/troubleshoot-authentication/remember-me-session-ended` | b | Remembered admin sessions last 30 days. |
| `troubleshooting/troubleshoot-authentication/sessions-are-not-removed` | b | Frequent logouts trace to session storage, cleanup settings and proxies. |
| `troubleshooting/troubleshoot-authentication/sso-closed-user-errors` | b | Single sign-on is refused for closed clients, allowed for active and inactive. |
| `troubleshooting/troubleshoot-cron-issues/cron-metrics-polling-timeout` | b | Usage polling can time out and stop the run midway; each step needs its own time budget and log line. |
| `troubleshooting/troubleshoot-cron-issues/cron-system-queue-timeout` | b | The backup step inside the daily run grows with the database and can stall the run. Keep backups out of the billing run. |
| `troubleshooting/troubleshoot-cron-issues/cron-time-of-day-issues` | b | Daily run did not start in its configured hour: warning and email. Our automation watchdog does the same. |
| `troubleshooting/troubleshoot-cron-issues/intermittent-cron-failures` | b | Intermittent failures need per-run logs; we log every run in automation_runs. |
| `troubleshooting/troubleshoot-cron-issues/system-timezone-issues` | b | Timezone mismatches between PHP, database and cron skew timestamps in imported mail. We use company timezone and UTC storage. |
| `troubleshooting/troubleshoot-domains/domain-availability-errors` | b | Availability checks fail on registry timeouts or give wrong answers; the cart must show unknown, not available. |
| `troubleshooting/troubleshoot-domains/free-domain-did-not-renew` | b | Renewal of a free domain is suppressed when its product is gone and the setting is on. Our FreeDomainRenewalBlocked event covers it. |
| `troubleshooting/troubleshoot-domains/free-domain-is-not-free` | b | Free-domain discount needs the right TLD, product and cycle together. Domains section 4.3. |
| `troubleshooting/troubleshoot-domains/missing-registrant-contact-errors` | b | Registrar rejects an order if the registrant contact is incomplete. Domains section 11. |
| `troubleshooting/troubleshoot-domains/registration-suppressed-errors` | b | Auto-registration skipped for new clients (when set) or when the domain is already marked active. Built as orders.auto_provision_only_existing. |
| `troubleshooting/troubleshoot-domains/resellerclub/resellerclub-pending-actions` | b | A contact change at the registry holds the domain for confirmation of up to a day. Show a pending state. |
| `troubleshooting/troubleshoot-domains/sync-not-supported-errors` | b | Registrar without sync support: do nothing and say so. Registrar contract has a capability flag. |
| `troubleshooting/troubleshoot-domains/transip-update-delays` | b | Some registrars apply changes minutes later; block follow-up changes meanwhile. Covered by ADR 0026 indeterminate outcomes. |
| `troubleshooting/troubleshoot-email/broken-renewal-links-in-emails` | b | Renewal links wrong when the cron runs under a different PHP binary; our links come from APP_URL. |
| `troubleshooting/troubleshoot-email/email-template-syntax-errors` | b | Template syntax errors surface in the system log. Our templates are validated on save. |
| `troubleshooting/troubleshoot-email/failed-recipients` | b | One bad address anywhere (profile, contact, BCC, copy-to) fails the whole send. Validate on save. |
| `troubleshooting/troubleshoot-email/unsent-ticket-notifications` | b | Staff ticket mail depends on the staff flag, role and department settings. |
| `troubleshooting/troubleshoot-email/unsent-welcome-emails` | b | Welcome mail missing usually means account creation failed first. |
| `troubleshooting/troubleshoot-invoices/cancelled-domain-renewal-invoices` | b | Expired domain or a new renewal order cancels the open renewal invoice. Matches domains section 4.5. |
| `troubleshooting/troubleshoot-invoices/duplicate-invoices-different-dates` | b | Importing services with a blank or zero next due date makes the invoice job catch up with one invoice per missed period. Importer guard, section 4 item 3. |
| `troubleshooting/troubleshoot-invoices/duplicate-invoices-same-dates` | b | Two cron processes at once produce twin invoices and double reminders. Covered by our unique-index and run-lock design. |
| `troubleshooting/troubleshoot-invoices/invoice-immutability-errors` | b | Warning shown when immutability is switched off. We keep immutability on with no off switch. |
| `troubleshooting/troubleshoot-invoices/invoices-are-missing-tax` | b | Checklist of why tax is missing: tax settings, per-product flag, client exemption, state rules. Use as acceptance cases in billing section 5. |
| `troubleshooting/troubleshoot-invoices/missing-domain-renewal-invoices` | b | Domain renewal invoice not created: auto-renew off, status, already invoiced, cron. Matches domains section 4. |
| `troubleshooting/troubleshoot-invoices/missing-payment-reminders` | b | Reminder gaps come from reminder settings, missing template, or a missed daily run. |
| `troubleshooting/troubleshoot-invoices/missing-service-invoices` | b | Domain renewal invoice not created: auto-renew off, status, already invoiced, cron. Matches domains section 4. |
| `troubleshooting/troubleshoot-invoices/unexpected-invoice-details` | b | Client details on an invoice depend on the snapshot setting and the billing contact. Already specified. |
| `troubleshooting/troubleshoot-invoices/unexpected-next-due-date` | b | Marking an invoice paid more than once moves the next due date forward each time. Our paid transition is idempotent; keep the test. |
| `troubleshooting/troubleshoot-orders/invalid-registration-period-errors` | b | Ordering a TLD or period that is not priced fails; cart must hide unpriced periods. |
| `troubleshooting/troubleshoot-payments/duplicate-transactions` | b | Two cron processes at once produce twin invoices and double reminders. Covered by our unique-index and run-lock design. |
| `troubleshooting/troubleshoot-payments/exchange-rate-update-failures` | b | Rate refresh fails for a currency the feed does not carry; manual base rate is the fallback. Check NPR handling in platform-admin. |
| `troubleshooting/troubleshoot-payments/invalid-vat-format-errors` | b | VAT number format and live-registry checks at checkout, with a UK mode that can deny all. See billing section 5; Nepal PAN/VAT format is ours to define. |
| `troubleshooting/troubleshoot-payments/invalid-vat-number-errors` | b | VAT number format and live-registry checks at checkout, with a UK mode that can deny all. See billing section 5; Nepal PAN/VAT format is ours to define. |
| `troubleshooting/troubleshoot-payments/paypal/orphaned-paypal-subscriptions` | b | Subscription at the provider with no matching service. Relevant if we ever use provider-side subscriptions; today we invoice ourselves. |
| `troubleshooting/troubleshoot-payments/stripe/stripe-and-rbi-regulations` | b | RBI rules forbid storing card credentials without tokenisation consent; relevant for later India support, Razorpay wave 3.4. |
| `troubleshooting/troubleshoot-payments/unexplained-declines-and-failures` | b | Decline diagnosis starts at the gateway log. Our PaymentFailed carries the provider code. |
| `troubleshooting/troubleshoot-payments/unrecorded-transactions` | b | Callbacks not reaching the app are the usual cause of unrecorded payments. Our daily gateway reconciliation covers the gap. |
| `troubleshooting/troubleshoot-products-and-services/on-demand-renewal-unsupported` | b | Eligibility list for client-initiated early renewal: recurring, active, no unpaid invoice, within the window, no metered billing. Services section 4. |
| `troubleshooting/troubleshoot-products-and-services/service-not-prorated` | b | Signup-anniversary prorata silently overrides product prorata. Make the precedence explicit in orders section 5. |
| `troubleshooting/troubleshoot-products-and-services/unexpected-service-suspensions` | b | Lists the only causes of suspension and termination: overdue days, fixed term, admin action. Good basis for an explain-why panel. |
| `troubleshooting/troubleshoot-products-and-services/unexpected-service-terminations` | b | Lists the only causes of suspension and termination: overdue days, fixed term, admin action. Good basis for an explain-why panel. |
| `troubleshooting/troubleshoot-servers/account-creation-failures` | b | Failed provisioning lands in a retry queue with the error shown. Services section 8. |
| `troubleshooting/troubleshoot-servers/server-deletion-errors` | b | A server cannot be deleted while any service points to it, even cancelled or terminated ones. Check services section 9. |
| `troubleshooting/troubleshoot-servers/status-monitoring-display-issues` | b | Uptime and load display depends on the OS and status endpoint. Skip. |
| `troubleshooting/troubleshoot-the-support-system/missing-support-notifications` | b | Staff notification gaps trace to staff email, role and department settings. |
| `troubleshooting/troubleshoot-the-support-system/scheduled-action-failures` | b | A scheduled ticket action retries for 60 minutes, then is marked failed and not retried. Decide our window in support section 11. |
| `troubleshooting/troubleshoot-the-support-system/ticket-replies/auto-submitted-header-errors` | b | Mail marked auto-submitted is not added to tickets, following the RFC for auto-responders. Already in support section 6. |
| `troubleshooting/troubleshoot-the-support-system/ticket-replies/duplicate-imported-replies` | b | Two departments on one mailbox import every reply twice. Add a uniqueness check to support section 6. |
| `troubleshooting/troubleshoot-the-support-system/ticket-replies/email-loop-errors` | b | Blocks a sender after 10 mails in 15 minutes. Check support section 6 thresholds. |
| `troubleshooting/troubleshoot-the-support-system/ticket-replies/pop3-cron-not-running` | b | Warning that POP3 is configured but the import job is not running. Our system-health watchdog should include it. |
| `troubleshooting/troubleshoot-updates/guardedforupdate-errors` | b | Update path guards: guarded settings model, channel selection, GuardedForUpdate errors. Self-hosted only; note for the upgrade runbook. |
| `troubleshooting/troubleshoot-updates/update-channel-errors` | b | Update path guards: guarded settings model, channel selection, GuardedForUpdate errors. Self-hosted only; note for the upgrade runbook. |

**(c) Self-hosted PHP, database, installer or WHMCS licensing operations; no Mohar counterpart** (88 pages): `general-404-page-not-found-errors`; `general-admin-directory-errors`; `general-blank-admin-dashboard`; `general-blank-or-partially-rendered-pages`; `general-character-display-in-reports`; `general-corrupt-file-errors`; `general-curl-couldnt-connect-to-host-errors`; `general-curl-couldnt-resolve-host-errors`; `general-curl-timeout-errors`; `general-dataset-must-be-array-errors`; `general-duplicate-system-theme-options`; `general-empty-pages`; `general-failed-opening-errors`; `general-file-cannot-be-decoded-errors`; `general-file-not-found-errors`; `general-filename-not-allowed-errors`; `general-general-troubleshooting`; `general-impossible-root-errors`; `general-logicexception-template-errors`; `general-missing-country-customizations`; `general-oops-errors`; `general-query-string-uri-errors`; `general-redirect-loops`; `general-server-communication-errors`; `troubleshoot-installation/administrator-password-refused-errors`; `troubleshoot-installation/duplicate-tblconfiguration-errors`; `troubleshoot-installation/license-key-required-errors`; `troubleshoot-installation/refused-installation-errors`; `troubleshoot-installation/stuck-installation-process`; `troubleshoot-licensing/invalid-license-api-errors`; `troubleshoot-licensing/invalid-license-errors`; `troubleshoot-licensing/invalid-licenses-on-hostgator`; `troubleshoot-licensing/no-connection-errors`; `troubleshoot-php/500-internal-server-errors`; `troubleshoot-php/allowed-memory-exhausted-errors`; `troubleshoot-php/exceptionloggingtrait-errors`; `troubleshoot-php/opcache-warnings`; `troubleshoot-php/php-execution-time-errors`; `troubleshoot-php/php-tag-errors`; `troubleshoot-php/required-function-errors`; `troubleshoot-php/segmentation-fault-errors`; `troubleshoot-php/smarty-variable-errors`; `troubleshoot-php/typeerror-convertinisize-errors`; `troubleshoot-php/typeerror-guessfileextension-errors`; `troubleshoot-ssl/certificate-not-detected-errors`; `troubleshoot-ssl/certificate-subject-mismatch-errors`; `troubleshoot-ssl/insecure-connection-ssl-errors`; `troubleshoot-ssl/invalid-or-corrupt-certificate-errors`; `troubleshoot-ssl/outdated-root-ca-bundles`; `troubleshoot-the-database/argument-pdostatement-errors`; `troubleshoot-the-database/base-table-or-view-not-found-errors`; `troubleshoot-the-database/column-group-id-null-errors`; `troubleshoot-the-database/command-denied-errors`; `troubleshoot-the-database/could-not-connect-errors`; `troubleshoot-the-database/duplicate-entry-errors`; `troubleshoot-the-database/field-default-value-errors`; `troubleshoot-the-database/large-database-backup-failures`; `troubleshoot-the-database/missing-zip-extension-errors`; `troubleshoot-the-database/mysql-server-gone-away-errors`; `troubleshoot-the-database/order-not-found-errors`; `troubleshoot-the-database/pop3-general-error-3988-errors`; `troubleshoot-the-database/sftp-backup-login-errors`; `troubleshoot-the-database/sftp-backup-uploading-errors`; `troubleshoot-the-database/sftp-unable-to-open-errors`; `troubleshoot-the-database/sftp-unable-to-write-errors`; `troubleshoot-the-database/table-doesnt-exist-errors`; `troubleshoot-the-database/unable-to-connect-errors`; `troubleshoot-the-database/valid-dump-path-errors`; `troubleshoot-updates/application-configuration-errors`; `troubleshoot-updates/automatic-update-connectivity-errors`; `troubleshoot-updates/automatic-update-permission-errors`; `troubleshoot-updates/automatic-update-version-data-errors`; `troubleshoot-updates/automatic-update-version-unavailable-errors`; `troubleshoot-updates/automatic-updates-unavailable`; `troubleshoot-updates/check-for-updates-failures`; `troubleshoot-updates/encryption-hash-errors`; `troubleshoot-updates/fatal-error-fetching-update`; `troubleshoot-updates/feature-issues-after-update`; `troubleshoot-updates/file-download-errors`; `troubleshoot-updates/legacy-module-removal-errors`; `troubleshoot-updates/legacy-paypal-module-errors`; `troubleshoot-updates/network-issue-errors`; `troubleshoot-updates/new-version-not-appearing`; `troubleshoot-updates/no-existing-installation-errors`; `troubleshoot-updates/update-failed-errors`; `troubleshoot-updates/update-incomplete-errors`; `troubleshoot-updates/update-message-validation-errors`; `troubleshoot-whmcs-cloud/missing-features-on-whmcs-cloud`

**(c) Registrar-specific error message and fix (provider quirk)** (52 pages): `troubleshoot-domains/blank-choose-a-domain-page`; `troubleshoot-domains/blank-domain-search-results`; `troubleshoot-domains/centralnic-reseller`; `troubleshoot-domains/centralnic-reseller/entity-reference-errors`; `troubleshoot-domains/domain-management-oops-errors`; `troubleshoot-domains/domain-pricing-will-not-save`; `troubleshoot-domains/enom`; `troubleshoot-domains/enom/bad-user-name-or-password-errors`; `troubleshoot-domains/enom/enom-api-curl-errors`; `troubleshoot-domains/enom/enom-domain-not-found-errors`; `troubleshoot-domains/enom/enom-empty-response-errors`; `troubleshoot-domains/enom/enom-invalid-data-errors`; `troubleshoot-domains/enom/enom-renewal-balance-failures`; `troubleshoot-domains/enom/enom-user-not-permitted-errors`; `troubleshoot-domains/godaddy`; `troubleshoot-domains/godaddy/godaddy-request-body-errors`; `troubleshoot-domains/hexonet`; `troubleshoot-domains/hexonet/hexonet-disconnections-and-failures`; `troubleshoot-domains/hexonet/hexonet-empty-response-errors`; `troubleshoot-domains/missing-contact-details-errors`; `troubleshoot-domains/namecheap`; `troubleshoot-domains/namecheap/namecheap-nameserver-errors`; `troubleshoot-domains/namecheap/namecheap-registrar-errors`; `troubleshoot-domains/no-nameservers-are-defined-errors`; `troubleshoot-domains/nominet`; `troubleshoot-domains/nominet/nominet-command-syntax-errors`; `troubleshoot-domains/nominet/nominet-connection-errors`; `troubleshoot-domains/nominet/nominet-socket-transport-errors`; `troubleshoot-domains/onlinenic`; `troubleshoot-domains/onlinenic/onlinenic-connection-errors`; `troubleshoot-domains/opensrs`; `troubleshoot-domains/opensrs/opensrs-connection-errors`; `troubleshoot-domains/opensrs/opensrs-domain-renewed-errors`; `troubleshoot-domains/opensrs/opensrs-order-errors`; `troubleshoot-domains/openssl-registrar-errors`; `troubleshoot-domains/resellerclub`; `troubleshoot-domains/resellerclub/resellerclub-access-errors`; `troubleshoot-domains/resellerclub/resellerclub-connection-errors`; `troubleshoot-domains/resellerclub/resellerclub-language-code-errors`; `troubleshoot-domains/resellerclub/resellerclub-locked-errors`; `troubleshoot-domains/resellerclub/resellerclub-login-errors`; `troubleshoot-domains/resellerclub/resellerclub-missing-name-errors`; `troubleshoot-domains/resellerclub/resellerclub-telephone-errors`; `troubleshoot-domains/resellerclub/resellerclub-unexpected-errors`; `troubleshoot-domains/resellerclub/resellerclub-unknown-fault-errors`; `troubleshoot-domains/resellerclub/resellerclub-website-errors`; `troubleshoot-domains/typeerror-additionalfields-errors`; `troubleshoot-domains/unable-to-display-pricing-errors`; `troubleshoot-domains/webnic`; `troubleshoot-domains/webnic/webnic-ip-address-errors`; `troubleshoot-domains/webnic/webnic-login-errors`; `troubleshoot-domains/webnic/webnic-partner-failures`

**(c) Section index page** (47 pages): `troubleshooting`; `general-troubleshooting`; `troubleshoot-addon-modules`; `troubleshoot-addon-modules/troubleshooting-addon-modules`; `troubleshoot-authentication`; `troubleshoot-authentication/troubleshooting-authentication`; `troubleshoot-cron-issues`; `troubleshoot-cron-issues/troubleshooting-the-system-cron`; `troubleshoot-customizations`; `troubleshoot-customizations/troubleshooting-customizations`; `troubleshoot-customizations/troubleshooting-hooks`; `troubleshoot-customizations/troubleshooting-system-themes`; `troubleshoot-customizations/troubleshooting-whmcs-api-calls`; `troubleshoot-domains`; `troubleshoot-domains/troubleshooting-domains`; `troubleshoot-email`; `troubleshoot-email/troubleshooting-system-email`; `troubleshoot-installation`; `troubleshoot-installation/troubleshooting-installation`; `troubleshoot-invoices`; `troubleshoot-invoices/troubleshooting-invoices`; `troubleshoot-licensing`; `troubleshoot-licensing/troubleshooting-licensing`; `troubleshoot-marketconnect`; `troubleshoot-marketconnect/troubleshooting-marketconnect`; `troubleshoot-orders`; `troubleshoot-orders/troubleshooting-orders`; `troubleshoot-payments`; `troubleshoot-payments/troubleshooting-payments`; `troubleshoot-php`; `troubleshoot-php/troubleshooting-php`; `troubleshoot-products-and-services`; `troubleshoot-products-and-services/troubleshooting-products-and-services`; `troubleshoot-servers`; `troubleshoot-servers/troubleshooting-server-issues`; `troubleshoot-ssl`; `troubleshoot-ssl/troubleshooting-ssl`; `troubleshoot-the-client-area`; `troubleshoot-the-client-area/troubleshooting-the-client-area`; `troubleshoot-the-database`; `troubleshoot-the-database/troubleshooting-the-database`; `troubleshoot-the-support-system`; `troubleshoot-the-support-system/troubleshooting-the-support-system`; `troubleshoot-updates`; `troubleshoot-updates/troubleshooting-updates`; `troubleshoot-whmcs-cloud`; `troubleshoot-whmcs-cloud/troubleshooting-whmcs-cloud`

**(c) Gateway-specific error message and fix (provider quirk)** (43 pages): `troubleshoot-payments/2checkout/2checkout-inline-checkout-crash`; `troubleshoot-payments/2checkout/authentication-failed-errors`; `troubleshoot-payments/2checkout/pe101-errors`; `troubleshoot-payments/authorize-net/field-is-required-errors`; `troubleshoot-payments/bitpay/bitpay-token-errors`; `troubleshoot-payments/bitpay/bitpay-token-migration-alerts`; `troubleshoot-payments/bitpay/missed-bitpay-notifications`; `troubleshoot-payments/eway/missing-card-fields`; `troubleshoot-payments/gocardless/gocardless-inactive-token-errors`; `troubleshoot-payments/gocardless/gocardless-remote-storage-errors`; `troubleshoot-payments/ideal-via-mollie/ideal-via-mollie-transaction-errors`; `troubleshoot-payments/paypal/duplicate-invoice-id-errors`; `troubleshoot-payments/paypal/local-card-errors`; `troubleshoot-payments/paypal/missing-paypal-payments`; `troubleshoot-payments/paypal/missing-paypal-subscriptions`; `troubleshoot-payments/paypal/paypal-invalid-receiver-errors`; `troubleshoot-payments/paypal/paypal-ipn-failures`; `troubleshoot-payments/paypal/paypal-log-entries`; `troubleshoot-payments/paypal/paypal-not-supported-errors`; `troubleshoot-payments/paypal/paypal-not-working-errors`; `troubleshoot-payments/paypal/paypal-old-transaction-errors`; `troubleshoot-payments/paypal/paypal-security-errors`; `troubleshoot-payments/paypal/paypal-ssl-errors`; `troubleshoot-payments/paypal/paypal-transaction-id-errors`; `troubleshoot-payments/paypal/paypal-unrecognized-currency-errors`; `troubleshoot-payments/paypal/remote-storage-errors`; `troubleshoot-payments/paypal/unexpected-paypal-cancellation-emails`; `troubleshoot-payments/skrill/skrill-secret-word-errors`; `troubleshoot-payments/stripe-dynamic-payments/missing-stripe-payment-options`; `troubleshoot-payments/stripe-dynamic-payments/stripe-add-payment-method-errors`; `troubleshoot-payments/stripe/no-stripe-customer-details-errors`; `troubleshoot-payments/stripe/stripe-ach-general-errors`; `troubleshoot-payments/stripe/stripe-ach-invalid-token-errors`; `troubleshoot-payments/stripe/stripe-bad-request-errors`; `troubleshoot-payments/stripe/stripe-cvc-value-errors`; `troubleshoot-payments/stripe/stripe-network-errors`; `troubleshoot-payments/stripe/stripe-payment-method-errors`; `troubleshoot-payments/stripe/stripe-pci-compliance-issues`; `troubleshoot-payments/stripe/stripe-remote-transaction-failures`; `troubleshoot-payments/stripe/stripe-statement-descriptor-errors`; `troubleshoot-payments/stripe/stripe-try-again-errors`; `troubleshoot-payments/worldpay/payment-response-password-errors`; `troubleshoot-payments/worldpay/worldpay-invisible-nmtoken-errors`

**(c) WHMCS-bundled addon (licensing, live chat, partner portals, theme editor); not a Mohar feature** (41 pages): `troubleshoot-addon-modules/addon-module-oops-errors`; `troubleshoot-addon-modules/e-invoicing`; `troubleshoot-addon-modules/e-invoicing/e-invoicing-account-sync-errors`; `troubleshoot-addon-modules/e-invoicing/e-invoicing-configuration-errors`; `troubleshoot-addon-modules/e-invoicing/e-invoicing-invoice-export-errors`; `troubleshoot-addon-modules/e-invoicing/e-invoicing-license-errors`; `troubleshoot-addon-modules/e-invoicing/e-invoicing-status-update-errors`; `troubleshoot-addon-modules/importassist`; `troubleshoot-addon-modules/importassist/cloud-importassist-errors`; `troubleshoot-addon-modules/importassist/importassist-unable-to-connect-errors`; `troubleshoot-addon-modules/live-chat`; `troubleshoot-addon-modules/live-chat/live-chat-connection-errors`; `troubleshoot-addon-modules/live-chat/live-chat-display-issues`; `troubleshoot-addon-modules/live-chat/live-chat-login-and-license-errors`; `troubleshoot-addon-modules/live-chat/live-chat-server-or-host-errors`; `troubleshoot-addon-modules/live-chat/live-chat-smtp-errors`; `troubleshoot-addon-modules/missing-addon-modules`; `troubleshoot-addon-modules/nova-cloud-prompt-first`; `troubleshoot-addon-modules/nova-cloud-prompt-first/nova-cloud-deep-link-failures`; `troubleshoot-addon-modules/nova-cloud-prompt-first/nova-cloud-license-external-id-errors`; `troubleshoot-addon-modules/software-licensing`; `troubleshoot-addon-modules/software-licensing/licensing-invalid-key-errors`; `troubleshoot-addon-modules/software-licensing/licensing-local-key-generation`; `troubleshoot-addon-modules/software-licensing/licensing-remote-check-errors`; `troubleshoot-addon-modules/theme-editor`; `troubleshoot-addon-modules/theme-editor/theme-editor-creation-errors`; `troubleshoot-addon-modules/theme-editor/theme-editor-deletion-errors`; `troubleshoot-addon-modules/translation-editor`; `troubleshoot-addon-modules/translation-editor/translation-editor-content-errors`; `troubleshoot-addon-modules/webpros-partner`; `troubleshoot-addon-modules/webpros-partner/cloud-service-credential-errors`; `troubleshoot-addon-modules/webpros-partner/cpanel-license-assigned-errors`; `troubleshoot-addon-modules/webpros-partner/cpanel-license-configurable-option-errors`; `troubleshoot-addon-modules/webpros-partner/cpanel-license-deactivated-errors`; `troubleshoot-addon-modules/webpros-partner/cpanel-license-missing-ip-address`; `troubleshoot-addon-modules/webpros-partner/cpanel-license-reactivation-errors`; `troubleshoot-addon-modules/webpros-partner/cpanel-licensing-options-errors`; `troubleshoot-addon-modules/webpros-partner/nova-locale-and-language-mismatch-errors`; `troubleshoot-addon-modules/webpros-partner/product-temporarily-unavailable-errors`; `troubleshoot-addon-modules/webpros-partner/riva-login-errors`; `troubleshoot-addon-modules/webpros-partner/webpros-partner-provisioning-errors`

**(c) cPanel or Plesk error message; panel quirks live in provisioning-cpanel** (35 pages): `troubleshoot-servers/406-not-acceptable-errors`; `troubleshoot-servers/cpanel-servers/access-denied-errors`; `troubleshoot-servers/cpanel-servers/action-failed-errors`; `troubleshoot-servers/cpanel-servers/group-already-exists-errors`; `troubleshoot-servers/cpanel-servers/invalid-cpmod-errors`; `troubleshoot-servers/cpanel-servers/invalid-numeric-value-errors`; `troubleshoot-servers/cpanel-servers/license-profile-errors`; `troubleshoot-servers/cpanel-servers/login-failed-errors`; `troubleshoot-servers/cpanel-servers/missing-username-errors`; `troubleshoot-servers/cpanel-servers/missing-whm-packages`; `troubleshoot-servers/cpanel-servers/missing-whm-username-errors`; `troubleshoot-servers/cpanel-servers/package-not-allowed-errors`; `troubleshoot-servers/cpanel-servers/package-permission-errors`; `troubleshoot-servers/cpanel-servers/product-attribute-errors`; `troubleshoot-servers/cpanel-servers/reseller-subdomain-errors`; `troubleshoot-servers/cpanel-servers/username-reserved-errors`; `troubleshoot-servers/cpanel-servers/wptoolkitcli-errors`; `troubleshoot-servers/plesk-servers`; `troubleshoot-servers/plesk-servers/_getssoredirecturl-errors`; `troubleshoot-servers/plesk-servers/blank-command-errors`; `troubleshoot-servers/plesk-servers/client-pool-errors`; `troubleshoot-servers/plesk-servers/domain-setup-failures`; `troubleshoot-servers/plesk-servers/email-not-found-errors`; `troubleshoot-servers/plesk-servers/invalid-request-errors`; `troubleshoot-servers/plesk-servers/limitations-exceeded-errors`; `troubleshoot-servers/plesk-servers/parser-errors`; `troubleshoot-servers/plesk-servers/permission-denied-errors`; `troubleshoot-servers/plesk-servers/plesk-connection-errors`; `troubleshoot-servers/plesk-servers/plesk-login-errors`; `troubleshoot-servers/plesk-servers/template-errors`; `troubleshoot-servers/plesk-servers/unsupported-protocol-errors`; `troubleshoot-servers/plesk-servers/username-required-errors`; `troubleshoot-servers/plesk-servers/version-errors`; `troubleshoot-servers/plesk-servers/www-does-not-work`; `troubleshoot-servers/product-module-missing-errors`

**(c) Payment troubleshooting; not read** (23 pages): `troubleshoot-payments/2checkout`; `troubleshoot-payments/authorize-net`; `troubleshoot-payments/bitpay`; `troubleshoot-payments/blank-payment-gateway-page`; `troubleshoot-payments/configuration-function-errors`; `troubleshoot-payments/eway`; `troubleshoot-payments/gateway-module-activation-errors`; `troubleshoot-payments/gocardless`; `troubleshoot-payments/ideal-via-mollie`; `troubleshoot-payments/invalid-access-attempt-errors`; `troubleshoot-payments/invalid-login-credentials`; `troubleshoot-payments/md5-hash-failures`; `troubleshoot-payments/no-gateway-log-entries`; `troubleshoot-payments/oops-module-not-found-errors`; `troubleshoot-payments/paypal`; `troubleshoot-payments/quantum-vault-missing-ending-errors`; `troubleshoot-payments/remote-transaction-failure-errors`; `troubleshoot-payments/skrill`; `troubleshoot-payments/stripe`; `troubleshoot-payments/stripe-dynamic-payments`; `troubleshoot-payments/unsupported-protocol-errors`; `troubleshoot-payments/user-redirected-to-wrong-page`; `troubleshoot-payments/worldpay`

**(c) Mailbox-protocol error message (POP3, Microsoft, SASL)** (22 pages): `troubleshoot-the-support-system/support-departments`; `troubleshoot-the-support-system/support-departments/department-access-denied-errors`; `troubleshoot-the-support-system/support-departments/department-not-found-errors`; `troubleshoot-the-support-system/ticket-id-not-found-errors`; `troubleshoot-the-support-system/ticket-replies`; `troubleshoot-the-support-system/ticket-replies/argument-7-ticket-errors`; `troubleshoot-the-support-system/ticket-replies/attachment-upload-failures`; `troubleshoot-the-support-system/ticket-replies/cannot-connect-to-host-errors`; `troubleshoot-the-support-system/ticket-replies/connection-refused-errors`; `troubleshoot-the-support-system/ticket-replies/duplicate-piped-directadmin-replies`; `troubleshoot-the-support-system/ticket-replies/invalid-sasl-argument-errors`; `troubleshoot-the-support-system/ticket-replies/local-delivery-failed-errors`; `troubleshoot-the-support-system/ticket-replies/logon-failure-errors`; `troubleshoot-the-support-system/ticket-replies/mail-import-test-failed-errors`; `troubleshoot-the-support-system/ticket-replies/microsoft-bad-request-errors`; `troubleshoot-the-support-system/ticket-replies/microsoft-connection-errors`; `troubleshoot-the-support-system/ticket-replies/microsoft-import-query-errors`; `troubleshoot-the-support-system/ticket-replies/pop3-auth-errors`; `troubleshoot-the-support-system/ticket-replies/pop3-read-failed-errors`; `troubleshoot-the-support-system/ticket-replies/single-id-errors`; `troubleshoot-the-support-system/ticket-replies/undefined-method-importing-errors`; `troubleshoot-the-support-system/ticket-replies/unregistered-address-errors`

**(c) Mail transport error message (SMTP, PHP mail, Microsoft); transport lives in the mail settings spec** (21 pages): `troubleshoot-email/data-not-accepted-errors`; `troubleshoot-email/email-flagged-as-spam`; `troubleshoot-email/message-body-empty-errors`; `troubleshoot-email/microsoft`; `troubleshoot-email/microsoft/microsoft-connection-unsuccessful`; `troubleshoot-email/microsoft/microsoft-mail-query-errors`; `troubleshoot-email/no-recipients-errors`; `troubleshoot-email/php-mail`; `troubleshoot-email/php-mail/could-not-instantiate-errors`; `troubleshoot-email/php-mail/undefined-phpmailer-function-errors`; `troubleshoot-email/sendemail-sending-failure`; `troubleshoot-email/sending-aborted-configuration`; `troubleshoot-email/sending-aborted-errors`; `troubleshoot-email/smarty-policy-errors`; `troubleshoot-email/smtp`; `troubleshoot-email/smtp/sender-verify-failed-errors`; `troubleshoot-email/smtp/smtp-authentication-errors`; `troubleshoot-email/smtp/smtp-connection-errors`; `troubleshoot-email/smtp/smtp-password-command-failed-errors`; `troubleshoot-email/undisclosed-recipients`; `troubleshoot-email/unsent-general-emails`

**(c) Social/SSO provider error message** (20 pages): `troubleshoot-authentication/duo-security-login-failures`; `troubleshoot-authentication/facebook-app-not-setup-errors`; `troubleshoot-authentication/facebook-url-errors`; `troubleshoot-authentication/google-remote-linking-failures`; `troubleshoot-authentication/google-sign-in-unavailable-errors`; `troubleshoot-authentication/google-verification-failures`; `troubleshoot-authentication/invalid-details-errors`; `troubleshoot-authentication/invalid-reset-link-errors`; `troubleshoot-authentication/login-details-errors`; `troubleshoot-authentication/login-redirect-loops`; `troubleshoot-authentication/oops-access-denied-errors`; `troubleshoot-authentication/password-length-out-of-range`; `troubleshoot-authentication/php-session-storage-errors`; `troubleshoot-authentication/social-login-errors`; `troubleshoot-authentication/sso-active-session-errors`; `troubleshoot-authentication/sso-configuration-errors`; `troubleshoot-authentication/sso-token-errors`; `troubleshoot-authentication/twitter-connection-errors`; `troubleshoot-authentication/twitter-link-initiated-messages`; `troubleshoot-authentication/unable-to-access-sign-in-integrations`

**(c) MarketConnect resale (excluded by brief)** (12 pages): `troubleshoot-marketconnect/marketconnect-404-errors`; `troubleshoot-marketconnect/mx-and-spf-record-errors`; `troubleshoot-marketconnect/nova-cloud`; `troubleshoot-marketconnect/nova-cloud/nova-cloud-license-state-errors`; `troubleshoot-marketconnect/promotion-display-issues`; `troubleshoot-marketconnect/ssl-certificates`; `troubleshoot-marketconnect/ssl-certificates/instant-issuance-deployment-errors`; `troubleshoot-marketconnect/ssl-certificates/instant-issuance-not-acquired-errors`; `troubleshoot-marketconnect/ssl-certificates/instant-issuance-unspecified-errors`; `troubleshoot-marketconnect/ssl-certificates/instant-issuance-validation-errors`; `troubleshoot-marketconnect/ssl-certificates/key-generation-failed-errors`; `troubleshoot-marketconnect/ssl-certificates/ssl-provisioning-issues`

**(c) Troubleshooting page; not read** (9 pages): `troubleshoot-invoices/blank-invoices`; `troubleshoot-invoices/duplicate-tblinvoices-errors`; `troubleshoot-invoices/logout-during-invoice-download`; `troubleshoot-invoices/question-marks-in-pdf-invoices`; `troubleshoot-invoices/tcpdf-image-size-errors`; `troubleshoot-invoices/tcpdf-timeouts`; `troubleshoot-products-and-services/column-not-found-shortdescription-errors`; `troubleshoot-products-and-services/member-function-renewal-errors`; `troubleshoot-products-and-services/recommendation-display-issues`

**(c) Self-hosted cron environment error** (9 pages): `troubleshoot-cron-issues/cron-failures`; `troubleshoot-cron-issues/cron-frequency-warnings`; `troubleshoot-cron-issues/cron-http-access-denied-errors`; `troubleshoot-cron-issues/cron-input-file-errors`; `troubleshoot-cron-issues/fatal-memory-size-errors`; `troubleshoot-cron-issues/no-input-file-specified-errors`; `troubleshoot-cron-issues/unable-to-communicate-errors`; `troubleshoot-cron-issues/undefined-curl_init-function-errors`; `troubleshoot-cron-issues/whoops-exception-errors`

**(c) PHP error display and support-access recipes for self-hosted installs.** (5 pages): `enable-error-reporting`; `errors-in-whmcs`; `grant-access-to-whmcs-support`; `refresh-a-directory`; `start-troubleshooting`

**(c) Captcha key or template display issue** (4 pages): `troubleshoot-the-client-area/captcha-verification-failures`; `troubleshoot-the-client-area/client-area-title-display-issues`; `troubleshoot-the-client-area/invalid-site-key-errors`; `troubleshoot-the-client-area/order-now-refreshes-the-page`

**(c) MaxMind fraud-service key error; provider quirk** (4 pages): `troubleshoot-orders/maxmind-license-key-errors`; `troubleshoot-orders/maxmind-license-required-errors`; `troubleshoot-orders/maxmind-request-errors`; `troubleshoot-orders/maxmind-user-id-errors`

**(c) Self-hosted file-permission check of web-exposed directories; Mohar ships containers.** (1 pages): `general-sensitive-directory-check-errors`

</details>

<details>
<summary>customization: 51 pages (a 1, b 12, c 38)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `customization/transliteration` | a (not needed) | Converts client names and addresses to a safe character set before a registrar call; a hook can replace the default. Owner decided 2026-10-31 that this is not needed (section 4 item 1). |
| `customization/client-area-customization-tutorials/customize-quick-shortcuts` | b | Homepage panels, menus and sidebars as object trees with page context. Compare navigation registry. |
| `customization/client-area-customization-tutorials/preview-a-template` | b | Preview an unpublished theme with a URL parameter. Idea in section 5. |
| `customization/client-area-customization/client-area-homepage-panels` | b | Homepage panels, menus and sidebars as object trees with page context. Compare navigation registry. |
| `customization/client-area-customization/menu-and-sidebar-context` | b | Homepage panels, menus and sidebars as object trees with page context. Compare navigation registry. |
| `customization/countries-and-calling-codes` | b | Editable country list with calling codes; Nepal +977 must be present. Platform-admin localisation. |
| `customization/create-admin-dashboard-widgets` | b | Admin dashboard widgets from extension code. Dashboard v2 widget registry. |
| `customization/custom-modules` | b | Database models exposed to third-party code. Laravel gives this natively. |
| `customization/error-pages` | b | Branded error pages for critical errors with logging. Mohar renders error pages in the theme. |
| `customization/integration-code` | b | Copy-paste login forms and links for an outside website; our storefront covers it. |
| `customization/models` | b | Database models exposed to third-party code. Laravel gives this natively. |
| `customization/sessions` | b | Cookie and session catalogue. Our privacy/cookie page should list the same facts. |
| `customization/single-sign-on-into-whmcs` | b | Single-use token sign-in from a trusted outside app, user can opt out. Public API wave 4. |

**(c) Smarty template and menu customisation recipe; Mohar themes are React with a theme editor** (34 pages): `customization/client-area-customization`; `customization/client-area-customization-tutorials`; `customization/client-area-customization-tutorials/create-a-new-order-form-template`; `customization/client-area-customization-tutorials/create-customizations-with-git`; `customization/client-area-customization-tutorials/disable-feature-highlights`; `customization/client-area-customization-tutorials/homepage-panels`; `customization/client-area-customization-tutorials/homepage-panels/add-a-panel-to-specific-pages`; `customization/client-area-customization-tutorials/homepage-panels/add-a-promotional-offer-panel`; `customization/client-area-customization-tutorials/homepage-panels/add-a-social-media-panel`; `customization/client-area-customization-tutorials/homepage-panels/add-a-special-offer-panel`; `customization/client-area-customization-tutorials/homepage-panels/add-an-informational-panel`; `customization/client-area-customization-tutorials/homepage-panels/create-a-two-column-panel`; `customization/client-area-customization-tutorials/homepage-panels/modify-an-existing-panel`; `customization/client-area-customization-tutorials/homepage-panels/rearrange-panels`; `customization/client-area-customization-tutorials/homepage-panels/remove-a-panel`; `customization/client-area-customization-tutorials/navigation`; `customization/client-area-customization-tutorials/navigation/add-a-menu-item`; `customization/client-area-customization-tutorials/navigation/change-a-menu-item-label`; `customization/client-area-customization-tutorials/navigation/change-menu-item-link`; `customization/client-area-customization-tutorials/navigation/open-menu-links-in-new-tabs`; `customization/client-area-customization-tutorials/navigation/rearrange-menu-items`; `customization/client-area-customization-tutorials/navigation/remove-a-menu-item`; `customization/client-area-customization-tutorials/sidebars`; `customization/client-area-customization-tutorials/sidebars/add-a-sidebar-item`; `customization/client-area-customization-tutorials/sidebars/change-a-sidebar-menu-label`; `customization/client-area-customization-tutorials/sidebars/change-a-sidebar-menu-link`; `customization/client-area-customization-tutorials/sidebars/rearrange-sidebar-items`; `customization/client-area-customization-tutorials/sidebars/remove-a-sidebar-item`; `customization/client-area-customization-tutorials/update-customizations-manually`; `customization/client-area-customization-tutorials/update-customizations-with-git`; `customization/client-area-customization/client-area-layout`; `customization/client-area-customization/client-area-navigation-menus`; `customization/client-area-customization/client-area-sidebars`; `customization/client-area-customization/customizing-promotions`

**(c) Self-hosted customisation hook or file recipe; no Mohar counterpart** (4 pages): `customization`; `customization/custom-order-form-templates`; `customization/themes-and-templates`; `customization/whmcs-base-url-template`

</details>

<details>
<summary>installation-guide: 15 pages (a 0, b 1, c 14)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `installation-guide/set-up-a-test-environment` | b | Staging copy before upgrades and for development. Idea in section 5. |

**(c) Self-hosted installer and file hardening; Mohar installs from containers (docs/install)** (14 pages): `installation-guide`; `installation-guide/initial-configuration`; `installation-guide/initial-configuration/move-the-cron-directory`; `installation-guide/initial-configuration/rename-the-admin-directory`; `installation-guide/initial-configuration/restrict-nginx-directory-access`; `installation-guide/initial-configuration/secure-the-configuration-file`; `installation-guide/initial-configuration/secure-writeable-directories`; `installation-guide/initial-configuration/the-configuration-php-file`; `installation-guide/install-ioncube-loader`; `installation-guide/install-on-the-command-line`; `installation-guide/install-whmcs`; `installation-guide/install-with-plesk-whmcs-installer`; `installation-guide/system-requirements`; `installation-guide/uninstall-whmcs`

</details>

<details>
<summary>marketconnect: 25 pages (a 0, b 0, c 25)</summary>

**(c) MarketConnect resale of third-party products (excluded by brief)** (25 pages): `marketconnect`; `marketconnect/marketconnect`; `marketconnect/marketconnect-landing-pages`; `marketconnect/marketconnect-promotions`; `marketconnect/marketconnect-tutorials`; `marketconnect/marketconnect-tutorials/activate-marketconnect`; `marketconnect/marketconnect-tutorials/configure-marketconnect-pricing`; `marketconnect/marketconnect-tutorials/deposit-funds`; `marketconnect/marketconnect-tutorials/set-up-balance-alerts`; `marketconnect/marketconnect-tutorials/set-up-balance-reloading`; `marketconnect/marketconnect-tutorials/start-selling-marketconnect`; `marketconnect/service-providers`; `marketconnect/service-providers/360-monitoring`; `marketconnect/service-providers/codeguard`; `marketconnect/service-providers/marketgoo`; `marketconnect/service-providers/nordvpn`; `marketconnect/service-providers/nova-cloud`; `marketconnect/service-providers/ox-app-suite`; `marketconnect/service-providers/sitelock`; `marketconnect/service-providers/sitelock-vpn`; `marketconnect/service-providers/socialbee`; `marketconnect/service-providers/spamexperts`; `marketconnect/service-providers/ssl-certificates`; `marketconnect/service-providers/web-com-site-builder`; `marketconnect/service-providers/weebly`

</details>

<details>
<summary>releases: 4 pages (a 0, b 3, c 1)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `releases/9-1` | b | Release highlights and change log for 9.1; covered by the refresh note and the change-log note. |
| `releases/9-1/9-1-change-log` | b | Release highlights and change log for 9.1; covered by the refresh note and the change-log note. |
| `releases/9-1/9-1-release-highlights` | b | Release highlights and change log for 9.1; covered by the refresh note and the change-log note. |

**(c) Release index or upgrade path for old WHMCS lines** (1 pages): `releases/updating-through-eol-versions`

</details>

<details>
<summary>billing-and-invoicing: 1 pages (a 0, b 1, c 0)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `billing-and-invoicing/invoicing-tutorials/configure-invoice-immutability` | b | Three compliance states for immutability. We keep one state: on. |

</details>

<details>
<summary>mobile-app: 2 pages (a 0, b 1, c 1)</summary>

| Page (under `https://docs.whmcs.com/9-1/`) | Triage | Note |
| --- | --- | --- |
| `mobile-app/whmcs-mobile-apps` | b | Admin mobile apps for iOS and Android. Already in admin-and-client section 17. |

**(c) App store listing** (1 pages): `mobile-app`

</details>

<details>
<summary>about-whmcs: 4 pages (a 0, b 0, c 4)</summary>

**(c) WHMCS company, glossary or distribution info** (4 pages): `about-whmcs`; `about-whmcs/whmcs-development`; `about-whmcs/whmcs-distributions`; `about-whmcs/whmcs-glossary`

</details>

<details>
<summary>tags: 260 pages (a 0, b 0, c 260)</summary>

**(c) Navigation index** (260 pages): `tags`, `2checkout`, `addon-modules`, `addon-modules-general`, `admin-area`, `admin-dashboard`, `admin-directory`, `administrator-roles`, `admins`, `affiliates`, `amazon-s3`, `analytics`, `announcements`, `application-links`, `apps-and-integrations`, `attachments`, `authentication`, `authorize-net`, `automatic-updates`, `automation`, `billable-items`, `bitpay`, `cancellations`, `captchas`, `centralnic-reseller`, `client-area`, `client-area-customization`, `client-area-menus`, `client-area-sidebar`, `client-data`, `client-emails`, `client-groups`, `client-profile`, `clients`, `cloudflare`, `configurable-options`, `configuration`, `configuration-php`, `contacts`, `continuous-invoice-generation`, `cpanel`, `credit`, `credit-and-debit-notes`, `curl-errors`, `currencies`, `custom-fields`, `customization`, `database`, `database-backups`, `digicert`, `directadmin`, `display-errors`, `display-issues`, `disputes`, `domain-availability`, `domain-management`, `domain-pricing`, `domain-registration`, `domain-registration-general`, `domain-renewals`, `domain-sync-task`, `domain-transfers`, `downgrades`, `downloads`, `duo-security`, `e-invoicing`, `email-importing`, `email-marketing`, `email-piping`, `email-sending`, `email-templates`, `emailing-admins`, `enom`, `enom-ssl`, `error-handling`, `errors`, `escalation`, `event-handling`, `eway`, `exchange-rates`, `facebook`, `facebook-sign-in`, `file-and-encoding-errors`, `fraud-protection`, `free-domains`, `friendly-urls`, `general-errors`, `general-settings`, `general-system-email`, `getting-started`, `gocardless`, `godaddy`, `google`, `google-importing`, `google-sign-in`, `grace-periods`, `gravatar`, `hexonet`, `homepage-panels`, `hooks`, `hosting-accounts`, `ideal-via-mollie`, `importassist`, `installation`, `invites`, `invoice-generation`, `invoices`, `ioncube`, `ip-addresses`, `knowledgebase`, `licensing`, `linking`, `live-chat`, `localization`, `logo`, `logs`, `lookup-provider`, `mail-providers`, `mailgun`, `maintenance-mode`, `manual-gateway`, `marketconnect`, `marketconnect-provider`, `marketplace`, `maxmind`, `merchant-gateway`, `microsoft`, `microsoft-importing`, `microsoft-mail-provider`, `migrations`, `modules`, `namecheap`, `nameservers`, `network-issues`, `next-due-date`, `nominet`, `notifications`, `nova-cloud`, `nova-experience`, `oauth`, `on-demand-renewals`, `onlinenic`, `oops-errors`, `openid-connect`, `opensrs`, `opt-in`, `order-form`, `order-form-templates`, `orders`, `overage-billing`, `password-resets`, `payflow-pro`, `payment-gateways`, `payment-reminders`, `payment-reversals`, `paypal`, `paypal-basic`, `paypal-pro-reference-payments`, `pdf-invoices`, `php`, `php-mail`, `php-typeerror`, `php-upgrades`, `plesk`, `post-update`, `predefined-replies`, `product-addons`, `product-groups`, `products`, `proforma-invoices`, `promotions`, `prorata-billing`, `provisioning`, `provisioning-modules`, `quantum-vault`, `quotes`, `recommendations`, `redemption-periods`, `registrar-module`, `reminder-emails`, `renewals`, `reports`, `resellerclub`, `rfc-3834`, `scheduled-actions`, `search-page`, `security`, `sendgrid`, `server-module`, `servers`, `services`, `sessions`, `shopping-cart`, `sign-in-integrations`, `single-sign-on`, `sitejet-builder`, `skrill`, `slack`, `smarty`, `smtp`, `smtp-mail-provider`, `software-licensing-addon`, `spam`, `sparkpost`, `ssl`, `ssl-monitoring`, `status-monitoring`, `storage`, `stripe`, `stripe-ach`, `stripe-dynamic-payments`, `support`, `support-departments`, `support-tickets`, `suspensions`, `sync-accounts`, `system-cron`, `system-email`, `system-requirements`, `system-theme`, `system-url`, `taxes`, `terminations`, `theme-editor`, `third-party-gateway`, `ticket-actions`, `ticket-replies`, `time-billing`, `timezone`, `token-gateway`, `tokenization`, `transactions`, `transip`, `translation-editor`, `troubleshooting`, `troubleshooting-article`, `twitter`, `twitter-sign-in`, `two-factor-authentication`, `unattended-automatic-updates`, `update-check`, `updates`, `upgrades`, `usage-billing`, `user-identity-verification`, `users`, `vat`, `webnic`, `webpros-partner`, `welcome-emails`, `whmcs-api`, `whmcs-cloud`, `whmcs-support`, `whmcs-versions`, `whois`, `wordpress`, `worldpay`, `wp-squared`, `wp-toolkit`, `wpsquared`

</details>

<details>
<summary>categories: 1 pages (a 0, b 0, c 1)</summary>

**(c) Navigation index** (1 pages): `categories`

</details>

<details>
<summary>content-type: 2 pages (a 0, b 0, c 2)</summary>

**(c) Navigation index** (2 pages): `content-type`; `content-type/topic`

</details>

<details>
<summary>site root: 1 pages (a 0, b 0, c 1)</summary>

**(c) Navigation index** (1 pages): `https://docs.whmcs.com/9-1`

</details>

Developer pages (3): all in `/rest-api/`, all 9.1.

| Page | Triage | Note |
| --- | --- | --- |
| `headless-stripe-payments` | a | The payment-session contract for a custom storefront. Section 4 item 3. |
| `stored-payment-methods` | a | List, read and delete saved methods; the failure codes mean different things. Section 4 item 3. |
| `rate-limits` | b | 30 calls per 5 minutes per IP and route on four payment endpoints, 429 with no retry header. Refines public-api section 2. |

## 4. Uncovered features and details worth acting on

Most of what is uncited is troubleshooting. Troubleshooting pages are valuable for a different reason than feature
pages: each one is a failure that real hosts hit, which makes it a test case. Items 1 to 10 are ranked by value to a
Nepali host switching from WHMCS. Items marked (a) are not in our specs; items marked (b) are in our specs in some
form and the page adds a detail or a test.

| # | Item | Class | What it is | Build? (Nepal-first) | Where |
| --- | --- | --- | --- | --- | --- |
| 1 | Registrar-safe character set | a (not needed) | A shipped function replaces characters in client details that registrars reject; a hook can swap in a better one ([transliteration](https://docs.whmcs.com/9-1/customization/transliteration), 2026-10-31) | **Not needed (owner, 2026-10-31):** clients never enter Devanagari names, so no conversion is built. Kept in the list as the highest-ranked item before the decision | `registrars.md` contact mapping, `domains.md` section 5; wave 3.3 |
| 2 | Import guard for blank, zero or past next-due dates | b | A service with a zero next-due date makes the invoice job create one invoice for every missed period ([duplicate invoices, different dates](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-invoices/duplicate-invoices-different-dates), 2026-10-31) | **Yes, P0 for the importer.** A switching host has such rows (imported or hand-added services). Reject or flag them in the dry run; never let a due date earlier than the cutover date create catch-up invoices | `migration.md` section 7, `invoice-domain-import.md` section 9 |
| 3 | Headless payment contract | a | A payment returns `status` (paid, pending, action_required, not_required, failed) and a `next_action` (redirect or payment_session). A decline returns normally with `failed` and a fresh session. Removing a saved method returns 422 for a merchant setting, 409 for a passing condition, 429 for rate limit ([headless](https://developers.whmcs.com/rest-api/headless-stripe-payments), [stored methods](https://developers.whmcs.com/rest-api/stored-payment-methods), 2026-10-31) | **Yes, P1.** eSewa and Khalti are redirect flows and fit `redirect`; cards fit `payment_session`. One shape for a custom storefront, mobile app or the host's own site. Add `Retry-After` and per-token limits, which WHMCS does not | `public-api.md` section 4/5 (v1.1), `payments-nepal.md` flows; wave 4 REST API |
| 4 | Monthly equivalent in the cart | a | A setting shows the per-month price for longer billing cycles; admins who forget it see "prices lower than set" ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-the-client-area/prices-incorrect-in-cart), 2026-10-31) | **Yes, P2, small.** Nepali hosts sell annual plans; "NPR 250 a month, billed NPR 3,000 a year" helps conversion. Always show the billed amount next to it | `storefront-marketing.md`, `orders.md` section 4 |
| 5 | Event and hook trace | a | A debug switch logs each hook call so an admin can find which custom hook blocked an email ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-email/sending-aborted-by-hook), 2026-10-31) | **Yes, P2.** Once outside code listens to our events (wave 4 webhooks), "who stopped this email" needs an answer. A per-event listener trace with durations, off by default, in the activity log | ADR 0008 amendment; `notifications.md` suppression reasons |
| 6 | E-invoice submission lifecycle | a | A submitted e-invoice becomes immutable; a recipient refusing it is a business outcome needing a new document; missing client data blocks export ([three pages](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-addon-modules/e-invoicing/refused-e-invoice-status), 2026-10-31) | **Out of scope for now (owner, 2026-10-31); kept as a note only.** If the IRD later requires electronic submission, reserve a `submitted` state | `billing.md` section 2 (document states); `vat-register.md` |
| 7 | Update and maintenance protocol | a | Unattended updates try three times then stop and wait for a person; a permission pre-check runs before any file is touched; during an upgrade the client area, API and cron all answer with one maintenance message ([updates](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-updates/permanently-failed-automatic-updates), [maintenance](https://docs.whmcs.com/9-1/troubleshooting/general-troubleshooting/down-for-maintenance-errors), 2026-10-31) | **Yes, P2.** Ours is a container deploy, but the rules carry over: one maintenance state that returns 503 with `Retry-After` to web, API and scheduler; a preflight; bounded retries with a visible failed state | `docs/devops` install and upgrade runbook; platform-admin maintenance mode |
| 8 | Small validation rules | b | One mailbox per support department (two on one import every reply twice; already specified: `support.md` schema and section 13); admin invites last 7 days and resend or delete kills the old link (our staff invitations have the 7 days and resend; confirm cancel); remembered admin sessions last 30 days; 10 failed logins in 10 minutes lock the source; password history is clamped to 4 to 40 | **Yes, P1, cheap.** Each is one acceptance line. The server-deletion rule is already specified (`services.md` section 9) | `support.md` section 6, `platform-admin.md` |
| 9 | Scheduled ticket action retry window | b | An action that cannot run is retried for 60 minutes, then marked failed and left alone ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-the-support-system/scheduled-action-failures), 2026-10-31) | **Only if built:** `support.md` section 15 lists scheduled ticket actions as out of scope for v1; decide a window then | `support.md` section 15 |
| 10 | Cart honesty on unknowns | b | Availability checks that hit registry timeouts give wrong answers; TLD or period not priced rejects the order late ([availability](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-domains/domain-availability-errors), [periods](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-orders/invalid-registration-period-errors), 2026-10-31) | **Yes, P1.** Show "could not check" rather than "available"; never offer an unpriced period | `domains.md` section 6, `orders.md` section 6 |

Other (b) findings that need only a test, not a feature:

- Marking an invoice paid twice moves the next due date twice ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-invoices/unexpected-next-due-date)). Our paid transition is idempotent; keep the test.
- Two cron processes create twin invoices, twin reminders and double charges ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-invoices/duplicate-invoices-same-dates)). Our unique indexes and run lock cover it; keep a test that runs the job twice at once.
- Signup-anniversary prorata overrides product prorata without saying so ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-products-and-services/service-not-prorated)). Show the effective rule in the product editor (see ideas).
- Early client renewal is allowed only when the service is recurring, active, unmetered, fully paid and inside the renewal window ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-products-and-services/on-demand-renewal-unsupported)). `services.md` section 16 puts early renewals out of scope for v1, so this is Missing; the five conditions are the starting point if built (parity row "On-demand renewals per product").
- WHMCS lets an owner switch invoice immutability off, with three states ([page](https://docs.whmcs.com/9-1/billing-and-invoicing/invoicing-tutorials/configure-invoice-immutability)). We keep it on with no switch; we assume the IRD treats an issued invoice as final (see Owner to confirm).
- The daily run can stall inside its own backup step or its usage-polling step ([backup](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-cron-issues/cron-system-queue-timeout), [polling](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-cron-issues/cron-metrics-polling-timeout)). Keep backups out of the billing run and give each step a time budget.
- A recipient list with one bad address fails the whole email ([page](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-email/failed-recipients)). Validate addresses on save and send to the valid ones.

## 5. Ideas worth considering

**Things WHMCS does well that we have not planned**

1. **Explain-why panel on a service.** WHMCS lists the only three reasons a service can be suspended or terminated (overdue days, a fixed term, an admin click) and sends staff to the activity log. Do it for them: a "why is this suspended" line computed from the audit trail, shown to staff and, in plain words, to the client.
2. **Failure catalogue with stable codes.** 513 of WHMCS's 1,431 documentation pages are troubleshooting. Our errors should carry a stable code and a link to a short fix page, generated from the same source as the error. Then support does not need a human to explain each one.
3. **Theme preview by URL.** Append a parameter to see an unpublished theme without exposing it to clients ([page](https://docs.whmcs.com/9-1/customization/client-area-customization-tutorials/preview-a-template)). Our theme editor can offer a signed preview link that staff share with the owner.
4. **Staging copy as a feature.** WHMCS tells hosts to build a test environment by hand before every upgrade ([page](https://docs.whmcs.com/9-1/installation-guide/set-up-a-test-environment)). We can offer "clone to sandbox" with fake gateways, captured mail and masked client data.
5. **Unattended patch updates with a visible history.** WHMCS added this in 9.1 after fifteen hardening releases in 2026. For us: signed images, patch-only auto update, rollback button, and a plain security notes page.
6. **Admin mobile apps.** Native apps exist for WHMCS; for us an installable admin PWA with push is cheaper and covers the same triage actions (approve order, reply to ticket, mark paid).

**Things WHMCS does badly that we can do better**

1. **Silent setting overrides.** Signup-anniversary prorata beats product prorata; a monthly breakdown makes prices look lower. Show the effective rule next to each setting and warn on conflicts.
2. **No Retry-After on rate limits, and limits by IP and route.** A server integration acting for many clients shares one allowance ([rate limits](https://developers.whmcs.com/rest-api/rate-limits)). Limit per token, send `Retry-After` and `RateLimit-*` headers.
3. **Security disclosure.** Fixes are "undisclosed" lines with no index. Publish a short advisory per fix after the patch window, with affected versions.
4. **Five generations of PayPal.** PayPal alone has seventeen troubleshooting pages. One driver per provider, webhooks plus a reconciliation job, and a deprecation path.
5. **Imports that create invoice storms.** Zero due dates cause one invoice per missed period. The importer should refuse them in the dry run.
6. **Edit-in-place invoices until 9.0.** Most invoice bugs in the change logs came from editing issued invoices. We never allow it.
7. **Database-backup step inside the billing run.** Keep it outside.

## 6. Importer coverage (ADR 0019, `client-import.md`, `invoice-domain-import.md`, `migration.md`)

### 6.1 API actions

Our read-only allowlist has 33 actions (`migration.md` section 2.1). WHMCS has 162 action pages. The read actions
(names starting `Get`) that our allowlist does not name, with a verdict:

| Action | What it returns | Need? | Why |
| --- | --- | --- | --- |
| GetConfigurationValue | One system setting by name | **Yes, P0** | The only way to read invoice settings, tax mode, late-fee and prorata switches, company timezone and currency without a database. Allowlist specific keys only, never "any" |
| GetCancelledPackages | Pending cancellation requests | **Yes, P1** | A host switching mid-month has open cancellation requests; `services.md` section 6 needs them |
| GetModuleQueue | Failed module actions | **Yes, P1** | Cited in our sources but not in the allowlist. Use as a pre-cutover check: do not import while failed provisioning is pending |
| GetHealthStatus | The health checks | **Yes, P2** | Pre-flight: flags a stale cron or disabled gateway before we read |
| GetStats | Income, order and ticket totals | **Yes, P1** | Reconciliation: compare our imported counts and income with the source's own totals |
| GetAutomationLog | Daily-run history | P2 | Confirms the source stopped its cron before cutover |
| GetEmails | Mail sent to one client | P2 | Communication history; heavy, one call per client; optional |
| GetEmailTemplates | Template list | P2 | Carry custom wording across; templates are Smarty, so only the text is useful |
| GetTicketPredefinedCats, GetTicketPredefinedReplies | Canned replies | P1 | Support teams value these; `support.md` has canned replies |
| GetOrderStatuses | Status names | No | Fixed set |
| GetPermissionsList, GetAdminDetails | Role names, current credential | P2 | Map staff roles; identify which admin credential we hold |
| GetActivityLog, GetToDoItems, GetTodoItemStatuses, GetProjects, GetProject, GetStaffOnline, GetTicketCounts | Logs, to-dos, project addon, presence | No | Not business data |

No action exists for tax rules, custom field definitions, configurable option definitions, product addon definitions,
knowledge-base articles or email-log bodies, so the importer cannot read them by API. This is already the position in
`migration.md`; the list above confirms it from the full action index. `GetClientPassword` is described in the
8.0 change log as removed from the documentation because it never worked (CORE-15308), which agrees with our "never
call" rule.

Behaviour changes that affect an importer (from the change logs): `GetInvoices` returned a wrong `numreturned` before
8.0 (CORE-14557); `GetUsers` exists from 8.1 and `WhmcsDetails` from 8.0; quantity fields on products and addons exist
from 8.12; API URLs are validated in 9.1. Read `WhmcsDetails` first and gate on version.

### 6.2 Hooks and events

The importer does not use hooks. They matter to a host moving custom hook files. The change logs and developer pages
name these hooks; the map to `app/Events`:

| WHMCS hook | Mohar event | Gap |
| --- | --- | --- |
| ClientAdd, ClientEdit | ClientRegistered, ClientUpdated | none |
| ClientDelete (deprecated), PreDeleteClient | none | we close and merge clients; add `ClientAnonymised` if an erasure feature is built |
| UserAdd, UserEdit (client logins) | none | client-user events are wave 3.7 (invite, permissions) |
| InvoiceCreated, InvoicePaid, InvoiceCancelled, InvoicePaymentReminder | same names | none |
| InvoiceUnpaid, InvoiceCreationPreEmail | none | add `InvoiceReopened`; pre-email is covered by notification suppression |
| PreInvoiceAutomaticCancellation | none | needed when auto-cancel of overdue invoices is built (services/billing automation) |
| AfterModuleCreate, Suspend, Unsuspend, Terminate | ServiceActivated, ServiceSuspended, ServiceUnsuspended, ServiceTerminated | none |
| Pre and AfterModuleCustom | none | `ModuleCommandSucceeded/Failed` (roadmap 3.1) |
| ServiceDelete | none | services are not deleted; map to ServiceCancelled/Terminated |
| AddonActivation and the other addon hooks | none | addon events missing (developer-architecture 12.2) |
| TicketOpen, TicketUserReply, TicketAdminReply, TicketMerge | TicketOpened, TicketReplied, TicketMerged | TicketSplit missing |
| EmailPreSend (can attach files since 9.0) | EmailQueued, EmailSuppressed | no pre-send mutation; decide in ADR 0008 |
| PostAutomationTask, DailyCronJob | AutomationRunCompleted | none |
| DomainTransferCompleted, DomainTransferFailed | DomainTransferred, DomainTransferFailed | none |

The 339-hook count and the category map are in `developer-architecture.md` section 12.2; this table adds the
renamed and deprecated hooks that old hook files still use.

## 7. Owner to confirm

Decisions recorded by the owner on 2026-10-31 (PR #550) replace the assumptions in the first draft.

- **Transliteration (item 1): not needed.** Clients never enter Devanagari names. Nothing to build.
- **IRD e-invoicing (item 6): out of scope for now.** Kept as a note only. An issued invoice is final and corrected
  only by a credit note (our existing rule).
- **Exchange rates: confirmed.** The Nepal Rastra Bank rate is the source for NPR, with a review dialog and a
  history of changes. The WHMCS page on failed rate updates (a currency the feed does not carry) still argues for a
  manual rate as the fallback.

## 8. What I could not reach or did not read

- docs.whmcs.com has no sitemap for old versions on the root sitemap; I used 9.1. Pages that exist only in 8.x
  (24 cited URLs) are not in the inventory.
- About 430 provider-specific troubleshooting pages were triaged from path and title, not read. A sample across
  gateways, registrars and panels was read to confirm they are error-message catalogues.
- The partial developer crawl does not prove the REST operation list is complete; it matches the 85 operations in the
  OpenAPI file that our earlier study read.
- The WHMCS public demo, community forums and marketplace were out of scope for this pass.
- No security advisory index was found, so patch security content is known only from change-log lines.
