# WHMCS change logs, 8.0 to 9.1, 2026-10-31

Research only. Product manager agent. Public pages only; all wording ours; entry ids (CORE-, FEATURE-, MODULE-, WHMCS-)
are quoted as references, not text. Nothing was run against a WHMCS install and no vendor source was read.
Change-log pages: `https://docs.whmcs.com/releases/<major>-<minor>/<major>-<minor>-change-log/` for the 16 minor
versions 8.0 to 9.1; release dates and end-of-life dates from the matching `...-release-notes/` pages. All read
2026-10-31, fetched one page at a time. Companion notes: `2026-10-31-whmcs-refresh.md` (headline release content, pricing,
deprecations) and `billing/docs/parity/evolution-and-pain-points.md` section 2 (headline features per release).
This note adds what the headline lists leave out: the per-patch fixes.

## 0. Method and limits

- The 16 change-log pages hold 2,165 numbered entries. I read every entry that mentions invoices, tax, proration,
  renewals, credit, suspension, sync, mail import, currency, promotions, hooks, API or removals, plus all feature
  ("Implemented") lines, all 9.x entries, and the section counts for the rest. Plain provider-module fixes (a single
  registrar or panel endpoint changing) and the many PHP 8.1 crash fixes were counted, not read one by one.
- WHMCS does not publish a separate security advisory index that I found. Security fixes appear in the change log as
  "undisclosed security fix" lines, so the counts below are the only public signal.
- Language-file changes are deliberately left out of WHMCS's change logs, so they do not show here.
- The change logs do not say how a fix works, only what was wrong. Every "WHMCS does X underneath" below is therefore
  an inference from the wording of the fix, marked as such. Anything we act on needs a test of our own behaviour, not a
  copy of theirs.
- Tags: **[U]** reveals underlying behaviour, **[I]** matters to the importer, API clients, hooks or module authors,
  **[R]** a recurring bug area (section 3 counts them).

## 1. Version timeline

Counts are numbered entries on the version's page, all patches included.

| Version | GA | End of life | Patches (last, date) | Entries | Undisclosed security lines | Theme |
| --- | --- | --- | --- | --- | --- | --- |
| [8.0](https://docs.whmcs.com/releases/8-0/8-0-change-log/) | 2020-09-28 | 2021-09-30 | 8.0.5 (2021-02-26, security) | 435 | 0 | Users separated from clients, email campaigns and mail services, SEO URLs, big clean-up of old code |
| [8.1](https://docs.whmcs.com/releases/8-1/8-1-change-log/) | 2020-12-29 | 2021-12-31 | 8.1.3 (2021-02-26, security) | 276 | 0 | New Bootstrap 4 client theme, Google Analytics 4, AutoAuth removed |
| [8.2](https://docs.whmcs.com/releases/8-2/8-2-change-log/) | 2021-06-21 | 2022-06-30 | 8.2.1 (2021-07-13) | 176 | 0 | Addon proration, addon types, Stripe balance and detail views |
| [8.3](https://docs.whmcs.com/releases/8-3/8-3-change-log/) | 2021-10-13 | 2022-10-31 | 8.3.2 (2021-11-29) | 108 | 0 | Dispute management, affiliate commission reversal, WordPress hosting |
| [8.4](https://docs.whmcs.com/releases/8-4/8-4-change-log/) | 2022-01-18 | 2023-01-31 | 8.4.1 (2022-02-21) | 116 | 0 | SSL management; invoice-numbering and Stripe-fee fixes |
| [8.5](https://docs.whmcs.com/releases/8-5/8-5-change-log/) | 2022-06-09 | 2023-05-31 | 8.5.2 (2022-11-30, security) | 93 | 0 | Cross-sell in cart, module custom actions |
| [8.6](https://docs.whmcs.com/releases/8-6/8-6-change-log/) | 2022-11-03 | 2023-10-31 | 8.6.2 (2023-06-20, security) | 112 | 0 | PHP 8.1, Microsoft mail OAuth |
| [8.7](https://docs.whmcs.com/releases/8-7/8-7-change-log/) | 2023-03-21 | 2024-02-29 | 8.7.3 (2023-06-20, security) | 159 | 0 | PHP 8.1 hardening, end of old Smarty PHP tags announced |
| [8.8](https://docs.whmcs.com/releases/8-8/8-8-change-log/) | 2023-09-21 | 2024-08-31 | 8.8.0 | 76 | 0 | On-demand service renewals, encrypted MySQL connections |
| [8.9](https://docs.whmcs.com/releases/8-9/8-9-change-log/) | 2024-03-06 | 2025-01-31 | 8.9.0 | 37 | 0 | On-demand addon renewals, read-only invoice access, PayPal Payments |
| [8.10](https://docs.whmcs.com/releases/8-10/8-10-change-log/) | 2024-04-24 | 2025-04-30 | 8.10.1 (same day) | 24 | 0 | Auto-cancel overdue invoices |
| [8.11](https://docs.whmcs.com/releases/8-11/8-11-change-log/) | 2024-09-18 | 2025-09-30 | 8.11.3 (2025-06-03, security) | 84 | 31 | Captcha v3 and hCaptcha, cancel-invoice permission, PHP 8.2 |
| [8.12](https://docs.whmcs.com/releases/8-12/8-12-change-log/) | 2025-01-15 | 2026-01-31 | 8.12.2 (2025-06-03, security) | 67 | 31 | Ticket scheduled actions, admin invites, DST proration fix |
| [8.13](https://docs.whmcs.com/releases/8-13/8-13-change-log/) | 2025-05-08 | 2026-12-31 | 8.13.7 (2026-09-03) | 160 | 55 | New getting-started wizard, cron IP allow list, hardening patches |
| [9.0](https://docs.whmcs.com/releases/9-0/9-0-change-log/) | 2026-01-20 | 2026-12-31 | 9.0.9 (2026-09-24) | 161 | 25 | Credit notes, Nexus cart and theme, VAT checks, Smarty 4 |
| [9.1](https://docs.whmcs.com/releases/9-1/9-1-change-log/) | 2026-10-20 | 2027-09-30 (expected) | none yet | 81 | 3 | Invoice immutability, credit-note pages, unattended updates, REST v2 payments |

Two things the table shows. First, entry counts drop from 435 (8.0) to 24 (8.10) and rise again to 160 (8.13, 9.0):
the long quiet middle is a maintenance phase, and 8.13 and 9.0 are mostly security hardening with the same fixes
applied to both lines. Second, no security lines were disclosed at all before 8.11, then 31, 31, 55, 25: either the
practice of listing "undisclosed" items started in 2024, or the earlier security patches (8.0.5, 8.1.3, 8.5.2, 8.6.2,
8.7.3) listed them in a form that does not show on the page. I cannot tell which from public material.

## 2. Per version

Each section is collapsed. Full entry lists are at the linked change-log page; here, features, behaviour changes,
selected fixes, security, deprecations and removals, then the flags.

<details>
<summary>8.0 (2020-09-28), 435 entries, the large clean-up release</summary>

[Change log](https://docs.whmcs.com/releases/8-0/8-0-change-log/), [notes](https://docs.whmcs.com/releases/8-0/8-0-release-notes/).

- **Features.** Separate user logins from clients; email campaigns; email delivery services (SparkPost, Mailgun,
  SendGrid); IDN improvements; SEO-friendly cart URLs; admin-user attribution on credit log entries; admin theme
  refresh; TicketMerge, TicketSplit and UserAdd hooks; Open-Xchange via MarketConnect.
- **Behaviour changes.**
  - 3 decimal places allowed on tax rates (CORE-14026). [U]
  - Prorata disallowed when the billing cycle is one-time (CORE-14492, CORE-14728). [U]
  - Automatic suspension and termination suppressed for services whose payment is pending (CORE-14427). [U]
  - Lifetime promotions can be applied on upgrade orders from the admin area (CORE-14465).
  - Mail collection no longer needs the PHP IMAP extension (CORE-14767). [I]
  - Admin choice of client or admin language for invoice PDFs (CORE-14573).
  - "Create add-funds invoice" gets its own admin permission (CORE-14595).
- **Fixes (selected).** Mass-pay credit goes to the credit balance if the contained invoices are already paid
  (CORE-11600) [U]; correct count returned by GetInvoices (CORE-14557) [I]; a deleted service tied to an invoice
  handled (CORE-14659); cancelled-during-cron invoice no longer fatals (CORE-14685); tax on addon prorata invoices
  (CORE-14880); renewal invoice regenerated after a product upgrade (CORE-14770) [U]; expired-card inspection
  (CORE-15213); AddClient email uniqueness now enforced (CORE-15298) [I]; Server Sync creating or reusing the right
  client (CORE-15101); piped attachments with complex file names (CORE-13893) [R].
- **Deprecations and removals.** AutoAuth switched off on upgrade (CORE-14395); old pipe directory, v4 admin theme,
  "boxes" and "modern" order forms removed; ClientDelete hook deprecated in favour of PreDeleteClient (CORE-14412)
  [I]; Paymate, Kayako LoginShare, GameCP and HipChat references removed; GetClientPassword removed from the docs
  as non-functional (CORE-15308) [I].
- **Security.** 8.0.5 is a security-only patch; no detail shown.
- **Flags.** Hook system internals were refactored in RC1 (CORE-13824) [I]. Sixty-four of 435 entries are provider
  modules, 14 API.

</details>

<details>
<summary>8.1 (2020-12-29), 276 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-1/8-1-change-log/).

- **Features.** Bootstrap 4 client theme (Twenty-One); Google Analytics 4; Google and OAuth settings for ticket
  department mail; user deletion in data-retention automation; GetUsers API; GetRegistrars API.
- **Behaviour changes.**
  - No "Invoice Created" email when an order produced no invoice (CORE-10886, CORE-15352). [U]
  - Negative tax rates now appear in invoice email (CORE-15211). [U]
  - UK VAT number validation through the HMRC service (CORE-16033). [I]
  - Order invoices generated in the client's language (CORE-16093).
  - AutoAuth removed outright (CORE-14396).
- **Fixes (selected).** One-time promotion on a domain renewal affects only the registration price (CORE-14101) [U];
  upgrade rounding for non-fractional currencies (CORE-15575) [U][R]; detection of concurrent gateway transactions in
  one session (CORE-15712) [U]; uninvoiced items no longer added to a new order's invoice (CORE-15556) [U];
  ClientEdit hook now fires when tax status toggles (CORE-15138) [I]; free-setup promo with multiple quantities
  (CORE-15832) [R]; one-time promo with multiple quantities (CORE-15879) [R]; AddInvoicePayment honours a supplied
  time (CORE-15881) [I]; InvoiceCancelled hook correct when UpdateInvoice cancels (CORE-14322) [I]; InvoiceUnpaid
  hook ordering (CORE-16101) [I]; Stripe fees converted into the payment currency (MODULE-6646, MODULE-7573) [R].
- **API.** ActivateModule can enable addon and fraud modules; GetClients filters on status (CORE-15788); createssotoken
  works from the local API (CORE-15567); GetUsers and GetRegistrars added. [I]
- **Security.** 8.1.3 is a security-only patch.

</details>

<details>
<summary>8.2 (2021-06-21), 176 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-2/8-2-change-log/).

- **Features.** Prorata billing for product addons (FEATURE-2473); addon duplication and addon types; Stripe balance
  widget and transaction detail inside admin; SiteBuilder and WordPress Toolkit through MarketConnect.
- **Behaviour changes.** AddBillableItem accepts quantities (CORE-16442) [I]; pre and after hooks for module custom
  button commands (CORE-16484) [I]; free domains of active services hide the manual renewal option (CORE-16034) [U];
  transferring a service to another client cancels its outstanding invoices (MODULE-7557) [U].
- **Fixes (selected).** Overpayment after a refund of an earlier overpayment is credited to the client (CORE-15657)
  [U][R]; final invoice for a cancelled graduated-metric product aggregated correctly (CORE-15692) [U]; MZN marked as
  a fractional currency (CORE-15993) [R]; tax rate noted on zero-amount invoices (CORE-16836) [U]; piped-ticket sender inspection (CORE-16527) [R]; honour
  clientip in AddOrder (CORE-16312) [I].
- **Removals.** Enom "new TLDs" pre-order addon.

</details>

<details>
<summary>8.3 (2021-10-13), 108 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-3/8-3-change-log/).

- **Features.** Payment gateway dispute management; affiliate commission reversal; DNS domain validation views;
  friendly product URLs; admin notes splittable into tickets.
- **Behaviour changes.** Tax is not applied to exempt clients under inclusive tax (CORE-8243) [U][R]; with signup
  anniversary prorata plus product prorata, next invoice date is set equal to next due date (CORE-14615) [U];
  recurring price excludes the setup fee after a recurring promotion ends (CORE-16947) [U][R]; GetTLDPricing returns
  renewal price even when set to 0.00 (CORE-16920) [I]; manual transactions refused for an invalid invoice id
  (CORE-17099).
- **Fixes (selected).** Upgrade or downgrade with a promotion code over-credited (CORE-16056) [U][R]; tax for orders
  with multiple quantities (CORE-15538) [R]; subtotal with inclusive tax (CORE-16911) [R]; Stripe fees recorded
  wrongly for decimal currencies (MODULE-7701, CORE-17189) [R]; affiliate deletion broke invoice viewing and card
  processing (CORE-17197, CORE-17239, CORE-17294) [R].

</details>

<details>
<summary>8.4 (2022-01-18), 116 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-4/8-4-change-log/).

- **Features.** SSL listing, site seals, mailbox aliases for Open-Xchange, faster WordPress checkout.
- **Behaviour changes.** Services with unpaid invoices cannot be transferred to another client (CORE-17112) [U];
  invoice numbering reset trigger corrected on pay and generate (CORE-17134) [U][R]; product custom-field friendly names on
  invoice lines (CORE-10841); API grace for expired licenses (CORE-16872) [I].
- **Fixes (selected).** Upgrade calculation under inclusive tax (CORE-17387) [R]; addon suspend command (CORE-16979);
  stored Stripe fee amounts (CORE-17189, CORE-17442) [R]; PayPal events delayed by review hold (MODULE-7631) [U];
  duplicate 3-D Secure callbacks from SagePay (MODULE-7458) [U].

</details>

<details>
<summary>8.5 (2022-06-09), 93 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-5/8-5-change-log/).

- **Features.** Cart cross-sell recommendations; provisioning-module custom actions; Plesk metric billing; cPanel SEO
  and Digicert multi-year plans via MarketConnect.
- **Behaviour changes.** ServiceDelete hook fires when an order is deleted (CORE-17376) [I]; hooks loading no longer
  evaluates an index file (CORE-17411) [I]; AddOrder accepts non-FQDN domain values for non-domain services
  (CORE-17252) [I]; invoices with group discount and addons that come to zero are marked paid (CORE-17726) [U].
- **Fixes (selected).** Client can pay an existing invoice when its gateway is hidden from the order form
  (CORE-16548); promocode URL parameter honoured with friendly URLs (CORE-17266); correct template for domain renewal
  notices (CORE-17545); required-product promotions for admin orders (CORE-17226).
- **Security.** 8.5.2 is security only.

</details>

<details>
<summary>8.6 (2022-11-03), 112 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-6/8-6-change-log/).

- **Features.** PHP 8.1 support; gateway selection through Apps and Integrations; Microsoft OAuth for incoming and
  outgoing mail.
- **Behaviour changes.** VAT number format enforced in the client area (CORE-17800) [U]; tax labels follow the tax
  rules of the client, not the billing contact (CORE-17525) [U]; AddClient duplicate-email message reworded
  (CORE-18112) [I]; non-decimal currency payments no longer create fractional credit (CORE-17036) [U][R].
- **Fixes.** About twenty PHP 8.1 crashes (100%-discount invoices, 100% promo recalculation, deleted services on
  paid status, domain transfer, outgoing amount without invoice id, server sync, exchange-rate update when the base
  currency is not in the ECB feed) [R]. Many of these are the "zero total" path again. PayPal Checkout payable when a
  recurring service is 0.00 (MODULE-7696).
- **Removals.** Old PayPal Express module; obsolete gateway files.

</details>

<details>
<summary>8.7 (2023-03-21), 159 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-7/8-7-change-log/).

- **Features.** CentralNic Reseller module; NordVPN and 360 Monitoring via MarketConnect; final notice for the end of
  Smarty PHP tags.
- **Behaviour changes.** Billable hours and quantities accept two decimals (CORE-16535); quote quantities accept
  decimals (CORE-18592) [U]; termination date updated when a service is terminated from the client page (CORE-18389);
  notification rules evaluated for upgrade and downgrade orders (CORE-18189); upgrade orders honour the default
  order-form template (CORE-10317).
- **Fixes (selected).** Next due date formatting in paginated lists (CORE-18330); domain expiry date format
  in the sync report (CORE-13323); currency selection from footer (CORE-18556); hard block on deleting a currency
  wrongly applied (CORE-18562) [U]; Stripe orders proceed when the conversion currency no longer exists
  (MODULE-7734) [U]; deleted affiliate referral broke invoice display via pending commission (CORE-17695) [R].
- **Removals.** Payson gateway. Security: 8.7.3 security only.

</details>

<details>
<summary>8.8 (2023-09-21), 76 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-8/8-8-change-log/).

- **Features.** On-demand service renewals (FEATURE-3195): a client can renew ahead of the normal invoice date. Encrypted
  MySQL connections; Stripe Link; WP Squared provisioning.
- **Behaviour changes.** Cancellation date updated when invoices are cancelled during account closure (CORE-18337) [U];
  addon suspension suppressed when invoices are in Payment Pending (CORE-17899) [U]; `invoice_url` merge field for
  the invoice-created email (CORE-18203); RBI compliance updates for Stripe recurring payments (MODULE-7925) [U].
- **Fixes.** PHP 8.1 errors when suspend or terminate days are undefined (CORE-18698, CORE-18746) [R].
- **Removals.** SlimPay, Heart Internet.

</details>

<details>
<summary>8.9 (2024-03-06), 37 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-9/8-9-change-log/).

- **Features.** On-demand renewals for addons; PayPal Payments and PayPal Card Payments; view-only invoice access for
  client users (FEATURE-3421).
- **Behaviour changes.** Setting the next invoice number to an unsupported value is prevented (CORE-7657) [U];
  Open-Xchange cannot attempt billing-cycle upgrades (CORE-18744).
- **Fixes.** Renewal order line shows the recurring amount, not the first-payment amount (CORE-18927) [U];
  triennial invoice pricing once a promo's recurrence limit is reached (CORE-18944) [U][R]; invoice aggregation
  defect from the RC (CORE-19086) [R].

</details>

<details>
<summary>8.10 (2024-04-24), 24 entries</summary>

[Change log](https://docs.whmcs.com/releases/8-10/8-10-change-log/).

- **Features.** **Auto-cancel overdue invoices** (FEATURE-3759) with an automation status view (FEATURE-3794);
  aggregation for the cancellation skips invoices with partial payment or credit applied (FEATURE-3792) [U];
  Sitejet Builder; health warning when behind Cloudflare.
- **Behaviour changes.** Manual invoice payments stamp the current time (CORE-19178) [U]; View Invoices permission is
  enough to download invoice PDFs (CORE-19163).
- **Fixes.** Webhook handlers log history to the gateway log (FEATURE-3687).

</details>

<details>
<summary>8.11 (2024-09-18), 84 entries, 31 undisclosed security lines</summary>

[Change log](https://docs.whmcs.com/releases/8-11/8-11-change-log/).

- **Features.** reCAPTCHA v3 and hCaptcha; prevent clients from closing tickets; PHP 8.2; "Cancel Invoices" admin
  permission (CORE-19173); Stripe app key generation.
- **Behaviour changes.** Invoice total recalculates live while editing line items (CORE-18991) [U]; duplicating an
  invoice no longer copies applied credit (CORE-19228) [U]; sequential paid-invoice numbering stops changing when the
  default country changes (CORE-19241) [U][R]; payment-confirmation email sent for manual transactions added from the
  invoice screen (CORE-19244); webhook rejection to Stripe is non-blocking so the event still reaches other systems
  subscribed on the same account (MODULE-7915) [U].
- **Fixes.** Item data lost on save while managing an invoice (8.11.1, CORE-19649) [R]; running tax total when
  editing a quote (CORE-18934); crediting on a currency GoCardless does not support (MODULE-8126); invoice assigned
  to a gateway whose files are gone (CORE-19400) [U]; cron unaffected if the host is on the IP ban list (CORE-19202).
- **Security.** 8.11.3 (2025-06-03) carries the bulk of the 31 undisclosed items, plus the PayPal partial-capture fix
  (MODULE-8180 / WHMCS-21375), the refund timestamp (WHMCS-19688) and overpayment credit removal (WHMCS-19734).

</details>

<details>
<summary>8.12 (2025-01-15), 67 entries, 31 undisclosed security lines</summary>

[Change log](https://docs.whmcs.com/releases/8-12/8-12-change-log/).

- **Features.** Scheduled actions on tickets (including creating one during a reply), ticket pinning, admin user
  invites, opt-in usage tracking.
- **Behaviour changes.** Quantity fields added to GetClientsProducts, GetClientsAddons, GetProducts (with min and
  max) and to AddOrder for addons (WHMCS-19445 to 19449) [I]; Estonia VAT corrected to 22% (WHMCS-19488) [U]; renewal
  detection performance (WHMCS-19764).
- **Fixes.** **Prorata over a daylight-saving change no longer bills an extra day** (WHMCS-11766) [U]; pending-invoice
  check before service upgrades (WHMCS-19397) [U]; AddTransaction works without a description (WHMCS-19780) [I];
  user invoice permission (WHMCS-19691).
- **Security.** 8.12.2 (2025-06-03; the same fix is also listed in 8.11.3 and 8.13.1): PayPal Payments captured the full amount instead of the remaining balance on a
  partly paid invoice (WHMCS-21375) [U][R]. Treat this as a money bug fixed in a security release.

</details>

<details>
<summary>8.13 (2025-05-08, LTS), 160 entries, 55 undisclosed security lines, seven patches</summary>

[Change log](https://docs.whmcs.com/releases/8-13/8-13-change-log/).

- **Features.** New getting-started wizard; cron over HTTP restricted to an IP allow list (WHMCS-26198); admin can
  change user passwords; Troy card type; SocialBee; ticket scheduled actions extended (reply, note, custom field).
- **Behaviour changes.** Disabling domain auto-renew now cancels the renewal invoice (8.13.1, WHMCS-19766) [U][R];
  daily cron completes when late fees are on but no amounts are set (WHMCS-18912) [U]; ability to change an
  invoice's payment method needs Manage Invoice (WHMCS-19892); itemised invoice changes in the activity log
  (WHMCS-20046, WHMCS-22233); on-demand renewal availability logic for shorter months (WHMCS-20020) [U]; custom client
  VAT field used by auto-exemption in the client area (WHMCS-19970) [U].
- **Fixes (selected).** Credit attributes on refund (WHMCS-19887); credit reconciliation with several overpayments
  (WHMCS-19876) [U][R]; UK VAT validation again (WHMCS-21701) [R]; AddClient accepts a blank address (WHMCS-19870) [I];
  order processing when prorata is on but the prorata date is unset (WHMCS-19963) [U]; Skrill callback currency
  (WHMCS-21272); transaction time shown on the invoice (WHMCS-19845).
- **Security and removals.** Patches 8.13.4 to 8.13.7 (2026-06 to 2026-09) are security-hardening: reports, password
  reset, user logout, invoice-processing hook points (WHMCS-26206), email previews, Stripe and PayPal invoice payment.
  Asiapay, Gate2shop, Inpay, Paypoint and Hipchat modules removed; health check for deprecated modules added.

</details>

<details>
<summary>9.0 (2026-01-20), 161 entries, 25 undisclosed security lines, nine patches (9.0.1 to 9.0.9)</summary>

[Change log](https://docs.whmcs.com/releases/9-0/9-0-change-log/), [notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/).

- **Features.** Credit notes (WHMCS-23462); VAT compliance and validation at checkout (WHMCS-22633) with an admin
  dropdown (WHMCS-23184); Nexus theme and cart (WHMCS-23171); broader cart API coverage for buy flows (WHMCS-20187);
  AI domain suggestions; Smarty 4 (WHMCS-22591); CSV customer import in ImportAssist (WHMCS-22711).
- **Behaviour changes.** Upgrades and downgrades validate resources to stop overages (WHMCS-21795) [U]; EmailPreSend
  hook can attach files (WHMCS-21993) [I]; "Allow Smarty Tags" setting removed (WHMCS-23636); basic-auth mail for Google
  removed (WHMCS-24649) [I]; transfer and renewal API error handling (WHMCS-24834) [I]; billing-note transactions
  excluded from cashflow reports (WHMCS-24950) [U].
- **Fixes.** Undercharging on some prorated addon actions (WHMCS-19395) [U][R]; NULL first and last name on AddClient
  (WHMCS-23674) [I]; **cancelled services renewed in error** (9.0.2, WHMCS-25228) [U][R]; invoice generation error
  handling (WHMCS-25250); late fees on mass-pay invoices (WHMCS-25149) and existing late fees on upgrade to 9.0
  (WHMCS-24931) [R]; **downgrade credit calculation** (9.0.5, WHMCS-22846) [U][R]; invoice chunking logic (9.0.6,
  WHMCS-25216) [U]; invoice-creation hook when merging invoices (9.0.7, WHMCS-26336) [I]; null-IV data migration
  (WHMCS-27325) [U].
- **Security.** 9.0.5 and later add a stronger encryption method for stored sensitive data (WHMCS-26073), stronger
  random guest-ticket access keys (WHMCS-26075), blocked direct access to hidden knowledgebase and campaign
  attachments (WHMCS-26464), a "View Clients Summary" permission to download client files (WHMCS-26339).
- **Removals.** TrustCommerce, CashU, Hipchat and four more gateways.

</details>

<details>
<summary>9.1 (2026-10-20), 81 entries, 3 undisclosed security lines</summary>

[Change log](https://docs.whmcs.com/releases/9-1/9-1-change-log/), [notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/).

- **Features.** Invoice immutability and credit-note controls (WHMCS-27608); credit-note overview pages and print
  (WHMCS-24543, 24545), reports and widgets (WHMCS-24550); balance column on invoice lists (WHMCS-24544); Stripe
  Dynamic Payments gateway (WHMCS-25096); unattended automatic updates for maintenance releases (WHMCS-22150);
  configurable mail import limits (WHMCS-26207); PHP 8.4; Setup Tasks dashboard widget; Iris admin theme preview;
  automatic MarketConnect account registration.
- **Behaviour changes.** Unused credit-note and debit-note number increment settings removed (WHMCS-25146) [U];
  paid invoices removed from the Income by Product report (WHMCS-25887) [U]; fraud-related invoice statuses and the
  "Mark Paid" button reworked (WHMCS-25294, WHMCS-25172); automatic domain renewal setting handling (WHMCS-25709) [R];
  apply-once promo codes were being rejected on other orders (WHMCS-27326) [R].
- **API.** REST v2 gains headless checkout and payment endpoints (WHMCS-27266); bundle name and product type in cart
  items (WHMCS-24522, 24523); addon billing cycle handling (WHMCS-27208); API endpoint URLs validated (WHMCS-27409) [I].
- **Removals.** PayPal Basic and PayPal Checkout modules (WHMCS-28470).

</details>

## 3. Recurring bug areas

Counts are entries on the 16 pages whose text matches the area (an entry can count in several). These are rough signals,
not defect rates.

| Area | Entries | Reading |
| --- | --- | --- |
| Invoices (any) | 158 | The ledger object everything touches; fixes span creation, edit, aggregation, hooks, display |
| Auth, passwords, sessions, 2FA | 112 | Mostly 8.13 and 9.0 hardening |
| API (any) | 97 | Validation gaps and missing parameters, found one action at a time |
| PHP 8.1 crashes | 82 | Eight releases of "undefined value now errors"; nulls where a number was assumed |
| Stripe | 79 | Fees, currencies, webhooks, SEPA and ACH, 3-D Secure |
| Currency, decimals, rounding | 58 | Fractional versus non-fractional currencies come back in 8.1, 8.2, 8.3, 8.6, 8.7 |
| PayPal | 49 | Five generations of module; partial-payment capture a money bug in 8.12.2 |
| Hook points | 42 | Wrong arguments, hooks not firing on API or merge paths |
| Quantity and multi-quantity | 38 | Promotions and tax with quantity above one, repeatedly |
| Upgrade and downgrade | 38 | Credit, promo, tax and rounding interact |
| Credit, refund, overpayment | 35 | Overpayment reversal and reconciliation again and again |
| Tax and VAT | 28 | Inclusive tax with exemptions, UK and EU number checks |
| Cron and automation | 27 | Start-up health, undefined settings, timezones |
| Promotions | 27 | Recurrence limits, apply-once, quantity, currency |
| Domain sync and renewal | 21 | Auto-renew toggle, IDN, expiry formats |
| Mail pipe and import | 18 | File names, attachments, header folding, OAuth message ids |
| Prorata | 10 | Few entries, but each one is a billing error: DST overbill, addon undercharge, one-time cycle |

## 4. What this tells us

### 4.1 Edge cases to add to our specs

Our specs already cover much of this ground (idempotent callbacks, anchored month arithmetic, company-timezone dates,
partial payments, mass-pay batch allocation, auto-renew cancelling the renewal invoice, late fee on balance only,
refund polling). Keyword search of the product specs found no mention of the following. Each is a candidate for a
line in the named section, to be checked first (some may be covered under other wording).

| # | Behaviour seen | Spec and section to check or extend |
| --- | --- | --- |
| 1 | Prorating across a daylight-saving change billed one day too many (WHMCS-11766). Nepal has no DST, but other markets do and prorata should count calendar dates, not 24-hour spans | `services.md` section 4 (dates and renewals), `orders.md` section 5 |
| 2 | Prorata with a one-time cycle is nonsensical and was blocked (CORE-14492); prorata on addons was added later and undercharged in some actions (WHMCS-19395) | `orders.md` section 5, `services.md` section 5; add "prorata never applies to one-time; addon prorata uses the same day count as the parent" |
| 3 | Refund of an overpaid invoice, a second overpayment, and removing overpayment credit each caused reconciliation bugs (CORE-15657, WHMCS-19734, WHMCS-19876) | `billing.md` section 4 and 13; add a test matrix: overpay, refund, overpay again, remove credit, refund with several overpayments |
| 4 | A gateway that captures the invoice total instead of the remaining balance after partial payment (WHMCS-21375, shipped as a security fix) | `billing.md` section 6 (gateway contract) and `payments-stripe.md`, `payments-gateways-expansion.md`: the amount sent to a gateway is always `balance`, asserted in the driver contract test |
| 5 | Inclusive tax interacts with exemptions (CORE-8243), upgrades (CORE-17387), subtotal (CORE-16911) and Nexus cart display (WHMCS-24830) | `billing.md` section 5: add the combinations exempt x inclusive x upgrade x multi-quantity as acceptance cases |
| 6 | Promotion corner cases: apply-once with quantity above one, recurrence limit reached on long cycles, setup fee leaking into the recurring price after a promo ends, promo on domain renewal affecting only registration, lifetime promos on upgrades (CORE-15518, 18944, 16947, 14101, 14465) | `orders.md` section 5 and 12 |
| 7 | Fixed-amount promo in a non-default currency (CORE-16951) and a currency deleted while still referenced (CORE-18562, MODULE-7734) | `orders.md` section 12; `platform-admin.md` currencies: deleting a currency in use must be blocked with the list of references, not partly applied |
| 8 | Tax label and rules follow the client, not the billing contact (CORE-17525); tax rate must still be printed on a zero-total invoice (CORE-16836); negative rates appear in mail (CORE-15211) | `billing.md` section 5 and 9 (PDFs) |
| 9 | Invoice number sequences: reset trigger (CORE-17134), unsupported "next number" value (CORE-7657), sequential paid numbering changed by an unrelated setting (CORE-19241) | `billing.md` section 3 and 14; add "a numbering setting cannot be changed by editing another setting" |
| 10 | A suspended or terminating service with payment pending must not be suspended or terminated, for addons too (CORE-14427, CORE-17899) | `services.md` section 10 and 14 (we have the payment-pending rule for services; confirm addons) |
| 11 | Cancelled services renewing again (WHMCS-25228); disabled auto-renew not cancelling the renewal invoice (WHMCS-19766) | `services.md` section 4 and 14: add an invariant test "no renewal invoice for a cancelled or terminated service, from any path, including on-demand renewal" |
| 12 | Transfer of a service between clients: block with unpaid invoices (CORE-17112) or cancel outstanding invoices (MODULE-7557) | `services.md` section 14 currently says invoices stay; decide deliberately which of the two rules we want (owner question) |
| 13 | Account closure and termination dates (CORE-18337, CORE-18389); invoice cancellation during closure | `services.md` section 3 and 6 |
| 14 | Affiliate deleted or referral removed while commissions or invoices reference them broke invoice display and the card-charge task three times (CORE-17197, 17239, 17294, 17695) | `affiliates.md` edge cases: commission rows must survive client deletion; run-time jobs skip deleted affiliates |
| 15 | Mail import: attachments with unusual names, folded headers, PDF attachments coming through blank, sender checks, OAuth message ids needed to delete after import, per-run import limits (CORE-13893, 15423, 16528, 16527, 18174, WHMCS-26207) | `support.md` section 6 and 13 |
| 16 | Webhook retry rules: a provider needs a non-blocking rejection so the event still reaches other subscribers (MODULE-7915); delayed events from a provider review hold (MODULE-7631); duplicate 3-D Secure callbacks (MODULE-7458); email failure must never change invoice status (MODULE-7471) | `payments-stripe.md` webhooks, `billing.md` section 13: add the last as an explicit rule |
| 17 | Non-fractional currencies (JPY-like): a fractional credit after payment (CORE-17036); a currency mislabelled as fractional (MZN, CORE-15993). Nepali rupee has paisa, but multi-currency hosts will hit this | `billing.md` section 2 (money type), `platform-admin.md` currencies |
| 18 | Mass pay with late fees (WHMCS-25149), plus invoice merge firing the "created" hook (WHMCS-26336) | `billing.md` section 7 and 12 |
| 19 | Setting an invoice's payment method needs its own permission (WHMCS-19892); cancel-invoice, add-funds and create-upgrade each have separate permissions | `billing.md` section 10 and `platform-admin.md` roles: check that these four are separate permissions |
| 20 | Manual payment time defaults to now (CORE-19178); refund transaction date was wrong (WHMCS-19688); API takes an explicit time (CORE-15881) | `billing.md` section 4, `public-api.md` section 4 |

### 4.2 Fragile areas to get right early

These are the areas where WHMCS shipped the same class of bug in three or more releases. Each is a place to write the
test first.

1. **Money arithmetic around quantity, promotion, tax and currency, together.** One test matrix with all four
   dimensions beats separate suites.
2. **The credit ledger.** Overpayment, refund, credit removal, reversal. Our ledger design (signed entries, balance
   after) helps; the matrix in row 3 should be an acceptance criterion.
3. **Webhook and payment-state transitions.** Stripe (79 entries) and PayPal (49) are the two most patched modules.
4. **Zero totals.** 100% promotions and zero-balance invoices crashed in at least six places in PHP 8.1.
5. **Deleted-thing references.** Deleted affiliates, deleted services on invoices, deleted gateways, deleted
   currencies. Our specs use soft deletes in places; make "referenced rows cannot vanish" a general rule in
   `docs/architecture.md`.
6. **Hooks and events on every code path.** Merge, API update, import, module command. The event must fire from the
   domain action, not from the controller (ADR 0008 already points this way).
7. **Security as a continuous process.** Fifteen maintenance releases on the 8.13 and 9.0 lines in 2026 alone, most of them hardening. Plan a documented patch window and a
   disclosure page; being the host that explains its fixes is a selling point.

### 4.3 Importer impacts

- **Source versions.** Any WHMCS from 8.0 to 9.1 can be a source. Read `WhmcsDetails` first; behaviour differs by
  version: GetUsers exists from 8.1, quantity fields in GetClientsProducts and GetProducts from 8.12, credit notes from
  9.0, AddClient email uniqueness enforced from 8.0, GetClientPassword documented as non-functional since 8.0
  (already in `client-import.md` section 3).
- **Data written by older versions.** Fractional credit from non-decimal currencies (fixed 8.6.1), over-credited
  downgrades (fixed 8.3, 9.0.5), duplicated credit on duplicated invoices (fixed 8.11), invoices whose items were
  lost on save (fixed 8.11.1), next-due-date drift under prorata and DST (fixed 8.0.2, 8.12). The import validation
  report (`migration.md` section 8, `invoice-domain-import.md` section 6) should check line totals against
  invoice totals and flag, not repair, such rows.
- **Credit notes.** On 9.0+ sources, credit and debit notes exist as documents; the importer specs treat invoices only.
  Add a read of credit-note data when the REST v2 or API exposes it, or document the gap (`invoice-domain-import.md`
  section 4 and 17).
- **Invoice numbers.** WHMCS keeps a separate paid-invoice number sequence when sequential paid numbering is on;
  import both the id and the printed number (`invoice-domain-import.md` section 6).
- **API behaviour changes to guard against.** GetInvoices returned a wrong `numreturned` before 8.0
  (CORE-14557): the existing "unique ids must equal totalresults" check is the right defence. Paging uses
  `limitstart` and `limitnum`; AddClient unique-email rules do not matter for read-only import.
- **Hooks to events.** The change logs show which hooks changed meaning: ClientDelete became PreDeleteClient (8.0),
  ServiceDelete now fires on order delete (8.5), ClientEdit on tax-status toggle (8.1), UserEdit on email change
  (8.2), InvoiceCancelled via the API (8.1), pre and after hooks for module custom buttons (8.2), TicketMerge, TicketSplit,
  UserAdd (8.0), EmailPreSend attachments (9.0), PreInvoiceAutomaticCancellation (9.1). A `compat:whmcs-hooks` map
  should note these as the useful subset (see the coverage note).
- **Module compatibility.** Illuminate 7 to 9 and Smarty 4 in 9.0 broke third-party modules; PHP minimum 8.2 from 9.0;
  PayPal Basic and Checkout gone from 9.1; Stripe, Stripe ACH, SEPA deprecated for Stripe Dynamic Payments. For
  switching hosts, the list of gateways their source install actually uses decides what we need first.

## 5. Top ten changelog-derived edge cases to check against our specs

1. Partial payment: the gateway must be asked for the remaining balance, never the invoice total (WHMCS-21375).
2. Cancelled or terminated service must never get a renewal invoice from any path (WHMCS-25228), and turning auto-renew
   off must cancel an unpaid renewal invoice (WHMCS-19766).
3. Overpayment, refund of overpayment, second overpayment and credit removal must reconcile (CORE-15657, WHMCS-19734, 19876).
4. Prorata: never on one-time cycles; day counts by calendar date so a clock change never adds a day (CORE-14492, WHMCS-11766).
5. Inclusive tax with exempt clients, upgrades and quantities above one (CORE-8243, 17387, 15538).
6. Promotion rules with quantity, recurrence limit, setup fee after expiry and non-default currency (CORE-15518, 18944, 16947, 16951).
7. Downgrade credit never exceeds what was paid, including with a promotion (CORE-16056, WHMCS-22846).
8. Non-fractional currencies must not produce fractional credit (CORE-17036), and a currency in use cannot be deleted half way (CORE-18562).
9. Numbering settings must not interact: changing country, resetting a sequence or setting the next number (CORE-19241, 17134, 7657).
10. Deleted affiliates, services, gateways and currencies must not break invoice display or scheduled jobs (CORE-17197, 17239, 14659, 19400).
