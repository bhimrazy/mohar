# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Progress, status and screenshots: [docs/progress.md](docs/progress.md).

## [Unreleased]

### Changed

- **A login without a password is told to set one when a sensitive action needs step-up (#274, ADR 0018 §4).** Such a
  login (for example from a social or magic-link sign-in) can never pass step-up, whatever the session holds, including
  a passkey confirmation. The 423 response carries `code: "password_not_set"` and the Inertia validation response
  carries `step_up_reason`; neither holds secret data. The confirm dialog and the confirm-password page show "Set a
  password" (linking to Security settings) instead of a password field. Shared props carry `auth.user.has_password`.
  Upgrade: migration `make_users_password_nullable` makes `users.password` nullable (on PostgreSQL it takes a short lock
  on `users`); no behaviour changes for logins that have a password.

### Fixed

- **Simulators no longer fail to start at random (#370).** A scenario's `scenario.json` is now written to a temporary file and renamed into place. Rewriting it in place (after the processes were spawned) could let a starting process read it empty, which failed CI with "did not become ready". Test-only tooling; no app change.

- **Report PDFs: long names wrap, headings line up, dates sit next to the next column (#357).** A long text cell (a legal
  name) now wraps inside its column, and ends in an ellipsis once it would take more than four lines, instead of being
  cut mid-word with no marker; the CSV always holds the full text. The cut never splits a character (a Devanagari
  conjunct or an emoji sequence). Pages are planned from the printed height of each row, measured with the PDF font's
  real glyph widths (so all-capital names, which are wider, still fit), and the header block, page totals and grand
  totals count in that height, so the PDF has the pages the export planned; a page with tall rows holds fewer rows.
  Numeric headings (quantity, amounts) are right-aligned like their figures; dates and times are left-aligned in a
  narrow column. "Printed by ... at ..." shows the company time zone, as before (now covered by a test). A report with
  its own PDF table view is paged as if it used the default column widths. No schema, permission or setting change.
- **Services follows the company calendar (#344, ADR 0013 Amendment 1).** Services no longer reads the UTC date. For a Kathmandu company, between 00:00 and 05:45 local time, these used the previous day and now use the company day: when a service counts as overdue (the list flag, the overdue filter, the renewals widget and the unsuspend-on-payment check), the date of an immediate cancellation, a new add-on, a terminated service, an ordered service and an imported account (its registration date and default due date), the check that an import's next due date is not in the past, and the module-log prune cutoff. The upgrade and downgrade proration now starts a period at the company midnight, so a change applied in the first hours of the period's first day is counted in the quote. The Services dashboard's MRR history picked the wrong month ends on the 29th to the 31st of a month; it now uses the previous month, and its 12-month window compares timestamps with the company day start. Upgrade notes: no schema or permission change; stored dates are not rewritten. The UTC-date scan in `CompanyClockScanTest` now covers `app/Modules/Services`, and its timestamp-column check also matches table-qualified names such as `services.updated_at`. Known gap: the MRR query still takes the ended day of a service with `date(updated_at)` in SQL (the UTC date); it moves with the dashboards' `dayBounds()` migration.
- **The storefront cart and checkout no longer scroll sideways on phones, and the checkout sidebar price stays inside its card (#353).** The two-column layout let long rows widen the page to 411-425 px at 390 px; the columns now shrink to the screen (checked at 360, 390 and 1440 px, light and dark). Domain names in the checkout summary and cart lines wrap instead of being cut off, so two domains that differ only by extension read differently. No schema, setting or permission change.

- **Registrar jobs for one domain no longer overlap, and none is dropped while waiting (#14).** Registering, transferring, renewing and syncing a domain now share one lock per domain (before, the three jobs only excluded their own kind). A job that finds the domain busy goes back on the queue and tries again after 30 to 45 seconds. The jobs no longer use a fixed number of tries (every wait counts as an attempt in Laravel, so a paid renewal could have failed just by queuing behind another job): they fail after 3 real errors, timeouts or crashes, or if they have not run 24 hours after they were queued, and then alert staff in plain words as before (the registrar queue can be down for hours and still run paid work when it returns). A sync that meets a busy domain skips it (the next hourly run picks it up; **Sync now** says another registrar action is running). Name-server, contact, lock and EPP-code changes stay outside the lock. Upgrade notes: the cache store must support atomic locks (Redis or database); on the `sync` queue driver a job that meets the lock is dropped, so use a real queue driver; the lock must not be taken inside a database transaction (the database cache store aborts a surrounding PostgreSQL transaction when the lock is taken); queued register, transfer and renew jobs keep their unique lock for 25 hours instead of 1. No schema or permission change.

- **A refund that times out is no longer refunded twice (#334).** A gateway refund now fails and frees its amount only when the provider definitely declines it, or when nothing was ever sent. A timeout, a server error, a pending answer or a crashed request leaves the refund **Pending** (shown as "Being confirmed") with its amount reserved, and staff see "The refund is being confirmed with the provider" instead of an error that invites a retry; the invoice shows a banner ("Don't refund again until this is settled") and a second gateway refund of the same payment is refused. A pending refund settles on the same row, so the provider's idempotency key never changes, through the provider's refund callback (checked against the refund we sent: payment, amount and currency), **Check refund**, or staff. **Check refund** is offered for Stripe: it repeats the original request with the same idempotency key, so Stripe returns the refund it made or makes it once; after Stripe's 24-hour window it lists the payment's refunds instead and treats absence as "no such refund" only past 24 hours. A Check replay Stripe did not process (a rejected or missing key, an idempotency conflict) never releases the amount. A refund Stripe keeps answering "unknown" for three days can be settled by hand like the others. For Khalti, which cannot be asked, staff choose **Confirmed at the provider** (with the provider's refund reference, which is booked and deduped on) or **Not refunded** (releases the amount), 10 minutes after the request if it never answered. Both need a recent password confirmation and `billing.refunds.manage`, and are written to the activity log. A late error never undoes a refund the callback already booked. A provider refund that arrives for a refund already released is booked once (by the provider's refund id) and put on the review list. Releasing never restores more than the payment can still give back after a chargeback. The dashboard lists **Refunds waiting for the provider** (older than 10 minutes), the Transactions page has a "Waiting for provider" filter, and every transaction row shows a status. Stripe and Khalti server errors (5xx) now count as unknown, not declined. New provider contract `LooksUpRefunds`; `RefundRequest` gains an optional `sentAt` (kernel changelog). Schema: new partial index `transactions_refund_pending_index` (built concurrently on PostgreSQL). No new permission.

### Added

- **A one-time notice about the automation times, and a daylight-saving help line (#341, #342).** After upgrading, staff who can change settings (`core.settings.update`) see a dismissible notice on the admin dashboard: **Daily automation time** and **Rate update time** now mean company time, no value was converted, and they should check both. Dismissing it hides it for that person only; staff added after the upgrade and fresh installs never see it. **Daily automation time** now says that in a time zone with daylight saving the run can be skipped or happen twice on the day the clocks change (Nepal has none, so Nepal-time runs are never skipped or repeated). A notice that does not exist returns 404 on dismiss, and each notice has its own audience permission. Upgrade notes: new table `staff_notices` (one row per existing staff member); no new permission.

- **Report registry and one export pipeline (ADR 0025, VAT PR 0c).** Modules and packages can now register reports
  (`ReportRegistry`) and export them as CSV or PDF through one path that checks the permission, limits the size, and
  writes a `report.exported` entry to the activity log (report, layout, format, filters, row count). Each report
  adds `reports.<key>.view` and `reports.<key>.export` to the role editor under "Reports"; an export that carries
  tax or personal identifiers in bulk needs a recent password confirmation. CSV files are UTF-8, optionally with a
  byte order mark so Excel on Windows reads Nepali names (new settings **Reports**: `reports.csv_bom`, default off,
  `reports.sync_export_max_rows`, default 5,000, and `reports.sync_pdf_max_rows`, default 1,000, because a PDF is
  built in memory; a larger export is refused with its row count and a request to narrow the filters; a file is
  never written past its limit). A cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is stored as text with a leading apostrophe,
  while amounts, counts and dates are written from their values, so a negative amount stays a number a spreadsheet
  can sum. PDFs are A4 landscape with the brand, amounts in the currency's display (`Rs 1,23,456.00`), a page total after each page, totals kept per currency, grand totals and "Printed by ... at
  ..." in company time. The client-import errors file now uses the same CSV writer (no change in its content). The activity entry lists only filters that are safe to log (not free text). Devanagari text typed by users does not render in PDFs (use the CSV). No
  report is registered yet (the VAT register follows), so nothing new is visible in menus. Queued exports and XLSX are
  deferred. Upgrade notes: no schema change; new permissions appear only when a report is registered.

- **Credit and debit note events (VAT PR 0b).** Two new kernel events, `CreditNoteIssued` and `DebitNoteIssued`, for the VAT credit-note work (ADR 0024 §6). Nothing emits them yet, so behaviour is unchanged. No schema or permission change.

- **Documents are dated on the company's calendar (ADR 0013 Amendment 1).** Invoice issue and due dates, document
  numbers (the yearly and monthly resets), quote validity, overdue checks, card expiry, the tax-rule effective date,
  receipt dates, Bikram Sambat dates and the daily automation run date now come from the company time zone (Settings,
  Company, `system.timezone`) instead of UTC. In Kathmandu an invoice published or paid at 00:05 is dated that day;
  before, it carried the day before until 05:45. Upgrade and downgrade invoices are dated the same way. The daily
  automation and the separate exchange-rate update start at their set time on the company's clock (they ran on server
  time, UTC), and the automation page and the exchange-rate page show the next run in company time. A stored zone name
  such as `Asia/Katmandu` or `Asia/Calcutta` is accepted and mapped to `Asia/Kathmandu` or `Asia/Kolkata`; a name that
  is not a time zone at all falls back to UTC, is reported, and shows a warning on the admin dashboard. New read-only
  command `php artisan billing:report-utc-dates` lists numbered invoices created, paid or last updated while the
  company date differed from the UTC date (in Kathmandu, 00:00 to 05:45), so the owner and the accountant can judge
  them; **existing invoices are not changed**. Upgrade notes: **the daily automation time and the exchange-rate update
  time (`core.automation_time`, `platform.rates_update_time`) now mean company time**, where they meant UTC, so check
  them once after upgrading (no stored value is converted). The first automation run after the upgrade uses the
  company date, so one run date can be skipped (a Kathmandu install deployed between 21:15 and 03:00 UTC runs 18 Oct,
  then 20 Oct); a date is never run twice, and the renewal, reminder, cancellation and suspension tasks select by `<=`
  windows, so the next run takes what the skipped one would have. To run a skipped date, use `php artisan
  automation:run --date=YYYY-MM-DD`. Core's `BusinessCalendar`, Orders' `CompanyClock` and Notifications' `MailClock`
  are replaced by the `CompanyClock` contract. No schema or permission change.

- **Extension details for registrars.** When a domain is ordered, the registrar can now ask for the extra details a
  registry requires for that extension, and they are asked for where the domain is ordered: the domain search, the
  product page ("Register a new domain") and the staff order form. The details are checked on the server (required,
  one of the listed options, no stray values), kept in the cart encrypted, stored with the pending domain
  (`domains.additional_fields`, no schema change) and sent with the registration; a transfer sends none. They are never
  written to the activity log, the registrar command log or an error message. ResellerClub asks for the purpose and
  who is registering for `.us`, and for the identification, policy reason and eligibility confirmation for `.com.au`;
  other extensions are unchanged; an ABN or ACN is checked for its length before payment. Each field says who qualifies,
  the cart and checkout show what was entered (ID numbers shortened to their last 3 characters), and staff see the stored
  details, with labels, on the domain page (read-only). Answers that a registry's error message repeats are masked. These
  ResellerClub attribute names are verified against the simulator only. Upgrade
  notes: no migration, no new permission; drivers without the capability behave as before; a domain ordered before
  this release has no details stored.

- **ResellerClub registrar (#280).** Hosts can register, transfer, renew and restore domains through ResellerClub:
  Admin → Domains → Registrars → ResellerClub takes the reseller id and API key (and separate demo credentials for
  test mode), one default customer or one customer per client, and an optional outbound proxy for the API allow-list.
  The driver also checks availability, syncs status and expiry, follows transfers, and sets name servers, the transfer
  lock, privacy and contacts, and returns the EPP code. Orders are placed without a ResellerClub invoice (the price
  comes off the reseller balance). Before it registers or transfers, it searches the client's own customer for an order of
  that name; an order is adopted only when the host already holds its id (a lost answer is settled by the host's
  reconcile step, created since the submission began), and any other order of the name is a conflict for staff, so a
  name in the reseller account cannot be taken over by typing it or its transfer code. In one-customer-per-client
  mode the ResellerClub username is generated from our client id (`client-{id}@{host of APP_URL}`) and an existing
  customer is adopted only on that exact username; the client's email is only a contact email. Reads, sync and the EPP code act only on the order id recorded from our own register
  or transfer answer (or the submission lookup inside our own window); sync never links an order it finds by searching,
  and says "no order is linked" when none is recorded (domains imported without one need the order id set by hand). A
  renewal is sent only when ResellerClub's expiry date equals ours; when ResellerClub is ahead, our expiry is updated
  from it and nothing is ordered again. A wrong transfer code gets its own message, apart from "code already sent". A
  registration that is still unconfirmed 15 minutes after submission and cannot be followed is listed in Needs attention
  and its sync reports the error instead of ok.
  Sync never cancels a domain on an unknown registry answer or a registration that never reached ResellerClub. Calls
  of a register, transfer or renew chain are time-bounded to fit the 180 s job, and the outbound proxy must be an
  http, https or socks5h address that passes the outbound policy. `.np` names never reach
  it. Verified against the ResellerClub simulator only (no live or demo account). No schema or permission change; the
  registrar shows up as a new, unconfigured entry.

- **ResellerClub simulator (#280, ADR 0016 S6).** Developers and CI can run ResellerClub's HTTP API as a local TLS
  simulator (`php artisan simulate:start resellerclub`): customers and contacts, availability, register, transfer with
  a later auth code, renew bound to the current expiry, restore, name servers, theft lock, privacy, contact changes,
  order details, order id and search, with ResellerClub's error envelope and async order states. Scenarios: premium
  and unavailable names, an unavailable registry, insufficient funds, a transfer waiting for its auth code, the
  allow-list refusal, a daily-reset style wipe, and the framework's timeout-after-commit and rate-limit faults.
  Development and tests only; no product, schema, permission or setting change.
- **Continue payment for Khalti (#251).** A client whose Khalti payment is still open (for example after closing the Khalti tab) sees **Continue payment** on the invoice, as with Stripe, and goes back to the same Khalti payment page. The hint beside it no longer mentions "use another method" when the gateway offers no such button (Khalti). It is offered only while the invoice still has a balance and Khalti's lookup says the session is `Initiated`. An expired, cancelled, completed or bank-pending session is never reopened: the attempt is settled from that one lookup, and the client is told the next step (for a bank-pending payment, that it is being confirmed). The page URL from Khalti is kept on the payment attempt, encrypted with `APP_KEY` (covered by `security:reencrypt`), dropped once the attempt is no longer pending, and never logged or sent to the browser before the click. For every gateway, Continue payment now refuses an invoice that no longer has anything to pay, and when the provider can't be reached it suggests another method only where that button exists. eSewa stays without Continue payment. Schema: new nullable column `payment_attempts.resume_url` (expand only). No permission or setting change.

- **Khalti and eSewa simulators (#208, ADR 0016 S5).** Developers and CI can run Khalti KPG-2 checkout (initiate, the hosted page, the return and the lookup, and refunds) and eSewa ePay v2 (the signed form, the payment page, the signed return and the status check) as local TLS simulators: `php artisan simulate:start khalti` or `esewa`. Control actions cover success, cancel, pending then completed or expired, amount mismatch, forged or badly signed returns, duplicate returns, lookup timeouts, and refunded or partly refunded payments. The drivers are tested against them in NPR with paisa. `simulate:e2e` and the opt-in `E2E simulators` CI job now also run the hosting lifecycle in NPR through eSewa and through Khalti. The Khalti and eSewa drivers take their endpoints from `INTEGRATION_ENDPOINTS` in `local` and `testing` only, like Stripe; with no override nothing changes. eSewa's Test connection now succeeds only on a redirect to `esewa.com.np` or one of its subdomains; a look-alike host such as `esewa.com.np.evil.example` used to pass. No schema, permission or setting change.

- **Identity photos shrink in the browser before upload (#288).** On the verification page a photo whose long edge is over 2,800 px, or whose file is over 1 MB, is scaled down (never enlarged) and saved as JPEG at quality 85 before it is sent; the EXIF orientation is applied, and an image with transparency stays PNG. A preview shows the old and new file size, and the client can choose another photo. A file that is not a JPEG, PNG or WebP (for example HEIC) is refused at once with a plain message; if the browser cannot shrink a valid photo, the original is uploaded and the server decides. The two file fields no longer overflow the card on desktop. The server checks and limits are unchanged. No schema, permission or setting change.

### Changed

- **Nepal-first demo data (#39).** The demo is now a Nepal install: NPR is the default currency, the company time zone is
  Asia/Kathmandu, dates read "22 Oct 2026" and Bikram Sambat dates show beside invoice dates. The showcase client is a
  Lalitpur company (Sujata Shrestha, Himal Labs Pvt. Ltd.) with a buyer PAN, NPR invoices at 13% VAT at the catalogue
  prices, eSewa and Khalti payments (when PaymentsNepal is installed) and a client-registered himallabs.com.np with no
  fee; no seeded invoice bills a `.np` domain. Eleven more generated Nepali clients follow, and one US client (USD, NY
  sales tax) stays last so multi-currency still shows. Catalog and domain list prices are converted from US dollars, so
  they are right under any default currency. Seeded invoices are always issued on or before their due date, including
  the 90-day history. The quote PDF prints "valid until" in the Date format setting, the invoice PDF prints the buyer's
  country name and one "PAN/VAT No." line when the seller's PAN is the VAT number, the client invoice shows "eSewa"
  as staff named it, and the client dashboard no longer ends with ".." after a company name ending in "Ltd.". Demo
  invoice numbers now pad to five digits (INV-01957), the Nepal install default. Demo data only: re-run `php artisan migrate:fresh --seed`; `db:seed` in production is unchanged; no schema, permission or
  setting change.
- **Simulator scenarios pick ports below the ephemeral range, per test worker (#209).** The simulators (dev-only) chose
  ports with `:0`, which the kernel takes from the range outgoing connections use, so a port could vanish before the
  simulator bound it and `PortRetryTest` failed under parallel runs. Ports now come from 20000 up to the kernel's
  ephemeral range, each probed by binding, in a slice per parallel worker. No schema or setting change.
- **Registrar submission refuses `.np` domains (#280).** A pending domain under a TLD marked "Registered by the client
  at the registry" (on by default for `.np` and its second levels), or a `.np` name outside the registry's second
  levels such as `asha.np`, is never sent to a registrar, even when staff created it with a registrar key. It stays
  Pending with the reason shown on the domain and in Needs attention, a staff task is logged and the
  registrar-failure alert is raised; staff cancel it or set its status. The `RegistrarRefs` contract now documents
  that recording a ref without a secret erases the stored one. No schema, permission or setting change.
- **Sizing and restore docs (CEO review 2026-10-22).** Every doc now gives 2 vCPU and 4 GB as the minimum for the
  production stack (it said 8 GB in `production.md` and the docs site), with the measured memory and when to grow
  (`sizing.md`). The backup and restore steps were run end to end once; the restore section now says how to remove a
  stack (`down -v` needs both colours' profiles), asks you to keep the `APP_KEY` of every backup, and has a checklist
  and the drill's timings. `deploy/bin/deploy` no longer needs GNU `realpath` (it stopped on macOS). No schema,
  permission or setting change.
- **Two time-boundary tests no longer fail on a second tick (#310, #322).** The auth throttle test and the KYC unlock-seconds test now freeze time, so Retry-After and the seconds left are measured from one instant. Tests only; no product, schema or permission change.

### Upgrade notes

- **Re-encode identity images stored before #286 (#287).** Run `php artisan kyc:reencode-images` once after upgrading,
  off-peak (`--dry-run` first). It re-encodes the images of documents uploaded before #286 through the same sanitiser
  as uploads, so their EXIF, GPS and device details are removed; new uploads are marked and skipped. It works in
  chunks, resumes after an interruption, never overlaps a second run, and writes one audit entry with counts only
  (event `kyc.images.reencoded`). An image it cannot read or re-encode is left as stored, flagged for staff and listed
  by id (exit 1). Start it only after an `APP_KEY` restart is complete; it refuses to start while `security:reencrypt`
  runs. Runbook: `docs/devops/kyc-images.md`. Migration `2026_10_22_770300` (expand only): nullable columns
  `kyc_documents.sanitised_at` and `sanitise_issue` with the partial index `kyc_documents_unsanitised_index` (`id` where
  `sanitised_at` is null, built concurrently on PostgreSQL), and table
  `kyc_image_rewrites` (paths of files being switched). On PostgreSQL the column step waits at most
  `DB_MIGRATION_LOCK_TIMEOUT` (3s) per attempt for its lock on `kyc_documents`, 4 attempts, then stops and names the
  session in the way; run the migration again once it has finished. New setting `kyc.reencode_decode_wait_seconds` (30). No
  permission change.
- **Bikram Sambat dates on invoices (#207).** New billing setting `billing.show_bs_dates`, off by default; no schema or
  permission change. The default text of the invoice emails and the service suspension email changed: the Issued, Due,
  Was due and Charge date rows print the BS date when the setting is on. `app:install` (run on every upgrade) brings
  unedited templates to the new text; edited templates keep their text and the editor offers the new default. Invoice
  PDFs rendered with BS dates are cached under a new path ending in `-bs.pdf`.
- **Identity images are re-encoded (#233).** PHP's `gd` extension with JPEG, PNG and WebP support is now required
  (`ext-gd` in `composer.json`; the production image already has it and its build checks the codecs). No schema or
  permission change. Documents uploaded before the upgrade keep their metadata until
  `kyc:reencode-images` (#287, above) re-encodes them. Six new settings in `kyc.php`: `max_image_pixels`
  (25 000 000), `max_buffered_jpeg_pixels` (20 000 000), `stored_image_pixels` (16 000 000), `stored_webp_pixels`
  (8 000 000), `step_down_pixels` (12 000 000, 8 000 000) and `image_decode_wait_seconds` (5). Image decoding takes
  the cache lock `kyc:image-decode`, so it needs the shared lock store the install already checks. The document
  upload route has a new rate limiter `kyc-upload` (20 a minute per IP address) on top of 10 a minute per user.
- **Credit ledger idempotency key (#263).** New nullable column `credit_ledger.idempotency_key` with a partial
  unique index (`credit_ledger_idempotency_key_unique`, built concurrently on PostgreSQL), migration
  `2026_10_22_300630`, expand only. The migration copies the key of existing imported opening balances (gateway
  `import`) as `import:<external_ref>`, in batches of 1,000; their `gateway` and `external_ref` are left as they are.
  Imported opening balances now carry the idempotency key; in this release they also keep `gateway` `import` and
  their `external_ref`, so a previous-release worker running beside the new one during a deploy still finds them (a
  later release stops writing that pair, #284). A gateway driver can no longer register the key `import`, or a key
  outside `[a-z0-9_]{1,50}`: it fails at boot with a LogicException. No permission or user-facing change.
- **Dependency security bump (#11).** `laravel/framework` 13.34.0 → 13.36.0 and `laravel/wayfinder` 0.1.21 → 0.1.22.
  13.35 hardens SQL in cursor pagination and `whereRowValues` (laravel/framework#61837); 13.36 makes Mail Markdown
  escape inline markdown in user input (#61755). Run `composer install` and `php artisan view:clear`; no config or
  schema change. Pest, PHPUnit and the other transitive packages stay at their cooldown-compliant versions.
- **Importer invitation rate.** The default of `migration.invite_per_hour` drops from 500 to 100, because cPanel/Exim and Google Workspace sending caps make 500 risky for deliverability. Installs that never changed the setting now send 100 an hour; raise it in Admin → Settings (1–10,000) if your mail provider allows more. No schema or permission change.
- **Integration log masking (#190).** No schema or permission change. New log rows mask more secret shapes (see
  Security below); rows written before the upgrade are not rewritten. Payment payload text values (`error`,
  `message`) now get the same text rules as module logs, so a `name=value` secret inside them is masked.
- **Application key rotation (#25).** New command `php artisan security:reencrypt` re-encrypts every stored secret
  with the current `APP_KEY`: the 22 encrypted-cast columns of the module models, secret settings, staff and client
  two-factor secrets and recovery codes, the new install secrets, and the identity images on the objects disk (27
  targets; `--dry-run` lists them with counts). It works in chunks with a checkpoint per target, so an interrupted run
  resumes (a half-switched identity image is finished on the next run), and a value the app writes during the run is
  never overwritten. A real run refuses to start while `MODULES` leaves modules out, unless `--partial` is given. It
  writes one audit-log entry with counts only (event `security.reencrypted`). Exit codes: 0 done, 1 values no key
  decrypts (left unchanged and listed by id), 2 invalid options or modules missing, 3 more to do, 4 another run holds
  the lock. The runbook is `docs/devops/key-rotation.md` (ADR 0022): new key, old key into `APP_PREVIOUS_KEYS`,
  restart every PHP process (Docker: `deploy/bin/deploy`, then `retire`, because the previous colour cannot read the
  new key), re-encrypt, verify, remove the old key. **Operators:** run `php artisan migrate` (new tables
  `reencryption_checkpoints` and `install_secrets`, migrations `2026_10_22_000100` and `000110`, expand only).
  `APP_KEY` and `APP_PREVIOUS_KEYS` can now come from a file named by `APP_KEY_FILE` / `APP_PREVIOUS_KEYS_FILE`
  (absolute, or relative to the app directory; previous keys separated by commas or new lines; Docker secrets:
  `deploy/compose.secrets.example.yaml` with the new `DEPLOY_COMPOSE_OVERRIDE` setting of `deploy/bin/deploy`;
  systemd credentials); a non-empty variable still wins. `app:install` (run by every deploy) now fails when a
  variable and its file hold different keys, when the key file is under `public/`, and when a previous key does not
  fit the cipher; in production it warns when the key is a published one or not random, when a key file sits inside
  the application directory, and when `.env` or `bootstrap/cache/config.php` is readable by other users. Import
  email hashes (duplicate checks, the email-keyed id map) now use an install secret instead of a key derived from
  `APP_KEY`, so a rotation no longer makes a re-import duplicate clients. On an upgrade, migration
  `2026_10_22_980300` (Migration, data only) pins the secret from the configured key that reproduces a stored hash (checked against staged emails and the
  emails of imported clients), so stored hashes keep matching; `security:reencrypt` pins it too if it is still missing.
  When several keys are configured and no stored hash can be checked, both stop with a message (migrate fails,
  `security:reencrypt` exits 2) instead of guessing: run `migrate` under the old key, then rotate. A run with `--only`
  reports the other targets as not checked, exits 3 and is not logged as complete. The KYC retention task re-reads a document's files under lock before deleting
  them. Set `PASSKEYS_USER_HANDLE_SECRET` before a first rotation (see the runbook). Backups: the backup sections of
  `install.md`, `production.md` and `cpanel.md` now say to exclude `.env` and `bootstrap/cache/config.php` from file
  and host backups and to keep the key (and old keys, for older dumps) in a secrets store; before, `production.md`
  listed `deploy/.env` among the things to back up. No permission change.
- **Step-up for sensitive actions works with any set of modules (#81).** The `password.recent` middleware moved from
  the Platform module to the application kernel (`App\Http\Middleware\RequireRecentPasswordConfirmation`, aliased in
  `bootstrap/app.php`). Installations that leave out Platform (`MODULES=Domains`, `MODULES=Services` and so on) no
  longer get a 500 error on sensitive Domains, Services, Kyc, Notifications and Storefront actions: they get the usual
  confirm-password prompt (423 for JSON clients). Billing's and Migration's own fallbacks are removed; Migration's
  used to redirect to the confirm page, and now behaves like every other module. The window is still 900 seconds;
  its setting moved from `platform.password_confirmation_seconds` to `auth.step_up_seconds` in `config/auth.php`.
  Anyone who edited the old key in `app/Modules/Platform/Config/platform.php` sets the new one instead. No schema or
  permission change.
- **Cache lock store check.** `php artisan app:install` reports a new "Cache lock store" check. `CACHE_STORE=array`
  or `null` (or a `failover` list containing one) stops a first production install and warns on an existing installation; `file`, `apc` and `octane` warn that they are safe on a
  single host only; `database` and `redis` pass. In production the admin dashboard's System health card shows the same
  finding. Operators with more than one server or container: use `database` or `redis` (`docs/devops/install.md`).
- **A currency's code can no longer be changed after it is created (#238).** Clients, invoices, quotes,
  transactions, prices and rate history store the code, so renaming it left them pointing at a currency that no
  longer existed. Admin → Currencies → Edit shows the code read-only with the reason; an update request that sends a
  different code is refused with "The code of an existing currency cannot be changed. To use another code, add a new
  currency." Staff who need another code add a new currency. No schema or permission change. Installations where a
  code was already renamed keep their records under the old code; nothing repairs them automatically.
- **Security: upgrade PHP to 8.4.27 or newer (CVE-2026-109618, CVE-2026-109619).** PHP 8.4.26 and older (8.5.11
  and older) allow unauthenticated remote code execution through every web SAPI, FrankenPHP included, with no
  workaround besides upgrading. **Operators: upgrade PHP to 8.4.27+ (8.5.12+ on 8.5) and rebuild or pull the image.**
  Docker: the image now builds from FrankenPHP 1.13.2 (PHP 8.4.27, which also fixes four FrankenPHP advisories, one
  high: GHSA-6q3j-62j9-f9hh); deploy as usual with `deploy/bin/deploy`, which replaces the web container and every
  worker. The prod build stops if its PHP is older than 8.4.27. Self-hosted and cPanel: upgrade the PHP packages (on
  cPanel, ask the host for `ea-php84` 8.4.27+), then restart php-fpm and run `php artisan queue:restart`; see
  `docs/devops/install.md` and `docs/devops/cpanel.md` ("PHP security floor"). `php artisan app:install` reports a new
  "PHP" check (a warning on an existing installation; it stops a first install in production), and in production the
  admin dashboard's System health card shows "PHP runtime" in red while PHP is vulnerable. `composer.json` still
  requires `^8.4`. FrankenPHP 1.13.2 is younger than the 7-day cooldown and is adopted through the security-exception path.
- **Client import invitations.** A new table `migration_invitations` (migration `980300`, expand only) and a setting
  `migration.invite_per_hour` (500). The permission `migration.runs.execute` now also covers sending invitations to
  imported logins. A new system email template `client.migration_invitation` is installed by `php artisan app:install`
  (run on every upgrade); it is always sent and cannot be switched off. Kernel: `App\Contracts\Auth\ClientLoginInvitations`
  (Core binds a null default, Platform its invitation flow), `App\Enums\InvitationOutcome` and
  `App\Enums\InvitationPurpose`. `App\Enums\SystemMailOutcome` gains `Suppressed` (mail logged but not sent on
  purpose), which was reported as `Taken` before.
- **Invitations while "Stop sending email" is on.** Adding a new login to a client now answers "the invitation was not
  sent: email is switched off…" instead of reporting it sent, and leaves no open invitation; send it again once mail
  is on.
- **Client import opening balances.** Account-credit entries gain the reason `import`; an import's entries carry the
  reference `import` / `run:<run>:client:<client>` in the existing unique (`gateway`, `external_ref`) pair. Migration
  `980310` adds `migration_records.entities` (expand only); applied rows now keep their undo snapshot per entity
  (`{client: …, credit_balance: …}`). GSTINs with the codes 97 (Other Territory) and 99 (Centre Jurisdiction) are now
  valid. No permission change. Kernel: `App\Contracts\Migration\RevertsImport`, `ImportAction::Reverted`,
  `App\Contracts\Billing\TaxIdRules` with `App\Data\Billing\TaxIdNote` (Core binds a null default, Billing its rules).
- **A client's currency is fixed once the client has records in it (#218).** Staff can no longer change the currency
  on Admin → Clients → Edit when the client has invoices, quotes, payments, account credit, services, orders or
  domains (deleted services and domains count). The form shows the currency disabled with the reason, for example
  "This client has billing records and services in NPR, so the currency can't be changed.", and a save that tries
  anyway gets the same message on the currency field. Before, the change was saved and amounts stored in the old
  currency were read in the new one. A client with no records can still change currency, and saving other details
  with the same currency works as before. The check runs under the client's row lock; invoices, draft invoices and
  quotes now read the client under a share lock, and a checkout whose client currency changed after the cart was
  priced is refused ("The account currency changed while this order was placed."), so a change and a client's first
  invoice or order can't cross. Account credit (staff adjustments, manual payments without invoices) is refused when
  its amount is not in the client's currency ("Account credit must be in the client currency (NPR)."). Saving a quote
  now locks the quote first and checks its stage again, so an edit can no longer overwrite a quote accepted or
  converted at the same moment. Clients changed before the upgrade are not corrected: look for clients whose invoices
  are in another currency than the client's. For such a client, a refund, cancellation or reversal that would move
  account credit in the invoice's old currency is now refused with that message. No migration and no permission
  change. Kernel: `ClientCurrencyUsage::sourcesFor()`.
- **Identity verification (KYC) module, off by default (#197).** A new `kyc_documents` table (migration
  `770100`, expand only). Nothing changes until a host turns on Admin → Settings → Identity verification
  (`kyc.enabled`, new permission `kyc.settings.manage`, granted by `*` and `kyc.*`). Two new **sensitive**
  permissions, which `kyc.*` does not grant: `kyc.documents.view` (queue and documents) and `kyc.documents.review`
  (approve or decline). `php artisan app:install` resets the system roles, so the Support role gains
  `kyc.documents.view` (with an audit entry); deciding stays with full administrators unless a role is given the review
  permission. Opening documents and every decision ask for a recent password. The daily housekeeping stage gains
  the task `kyc.prune_documents`. Identity images are stored encrypted with `APP_KEY` on the `objects` disk: keep the
  old key in `APP_PREVIOUS_KEYS` when rotating, or stored images can no longer be read. `php artisan app:install`
  (or the upgrade's reference-data step) adds the new client email template `kyc.document_decided`; existing
  templates are untouched. Kernel: new event `App\Events\Kyc\DocumentDecided(clientId, documentId)`.
- **Payment follow-ups (#232, #230, #221).**
    - **A won Stripe dispute that was never booked as a chargeback is acknowledged (#232).** On a Stripe webhook
      destination without `charge.dispute.funds_withdrawn`, an inquiry that escalated and was then won reached its
      `charge.dispute.closed` with no chargeback booked, and Billing answered 409 until Stripe stopped retrying. When the
      dispute's balance transactions show both the withdrawal and the reinstatement, Billing now answers 200 and moves
      no money, with the gateway log result "dispute won with no chargeback booked; funds reinstated at the provider,
      no money moved". It records the win as a zero-amount `chargeback_reversal` transaction ("Dispute won at the
      provider before a chargeback was booked here. No money moved."), written under the payment's lock: a chargeback
      for that dispute delivered later (a `funds_withdrawn` read before the win) is then answered "dispute already won;
      nothing booked", and one that commits while the win waits for the lock is reversed as usual. The zero row is not
      shown to the client (invoice page, PDF, account credit page), is never refundable and adds no ledger line or
      income; staff see it on Billing → Transactions. A `charge.dispute.created` or `funds_withdrawn` retrieved after
      the win is also ignored by the Stripe driver. The inquiry's to-do stays on "Disputes to review" for staff
      to mark reviewed. Failed deliveries from before the upgrade are not replayed: resend them from the Stripe
      Dashboard if you want them in the log.
    - **The open-payment poller looks up every five minutes (#230).** As with the late sweep (#222), each lookup is
      stamped when it finishes, so the next five-minute run skipped the attempt and pending hosted payments (eSewa,
      Khalti) were looked up every ten minutes. A time-travel test showed the 10:05 run skipping it. The cut-off is
      now taken once, when the run starts, with up to 60 seconds of slack (half the interval when
      `billing.gateways.lookup_interval_minutes` is 1); the back-off after 12 lookups (every sixth interval) uses the
      same cut-off. Each run is now bounded: at most `billing.gateways.lookups_per_run` lookups (new setting, default
      200, 1 to 1,000), never-checked and least recently checked attempts first, and none
      started after 4 minutes; the rest wait for the next run.
    - **New outgoing webhooks `payment.reinstated` and `payment.reversed` (#221).** A won dispute fires the new event
      `PaymentReinstated` once for each `PaymentReversed` its chargeback fired, with the same invoice and amount: one
      per invoice for a combined charge, none for a dispute that took back only account credit (which fired no
      `PaymentReversed` either). It fires also when the invoice still owes more or the money went to staff review
      (`invoice.paid` still follows only when the win settles the invoice). Webhook endpoints can subscribe to
      `payment.reinstated` (the `chargeback_reversal` transaction) and to `payment.reversed` (the chargeback, or a
      refund with "reverse effects"), which was not offered before. An endpoint subscribed to all events (`*`) receives
      both from the upgrade on; others only once staff add them on the endpoint's form (Admin → Webhooks).
    - No migration and no permission change; one new setting (`billing.gateways.lookups_per_run`). Kernel: new event
      `App\Events\Billing\PaymentReinstated`; new `CallbackEvent::$fundsReinstated`.

- **Go-live payment fixes (#223, #212, #222, #224).**
    - **A refunded deposit takes its credit back (#223).** A provider refund (and a staff refund in any mode) of an
      add-funds payment now takes back the account credit the deposit placed: the refunded amount, after any
      overpayment credit, and never more than the deposit still holds. If the client already spent it, the balance
      goes negative and shows on "Clients owing account credit". Before, the client kept the credit. Deposits refunded
      before the upgrade are not corrected: check the add-funds invoices with status Refunded.
    - **Stripe inquiries are no longer booked as chargebacks (#212).** Stripe sends `charge.dispute.created` for
      inquiries too (status `warning_*`), where no money leaves the account. A dispute is now booked as a chargeback only
      when one of its balance transactions withdrew funds. Until then it is a zero-amount `dispute_inquiry` transaction
      on "Disputes to review" ("Inquiry: answer it at the provider"): no change to the invoice, payment or credit, and
      the client doesn't see it. An inquiry that escalates is booked when Stripe sends
      `charge.dispute.funds_withdrawn`, which also closes the inquiry's to-do; an inquiry that closes changes nothing.
      Without that event, the chargeback is booked when the dispute closes as lost (`charge.dispute.closed`), once per
      dispute. Adding `charge.dispute.funds_withdrawn` to the Stripe webhook destination is recommended, so the
      chargeback is booked when the money leaves, not weeks later. New webhook result: "dispute inquiry
      recorded; no money moved". Inquiries booked as chargebacks before the upgrade are not corrected: check Stripe
      chargebacks whose dispute status at Stripe starts with `warning_`.
    - **The late-payment sweep runs every hour (#222).** Each lookup is stamped when it finishes, a few seconds after the
      hourly run starts, so the next run skipped the attempt: closed attempts were looked up every two hours. The sweep
      now takes attempts last looked up at least 50 minutes before the run started.
    - **Two overpayments of one client no longer deadlock on PostgreSQL (#224).** A payment that may post account credit
      (an overpayment, a deposit paid in full, a payment of a closed or held invoice) locks the client after the
      invoice and before its row is inserted. A combined payment and a manual payment of several invoices lock all of
      them first, in id order. Two-process tests reproduced both deadlocks (40P01) before the fix.
    - No migration and no permission change. Kernel: `CallbackEvent::DISPUTE_INQUIRY`.

- **Won disputes are booked (#191), and provider reversals take back credit first (#194).** A dispute the merchant
  wins (Stripe `charge.dispute.closed` with status `won`) now books a `chargeback_reversal` transaction once per
  dispute, under either `billing.reversal.set_collections` setting: the invoice is paid again up to what it still
  owes, the payment can be refunded again by that much, and
  the account credit the dispute took back is restored, which clears the negative balance and the "Clients owing
  account credit" to-do it caused. Money the invoice can no longer take (it was paid again or cancelled since the
  dispute) goes to "Disputes to review", never to credit. Add `charge.dispute.closed` to the Stripe webhook
  destination; until then won disputes are still not booked. A win for a dispute you already corrected by hand
  restores the credit again: check those clients after the upgrade. Partial provider refunds and disputes now take
  back the credit the payment placed before they reduce the invoice (before: invoice first), so a partial refund of
  an overpaid payment leaves the invoice paid. A disputed payment flagged "Late payments to check" leaves that to-do,
  keeping its review note with "disputed at the provider" added, and an activity entry
  (`billing.payment.late_closed_by_dispute`). Credit a win restored is taken back again by a later provider refund or
  dispute of the same charge. New webhook results: "dispute won; payment reinstated", "dispute
  won; needs staff review" and "dispute not booked yet; retry expected". No migration.
- **eSewa attempts live 60 minutes and end on a cancel.** A captured run of eSewa's UAT sandbox (2026-10-21) showed
  that eSewa keeps a booked session payable about 50 minutes after the form post, and that a cancel on eSewa's page
  answers `NOT_FOUND` in the status check, not `CANCELED`. Before, the attempt expired after 15 minutes, so a
  customer could pay another way while the eSewa session could still be paid, and a customer who cancelled stayed
  locked out until those 15 minutes ran out. Now `payments_nepal.esewa.attempt_ttl_minutes` defaults to 60, and
  values below 55 are raised to 55. A pending eSewa attempt whose status check says `NOT_FOUND` ends when the
  customer comes back through the cancel URL (attempt failed; on any gateway, a cancel return that ends failed now
  shows "Payment cancelled. You can try again or choose another way to pay.") or once it is 5 minutes old (attempt expired). `NOT_FOUND` never marks an invoice paid. An
  attempt in staff review is not ended this way, and none is ended early unless `gateways.late_lookup_hours`
  covers the attempt's lifetime plus two late-sweep hours (3 hours with the 60-minute eSewa attempt; default 48). A cancel return for a pending attempt may look up the status again 10 seconds after the
  last lookup, instead of one minute. Ended attempts stay in the late-payment sweep (#130), so an old eSewa form
  that is still paid is booked and flagged for staff. Attempts already open at the upgrade keep their 15-minute
  expiry. No migration and no permission change. Kernel: `LookupResult` gains a trailing `sessionEnded` field (default false), and
  `SettlePaymentAttempt::handle()` takes an optional `cancelReturn` flag.
- **Changing a client login's email removes its verification (#184).** Every path that changes the email of a client
  login (not staff) now leaves `email_verified_at` empty unless the same save sets it (the profile page already did
  this). The client import relies on it: it links an existing login only when its email was verified 30 days ago or
  more. Staff logins are unchanged: when an admin changes a colleague's email, verification is cleared and the
  verification mail is sent; staff who change their own email on the staff page stay verified.
- **Nepal-first install defaults (#170) and a queue timeout check (#195).** Nothing changes on an existing
  installation: `app:install` writes the regional defaults only while no currency exists, so a database upgraded
  from an earlier release keeps the US dollar default, US as default country, UTC as company time zone and the
  built-in date and tax settings it already used, whether or not they were ever stored. A **new** install now
  starts with country Nepal, NPR as the default currency (USD and INR are added with rates relative to it), time
  zone Asia/Kathmandu, dates like `1 Jan 2026`, a VAT rule of 13% for Nepal, the seller tax country set to Nepal
  and invoice numbers `INV-00001`. Tax stays off until you enable it in Settings → Billing → Tax. The install
  now also **fails a first install** (and only warns on an installation that already has currencies, such as the re-run after an upgrade) when a queue connection's `retry_after` is not above the longest job timeout (200 s on the
  default connection, 3,600 s on `automation`): raise `DB_QUEUE_RETRY_AFTER` (or the Redis or Beanstalkd
  equivalent) above the figure the message gives. The shipped values (240 s) pass. The company time zone is
  now a setting you can edit in Settings → Company (`system.timezone`; ADR 0013's rename to `core.timezone` is
  still pending).
- **`.np` domains are registered by the client at the registry** (owner decision, 2026-10-20). Run `php artisan
  migrate`: it adds `tlds.registered_by_client` and turns it on for `.np` and its second levels. Those TLDs can no
  longer be registered or transferred through the cart; clients use them with hosting through "Use a domain I own".
  New setting: `domains.self_registration_url` ("How to register" link, default the registry's guide).
- **Chargebacks and provider refunds take back account credit (#172, #183).** A dispute or a provider-side refund
  of a payment that went to account credit (an overpayment, a payment of an invoice that was already paid or
  cancelled, or a staff refund of the payment to credit) left that credit spendable: the chargeback was booked at 0
  and the refund only logged "refund needs staff review". Now the part the payment could not return is taken back
  from the credit that payment placed, once per dispute or refund id, never from other credit. If the client
  already spent it, the balance goes negative; this now also applies to refunds of flagged late payments (#152),
  which keep their "Gateway payments to review" flag. Chargeback and refund rows book the whole reversed amount as
  money out (a refund that came only from credit gets its own row), so net income is right. A dispute with nothing
  left on the invoice no longer moves a paid invoice to Collections or emits `PaymentReversed`. Two new dashboard
  to-dos: "Clients owing account credit" (`billing.credit.manage`; Billing → Account credit, new "Show: Negative
  balances only" filter) and "Disputes to review" (`billing.payments.manage` and `billing.transactions.view`; Billing → Transactions,
  "Mark reviewed", recent password) for a rest nothing could cover. Webhook results now include "chargeback
  recorded; credit taken back", "chargeback needs staff review" and "refund recorded; credit taken back". Run
  `php artisan migrate`: it adds `transactions.review_amount` and `reviewed_at` (nullable) and two partial indexes,
  built concurrently on PostgreSQL. Disputes and refunds booked before the upgrade are not revisited: check clients whose payments
  were disputed or refunded at the provider.
- **Registrar drivers implement `findSubmission()`** (registrars.md C2). The `Registrar` interface gains
  `findSubmission(Domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup` (status: the kernel
  `App\Enums\ReconcileStatus`, shared with provisioning) and the `reconcile`
  capability; a driver without it throws `CapabilityNotSupported`. Result DTOs gain a trailing `remote` array.
  Driver settings are read as `SettingField`s: `integer` fields are validated and stored as numbers.
- **Registrar work moved to the `registrar` queue** (ADR 0014, ADR 0017). Register, transfer and renew jobs and the
  hourly registrar sync (now the `SyncRegistrars` job, scheduled as `domains:sync`) left the `default` queue, and the
  scheduler process no longer calls registrars itself. The provider pool (`queue:work
  --queue=payments,provisioning,registrar --timeout=200`) must be running, or registrations, renewals and sync wait
  there. Jobs already queued on `default` still run there. A sync run stops taking new domains after 70 s; the rest
  go first in the next run. `php artisan domains:sync` still runs a sync in the shell. `composer dev` now runs one
  queue worker for every queue (Core, built from the `Queues` constants) in place of the Services and Notifications
  ones, so local runs process registrar jobs too.
- **The hourly `domains:sync` now recovers interrupted submissions** (#12), also when registrar sync is switched
  off: a `pending` domain whose submission claim is older than 15 minutes is flagged for review with a staff task,
  checked with the registrar (never re-sent) and listed in the sync report. No schema change.
- **Website permissions.** Admin → Website needs `storefront.content.manage` (edit and preview the draft) and
  `storefront.publish` (make it live). `storefront.publish` is sensitive: only an exact grant (or `*`) gives it, never
  `storefront.*`, and publishing asks for the password when it was not confirmed recently. Grant both explicitly to
  staff who maintain the public site.
- **Public website (Storefront module).** A migration adds `storefront_revisions`. Until a version is published, the
  home page shows the placeholder headline "Hosting and domains from {company name}". The `/` route moved from
  `routes/web.php` to the Storefront module, and unknown public URLs now return the public 404 page (still HTTP 404;
  other-method URLs still 405). Footer company details read Settings → Company and the invoice seller details (legal
  name, address, phone, PAN, VAT number, GSTIN) until the company profile exists. Settings → Company gains support
  hours, WhatsApp number, company registration number and Facebook, Instagram, LinkedIn and X links for the footer;
  empty fields are not shown. A new support email (Settings → Company) is the website's contact address and "Contact
  us" link; without it the footer shows the billing address and "Contact us" opens a support ticket. The demo data
  now runs a clearly fictional host, "Example Hosting" (seller details, support hours, WhatsApp, PAN/VAT,
  registration number, `example.com` email, social and Terms, Privacy and Refund links), and its card gateway is
  named "Card"; its bank transfer instructions name a fictional bank and an example NPR account. The demo seller is a Nepal company: the demo sets the tax home to Nepal (Bagmati) and the default
  country to NP, so NPR invoices carry 13% VAT and INR invoices no longer charge Indian GST. Only the demo seeders
  change; the shipped defaults (tax home IN/MH, default country US) are as before. The Core and Billing demo
  seeders now fill only empty settings, so re-running `db:seed` keeps company values a developer changed; run
  `migrate:fresh --seed` for the new demo values.

- **Late gateway payments are booked (#130).** A success that Stripe, eSewa or Khalti confirms for a payment
  attempt that had already closed (expired, failed or cancelled) was logged but never booked. It is now booked
  after the same server-side lookup as any payment: on the invoice up to its balance, the rest as account credit
  (all of it when the invoice was already paid). A Stripe webhook for a closed attempt now answers
  `late payment recorded` instead of `attempt expired`. Run `php artisan migrate` (adds four nullable columns and
  two indexes to `payment_attempts`, and `gateway` and `external_ref` to `credit_ledger` with a unique index on the
  pair). The scheduler now runs `billing:reconcile-payments --only=open` every five
  minutes and `billing:reconcile-payments --only=late` hourly, each on its own lock; without `--only` the command
  runs both. New settings: "Hosted payments: keep checking closed payments for late success" (48 hours, 0 to 168)
  and "Hosted payments: late checks per hourly run" (100). Attempts that closed before the upgrade are not swept;
  a webhook or "Check now" can still book them. **After upgrading, check Billing → Payment attempts with the
  "Late payments" filter:** a payment staff booked by hand before the upgrade (for example from a bank statement)
  can be booked a second time by a provider webhook for its old attempt. Compare each listed payment with your
  records and refund any duplicate.

- **The default queue worker also runs client imports** (client-import spec C2): start it as
  `php artisan queue:work --queue=default,imports --tries=3 --timeout=150 --memory=256` (it was `--queue=default`), and give
  it a stop timeout above 150 s (Compose `stop_grace_period: 160s`). Import jobs use the new `imports` queue, worked
  after `default`, stop themselves after 120 s and may use up to 192 MB. Without the change, client imports wait in the
  queue. On SQS, give the `imports` queue a visibility timeout of at least 150 s.
- **Client import files can be 20 MB.** The container's PHP `upload_max_filesize` is now 21M (was 12M). On other
  installs, raise `upload_max_filesize` (and `post_max_size`) to at least 21M; the Import clients page says when PHP
  accepts less than the setting. New permissions `migration.runs.view` and `migration.runs.execute` (sensitive: never
  granted by a wildcard, needs a recent password) control the page.

- **Clients record how they were created and who set their email (`clients.origin`, `clients.email_source`).** A Core
  migration adds `origin` (`signup`, `checkout`, `staff`, `import`, `api`; default `signup`) and `email_source`
  (`staff`, `import`, `client`; default `client`), with CHECK constraints on PostgreSQL. It runs outside a transaction
  and backfills in batches of 1,000 clients from the activity log:
    - `origin` becomes `staff` where a staff member created the client (`client.created`), and `signup` otherwise;
    - `email_source` becomes `staff` where the last entry that set the email was a staff member's (`client.created`, or
      `client.updated` with `email` changed); a client-area account-details save counts as the client's.

    Clients seeded or created before the activity log recorded event keys (2026-10-17) count as `signup` with a
    `client` email. Account import does not propose their email matches until a login on the client verifies the
    address; staff can still choose such a client for an account. To vouch for addresses your staff entered or checked
    with the client, with an audit entry per client:
    `php artisan tinker --execute 'foreach ([12, 15] as $id) { $c = App\Modules\Core\Models\Client::query()->findOrFail($id); $c->forceFill(["email_source" => "staff"])->save(); app(App\Modules\Core\Support\ActivityLogger::class)->log("Client email vouched for by staff", $c, $c->id, ["email" => $c->email], "client.email_vouched"); }'`.
    **Do not vouch for an address a client chose** (a sign-up, or one changed in the client area): that lets an email
    match to it be proposed, and re-opens the gap this closes.

- **PostgreSQL: the `pg_trgm` extension and 12 search indexes.** Four migrations run `create extension if not exists
  pg_trgm` and build GIN trigram indexes CONCURRENTLY (no write lock) on the searched columns of `invoices`
  (`number`, `proforma_number`), `services` (`domain`, `username`, `product_name`, `dedicated_ip`), `support_tickets`
  (`number`, `subject`) and `clients` (`lower()` of `first_name`, `last_name`, `company_name`, `email`). PostgreSQL 13
  and later treat `pg_trgm` as trusted, so the database owner can create it; a role without `CREATE` on the database
  needs a superuser to run `create extension pg_trgm` first. Some managed services also need `pg_trgm` allow-listed
  before it can be created (Azure Database for PostgreSQL: the `azure.extensions` server parameter). If creating the
  extension fails, `migrate` stops at the first trigram migration with the database error; allow the extension and run
  `migrate` again, which is safe to repeat. On 20,000 clients the builds took 2.4 s and the indexes use about 10 MB.
  SQLite is unchanged.
- **Start a fourth queue worker for Admin → Automation → Run now** (#82):
  `php artisan queue:work automation --queue=automation --tries=1 --timeout=3600`, with a stop timeout above 3,600 s
  (systemd `TimeoutStopSec`, Supervisor `stopwaitsecs`, Compose `stop_grace_period`). Run now jobs moved from the
  `default` queue to their own `automation` connection: the backend of `QUEUE_CONNECTION` (`database`, `redis`,
  `beanstalkd` or `sqs`; `database` for any other driver), the `automation` queue, and a `retry_after` of 3,900 s. A run
  longer than 240 s was handed to a second worker, which failed it while the first worker was still running it.
  Without the new worker, Run now requests wait in the queue; the scheduled daily run is not affected. On SQS, create
  an `automation` queue with a visibility timeout of at least 3,900 s. Jobs already waiting on `default` from an
  earlier Run now still run there. Start the worker with the `automation` connection argument: a job taken by a
  worker on another connection (`queue:work --queue=automation`) fails without running and names the right command.
  The `app:install` hint prints the four workers and the 200 s timeout that `install.md` documents for the first pool
  (it printed 85 s).
- **Rotate the inbound email webhook secret (security review 2026-10-19).** `support.inbound_secret` was readable
  by every role with support settings access and stored in plaintext. It is now a write-only secret: a migration
  encrypts the stored value, it moves to its own section (Admin → Settings → Inbound email webhook) behind the new
  sensitive permission `support.inbound_webhook.manage`, and a change needs a recent password. `support.*` no longer
  reaches it; grant the permission explicitly to staff who manage the mail relay. After upgrading, enter a new secret
  of at least 32 characters and set it on the relay: mail signed with the old one stops being accepted.
- **Changing where a server connects clears its stored credentials.** A new hostname, IP address, port, TLS setting
  or driver needs the API token or password entered again in the same save; the stored ones and any trusted
  certificate are removed, and the move is recorded in the activity log as "Server connection address changed".
  Server audit entries now read "Server settings changed", with the server name in the properties.
- **Registration and password reset are rate-limited.** `POST /register`: 10 an hour per IP and 5 an hour per email
  address. `POST /reset-password`: 5 a minute per IP (no per-address limit, so failures from elsewhere cannot block
  a valid link). Over the limit the answer is 429.
- **More step-up confirmations.** Creating, editing and deleting server groups, capturing an authorised invoice
  charge, and saving or testing a registrar now need a recent password. `domains.registrars.manage` is sensitive:
  wildcards such as `domains.*` no longer grant it, so grant it explicitly. Registrar tests allow 6 a minute.
  Sensitive settings sections ask for the password before they validate the form, like every other step-up route.
- **Clients can change a hosting password 6 times a minute.**
- **Webhook deliveries ignore `HTTPS_PROXY`.** They connect directly to the vetted address, and also refuse the
  addresses the kernel outbound guard refuses (documentation, tunnelled and non-canonical forms). In local
  development a loopback receiver needs `OUTBOUND_ALLOW_LOOPBACK=true` (the default when `APP_ENV=local`).
- **New installs encrypt sessions** (`SESSION_ENCRYPT=true` in `.env.example`). Existing installs keep their
  setting; turning it on signs everyone out once.
- **Account import, step 1.** A migration adds the `server_account_imports` table: one fetched account list per
  server with how each account matched, encrypted, deleted once expired (24 hours) when next read or by the daily
  housekeeping task "Discard expired account imports". The new permission `services.servers.import` is sensitive:
  wildcards such as `services.*` never grant it, and fetching needs a recent password. Grant it explicitly to staff
  who bring existing accounts in. Fetches run on the `provisioning` queue.
  A further migration adds `server_account_imports.connection_user` (nullable): lists fetched before it are matched
  without the owner check until fetched again.
  A second migration adds `server_account_imports.selections`, `reviewed`, the last import's counts and stop reason, and the append-only `server_account_import_report_lines` table. Ticking and
  importing need a recent password; imports run on the `provisioning` queue.
  A Core migration adds `email_changed_at` to `clients` (nullable), set whenever the address changes.
  An email match is no longer proposed when the client changed email in the last 30 days, or when the client has an
  owner login and none with the client's email is verified (clients from sign-up or checkout until they verify).
  Clients without an owner login, as staff create them, are proposed as before. Staff can still choose a flagged
  client for an account; the review imports it only then. Match again to apply this to a list fetched earlier.
- **Loopback is reached only in development and tests.** Outbound connections to `127.0.0.0/8` and `::1` are refused
  unless `APP_ENV` is `local` or `testing`, even with `OUTBOUND_ALLOW_LOOPBACK=true`. A server or endpoint that must be
  reached from the billing host should use the panel's own public or private address, not 127.0.0.1.
- **Module queue check results.** A migration adds three nullable columns to `module_queue` (`lookup_status`,
  `lookup_message`, `looked_up_at`); no rows are rewritten. The scheduler must run for `services:verify-unknown`.

- **Server ports.** A cPanel server cannot be saved with a plain-HTTP port (2082, 2086, 2095) or any port below 1024
  other than 443; WHM is reached on 2087, or 443 behind a proxy. A trusted certificate pin must be a base64 SHA-256.
- **Usernames are unique per server among live services.** A migration adds a partial unique index on
  `services (server_id, username)` for pending, active and suspended services that are not deleted (built without a
  write lock on PostgreSQL). Terminated, cancelled and deleted services may share a name with a live one, since the
  panel frees it on removal. The migration fails only if two live services on one server share a username; rename one
  first. Generated and renamed usernames now treat only live services as taken.
- **Exchange rates are a separate, sensitive permission.** `core.currencies.rates` now guards creating and deleting
  currencies, rate sources and manual rates, rounding and buffer rules, "Update rates now", approving or rejecting
  held rate changes, changing the default currency, converted prices and their settings. Wildcards such as
  `core.*` never grant it, and each change needs a recent password. Roles that held only `core.currencies.manage`
  keep currency display settings; grant `core.currencies.rates` explicitly to staff who maintain rates.
- Exchange-rate columns widen to `decimal(24,12)` (currencies, rate history, reviews, transactions, payment
  batches and attempts). Existing values are kept; rolling the migrations back rounds rates to six decimals.
- Existing prices in non-default currencies are marked "set manually" (TLD prices always; product prices when
  automatic conversion was off), so the first price conversion leaves them unchanged.
- **Provisioning drivers read custom fields and configurable options by module key only** (#65). Set a module key on
  any custom field or option a driver reads; fields without one are no longer sent. A custom field's module key must
  now be unique per product: the migration fails if a product already has two fields with the same key, so rename
  duplicates first.
- **Notifications permissions.** New `notifications.*` permissions: `templates.view`, `templates.manage`,
  `log.view`, `log.resend`, `send`, `subscriptions.manage`, `webhooks.manage`, `delivery.manage` and
  `settings.manage`. `templates.manage`, `webhooks.manage` and `delivery.manage` are sensitive: wildcards never grant
  them and each change needs a recent password. Senders, Reply-To, the global BCC, template CC/BCC and
  `notifications.auth_link_host` need `delivery.manage`.
- **Mail now goes through the Notifications module.** Run queue workers for the `notifications` and `webhooks`
  queues (`docs/devops/local-setup.md`). Password-reset and verification mail use the editable templates; Laravel's
  own notification is the fallback only when no template message could be created.
- **Account mail fails closed.** A reset, verification or invitation link that is not on `APP_URL` (or
  `notifications.auth_link_host`) is never mailed: nothing is sent and a security event is logged. With an `http://`
  `APP_URL` in production every such mail is held; the install pre-flight and the dashboard health card say so.
- **Forgot-password** gives the same answer for known and unknown addresses and is throttled (5 a minute per IP,
  5 an hour per address).
- `php artisan app:install` and `db:seed` install the default email templates; staff edits are never overwritten.
- **Email direction A** adds `email_template_translations.preheader` (nullable) and seven new system templates;
  `db:seed` or `app:install` installs the new templates. Existing template text is never overwritten: use
  "Restore default text" on a template to pick up the new wording, buttons with amounts and preheaders.
- Staff invitations and account security notices (login added, access given, owner changed, two-step sign-in
  reset, staff sign-in email changed, security settings changed) now send editable templates and appear in the
  email log. A new client login gets the "Account invitation" email instead of the password-reset email.
- Demo seeders no longer send mail: what they would send is logged as suppressed (`demo_data`).
- **Host brand only in customer mail.** The default tagline is now empty; mail shows a tagline only when the host
  sets one. Mail shows the host's uploaded mark (PNG or JPG) beside the company name, or the name alone; the
  product's own mark and logo are no longer used. An optional "Email logo" upload (Settings → Appearance) replaces
  both; it is flattened onto white when uploaded so it reads in every mode. WebP marks are converted to PNG when
  uploaded; re-upload an existing WebP mark for it to appear in mail.
- Invitations and account security notices are always sent: their templates can't be switched off.
- **Email templates follow new defaults.** Run `php artisan migrate` (adds `default_hash` and `previous_text` to
  `email_template_translations`); the upgrade's `app:install` then replaces the text of templates nobody edited with
  the new shipped wording, with an activity-log entry each. Edited templates are left as they are.
- **Client-login invitations have their own link.** A new `client_invitations` table holds a single-use invitation
  token (stored hashed) instead of a password-reset token. Links work for "Invitation links work for (days)"
  (`platform.invitation_expiry_days`, default 7), now shared by staff and client-login invitations. Invitations sent
  before the upgrade were password-reset links and expire as before; those logins have no open invitation, so staff
  use the new "Send a password reset" action for them instead of "Send a new invitation".

### Security

- `vite-plus` 1.1.0 → 1.3.0 (and the `vite` alias/override `@voidzero-dev/vite-plus-core` 1.1.0 → 1.3.0) for
  GHSA-p458-7748-mcp3 (moderate: Vitest browser and UI servers misapplied `server.fs.deny` and could serve `.env`,
  keys and `.git`; dev and test only, nothing in the production image). 1.3.0 is the first fixed release and was
  adopted after the 7-day cooldown (#241); `npm audit` is clean again (it reported 5 moderate findings). Brings
  Vitest 5.0.5, Oxlint 1.88.0, Oxlint-tsgolint 7.0.2004, Oxfmt 0.73.0 and the Rolldown bundled in vite-plus-core; the Yuku parser moves
  0.9.5 → 0.19.0. No source or formatting change (`vp check --fix` leaves the tree unchanged). Developers run
  `npm ci` in `billing/` after pulling.
- Log redaction (#278): an unclosed void-named container with a secret name (`<param name="password"><value>…`, a
  truncated XML-RPC body, `<input>` used as an XML/SOAP element) now masks everything after it instead of leaking the
  child value, also after an empty CDATA section, a stray end tag, or a child whose name starts with `_`. Inside an
  HTML document (a `<!doctype html` or `<html` start tag earlier in the text, not inside a comment, CDATA section,
  attribute value or masked secret) these elements keep the next-tag bound, so `<meta name="csrf-token">` and
  `<input name="password"><p>…` on an HTML page stay readable. Without that marker a void-named secret element
  followed by an end tag and then a child element (`<object><param name="password"></object><p>…`) masks the rest.
  The marker is found by a forward walk with constant extra memory; a raw `<html>` inside a non-secret JSON string still
  counts as a marker, which only allows the next-tag bound and never exposes a secret value.
- **Downloads behind a recent-password check (#272).** A link to a protected file (today only the Migration "rows with errors" CSV) used to bounce back to the page and look dead. Clicking it now opens the confirm-password dialog and starts the download after the password is confirmed. A link opened in a new tab goes to the confirm-password page and returns to the file. The file is never sent without a recent confirmation. No schema or permission change.
- **Encrypted browser history is cleared on every logout (#250).** Before, only a logout with a KYC document unlock
  open cleared it, so any page that encrypts its history stayed restorable with Back or a restored tab until the tab
  closed. Now the history is cleared for staff and clients whenever a request ends or swaps the login (logout, deleting
  your own account, a deactivated login signed out, sign in as a client and back), and on the login and two-factor
  pages, where a session that ended on the server leads. A hover prefetch does not use up the clearing. No schema or
  permission change.
- **Identity images are re-encoded (#233).** Each uploaded JPEG, PNG or WebP is decoded and encoded again in the same
  format before it is encrypted. EXIF (GPS position, device, embedded thumbnails), colour profiles, text chunks, JPEG
  comments and bytes appended after the image data are not stored; a payload built into the pixel data of a lossless
  PNG is not removed. The EXIF orientation is applied to the pixels, so staff see the photo upright. Refused with a validation
  error: a file that cannot be decoded ("Upload a photo or scan as JPEG, PNG or WebP."), an image above 25 megapixels
  or a progressive or CMYK JPEG above 20 megapixels (from its header, before decoding), and an upload that waits more
  than 5 seconds for another upload's decode ("Other photos are being processed. Try again in a moment."). Decodes
  run one at a time per cache lock store, and uploads are limited to 20 a minute per IP address. Images above 16
  megapixels (WebP: 8 megapixels) are scaled down to that size. A stored image is at most 5 MB: a larger result is
  encoded again at quality 75, then scaled to 12 and 8 megapixels (an opaque PNG becomes a JPEG), and is refused only
  if it is still larger.
- **KYC review: Hide documents holds, and the page times the end from the server (#257, #258).** Which clients' documents a
  staff member has open is now a database row (new table `kyc_review_unlocks`) changed under a row lock, not part of the
  session. A request that began before Hide documents can no longer write the old unlock back when it saves its
  session, and a Show request from a page loaded before Hide is refused (a generation number travels with the page).
  The review page now gets the seconds left from the server and counts them on its own clock, so a browser clock that
  runs ahead no longer makes the reload at the end arrive early and re-encrypt the numbers in the browser history; if
  the server still says the unlock is open, the page waits again. **Operators:** run `php artisan migrate` (adds
  `kyc_review_unlocks`, expand only). Documents open at upgrade time are hidden again on the new release (staff press Show documents once); for one release the
  old session entry is still read so its browser history is cleared when it ends, and Hide also removes it (a
  compatibility shim, removed in the release after next). During a blue-green overlap or after a rollback, Hide documents
  served by the old release does not close a row the new release wrote; that unlock ends by itself within 15 minutes.
  Each browser session has its own unlock: Hide and sign-out in one browser end the others' too, and those browsers
  clear their history on their next page.
  An unlock now belongs to the session that opened it (a hash of the session id is stored, never the id): another
  browser or a copied session cookie of the same login sees nothing, signing in again does not bring it back, a changed
  session id ends it, and signing out ends every unlock of that staff member. Show documents now requires the
  `generation` the page sends.
- **Key rotation counts distinct keys (#279).** A key listed in both `APP_KEY` and `APP_PREVIOUS_KEYS` (or as `base64:`
  and decoded) counts once, so restoring the old `APP_KEY` no longer makes `migrate` refuse again. The refusal now says
  to restore the key the email hashes were made with or remove the stale key from `APP_PREVIOUS_KEYS`; the key-rotation
  runbook says the same. No schema or permission change.
- **Integration logs mask more secret shapes (#190).** The module, registrar and gateway logs, transaction and refund
  provider answers, and error text shown to staff now also mask: a JSON value quoted after a label
  (`HTTP 500: {"api-key":"…"}`, nested, arrays, escaped quotes), XML element values (`<password>`, EPP
  `<domain:authInfo>`, CDATA), OpenSRS `<item key="reg_password">` items and secret XML attributes (a `key` attribute that is the element's only value, as in `<license key="…"/>`, stays masked; it reads as a label only when the element has text, a non-blank CDATA section or a non-blank `value` attribute; child elements, comments, blanks or an empty `value` alone do not make it one), the rest of a value
  after `;` or `,` (`passwd=ab;cd`), URL userinfo (`https://user:pass@host`), percent-encoded `api_key=` pairs, Stripe
  secret keys, webhook secrets and client secrets, Khalti secret keys, and the `Key`, `whm` and `cpanel` authorization
  schemes. A secret pair behind any number of labels or long values is found (before, past two labels or 4 KB the
  value was kept). Ids, amounts and references stay readable (Stripe ids, Khalti `pidx`, eSewa `transaction_uuid`,
  ResellerClub `auth-userid`, OpenSRS `order_id`, Namecheap `DomainID`, and a Stripe id after a `Session:`
  label when the id is the whole value; anything joined to it, a UUID or any other value is masked). Also masked: EPP `<newPW>`, cPanel and WHM session cookies (`cpsession`, `whostmgrsession`) and
  every cookie on a `Cookie:` line, `print_r` dumps (`[password] => …`), pretty-printed JSON with the value on the next
  line, HTML-escaped JSON, an `&` inside an unquoted secret, field names up to 255 characters, `curl -u` credentials
  (quoted or attached) and a raw `@` in a URL password. A secret XML element with no end tag masks the rest of the
  text; an XML `<param name="password">` with child elements, comments or CDATA is masked to its end tag, and a value
  on the line after a secret label is masked even when it contains `=` or `:` (`token:\nQWx…==`). Text is read in one linear pass: a 1 MB adversarial string, including many
  distinct unclosed secret elements, is masked in under a second.
- Registrar errors re-thrown by the gateway keep their exception class, retry flag and `previous` chain; the message
  of every exception in the chain is masked. Webhook delivery errors and receiver answers, failed automation run
  summaries, usage-sync errors reported to the log, and the provider answer stored on a card capture's transaction
  are now masked too. A webhook receiver's answer is cut to its stored 2 KB before it is masked, so a large answer
  costs nothing to mask; a JSON answer with nothing secret is stored as received, and one with a secret is stored
  re-encoded (whitespace can differ).

### Migrations wait briefly for table locks (#290)

- Migration DDL on PostgreSQL no longer waits indefinitely behind a long transaction. Before, an `ALTER TABLE` behind
  one held up every later read and write of the table until that transaction ended. `LockTimeout::run()` sets
  `lock_timeout` (default 3s), retries 4 times with a 1, 2, 4 s backoff, then stops the migration with an error naming
  the table and the sessions in the way. `OnlineIndex` builds and drops use it too. Concurrent index builds wait for
  every older transaction, so they get 60s and 3 attempts, with the timeout set for the session and restored
  afterwards. New migrations use it (`docs/architecture.md`, rules 5 and 6); migrations already merged are unchanged.
- `OnlineIndex` now compares the partial-index `WHERE` before it reuses an existing index of the same name. Before,
  an index with the same columns but another predicate, or none, was kept without a word; now the migration fails.
  Columns in a predicate may be qualified by the table name.
- A concurrent index build whose last attempt fails now drops the INVALID index it left; before, a unique one kept
  rejecting rows until the next deploy. An INVALID index that another session is still building is never dropped:
  the migration waits for that build instead.
- **Operators:** no schema or permission change. A deploy can now stop at a migration that cannot get its lock:
  let the named transaction finish or end it, then deploy again. `DB_MIGRATION_LOCK_TIMEOUT` and
  `DB_MIGRATION_CONCURRENT_LOCK_TIMEOUT` (PostgreSQL durations such as `5s`) change the waits. The Docker `migrate`
  service now runs the cache-table migration first and then `migrate --isolated=1`: a second deploy started while
  one is migrating stops with "The [migrate] command is already running." (exit 1). A killed migrator holds that
  lock for up to an hour; `docs/devops/production.md` shows how to release it.

### Bikram Sambat dates beside AD dates on invoices (#207)

- Admin → Settings → Billing → "Show the Bikram Sambat date beside the AD date on invoices" (off by default). When on,
  the issue and due dates on the admin and client invoice pages, the invoice PDF and the key-facts rows of invoice
  emails also show the BS date: "12 Jan 2026 (28 Poush 2082 BS)". Month names are in English transliteration.
- BS dates cover 2000–2095 BS (14 April 1943 to 14 April 2039); a date outside that range shows the AD date only.
  Years after the current panchang (about 2084 BS onwards) are projections from public calendar tables. Fiscal-year
  invoice numbering in BS is not part of this change.

### PostgreSQL test database names (#285)

- `scripts/pg.sh` gives a slug longer than 42 characters a shortened database name (55 bytes: its first 46 bytes, `_`,
  and 8 hex digits of its SHA-256). Shorter slugs keep their names. Parallel test workers and the race tests' extra
  databases now stay within PostgreSQL's 63-byte limit, so they no longer collide. No change for users or operators.

### Stripe smoke command without Billing (#269)

- `php artisan payments:stripe-smoke` stops with an error before asking for a key when the `billing.gateways.return`
  route is not registered (PaymentsStripe loaded without Billing). It used to fail with an exception. No schema,
  permission or configuration change.

### Step-up window from the environment (#273)

- `AUTH_STEP_UP_SECONDS` sets how long a password confirmation unlocks sensitive changes. It is optional and defaults
  to 900, the same as before, and an empty value counts as unset. It must be a whole number from 60 to 3600; any
  other value stops the application at boot with a message naming the value received. Documented in `.env.example`, `docs/devops/install.md` and ADR 0018 §4.

### Architecture tests check every target (#275)

- The architecture rules in `tests/Arch` with several targets (kernel → module imports, Eloquent models in DTOs and
  events, database use in provisioning drivers) passed whenever one target was clean, so a violation in the others
  went unreported. Each rule now has one target, and each file fails if one of its target namespaces has no classes.
  No violations were found on `develop`. No change for users or operators.
- The target check now also accepts single classes, and the concrete driver registries and the base `DriverRegistry`
  are covered, so renaming one of them fails the build instead of leaving its rule empty (#277). No change for users
  or operators.

### Hosted payments: continue or pay another way (#123)

- After a declined card on Stripe Checkout, the client invoice now offers **Continue payment**, which reopens the same
  Stripe page (its address is read from Stripe at the click, only for the client's own open payment, and only on
  Stripe's checkout host), and **Use another method**. The second asks for confirmation, expires the session at Stripe
  first and frees the invoice only when Stripe's status confirms it can no longer be paid. If Stripe reports it paid,
  the payment is recorded instead. A double click closes it once.
- **Use another method** is offered only by methods that can close their payment page at the provider (Stripe
  today). eSewa and Khalti don't offer it, and a direct request to close one of their payments is refused without a
  status check or any change: their "not found" or "initiated" answer doesn't mean the customer's other tab can no
  longer pay. If Stripe still holds the session or can't be reached, the client sees why, for example "The payment
  page is still open, so the payment could still be made there". Each close sends Stripe a new idempotency key, so a
  transient Stripe error is not replayed on the next click.
- After a cancel on eSewa, the invoice now keeps a "Payment not completed" card with **Pay a different way** and
  the "Don't pay again" warning until the eSewa session time is over. A late success is still booked as before.
- Operators: no migration, no setting and no permission change. Two new client routes,
  `invoices/{invoice}/payments/{reference}/continue` and `…/close`, are throttled like Pay. Closing uses
  `Cache::lock` and so needs the shared lock store (#64). Kernel: `ResumesHostedPayment`, `CancelsHostedPayment`,
  `HostedSessionCancel`.

### Identity verification (KYC)

- Clients can verify their identity in the client area (Identity verification): confirm the account email, save a
  phone number (numbers without a prefix get `+977`, set in `kyc.phone_country_code`), and upload two different
  documents out of Citizenship certificate, National ID (NID) and Driving licence, each with its number and a front and
  back image. A banner on every client-area page shows the status (to do, pending review, declined) until the
  client is verified. Verification never blocks ordering, paying or using services.
- Images must be JPEG, PNG or WebP by their content (SVG and PDF are refused), up to 5 MB each. Images and document
  numbers are encrypted at rest; clients see only the last four characters of a number.
- A document waiting for review or declined can be uploaded again, and the old images are deleted; an approved one
  cannot be replaced. Documents are deleted 90 days after the account is closed (a reopened account keeps them), by
  the daily task `kyc.prune_documents`, at most 500 per run.
- Staff review: Admin → Clients → Identity verification shows summary cards (pending review, declined, verified, not
  started), a status filter and search, so staff can find any client's verification state; the client profile links
  to it. Numbers and images stay hidden until the staff member confirms their password for that client; they then
  show for up to 15 minutes (never longer than the password confirmation), through signed links bound to that staff
  member. Approve and Decline stay disabled until the documents were shown. A decline needs a reason. Every decision
  emails the client (new editable template "Identity document decision"), with the reason for a decline and one
  button to the verification page. Each opening, image view, approval and decline is in the activity log, without
  the number or the image.
- Staff review: when the 15-minute window ends, the page hides the numbers and images at once and reloads, and the
  browser history of the review page is cleared on the first page after the window ends, so Back or a restored tab no
  longer brings the numbers back (#240). Logging out while documents are shown clears it too, and so does every visit
  to the login or two-factor challenge page, where a session that ended on the server (idle expiry, deactivated
  account) leads. A new **Hide documents** button ends the window early for that client; it needs no password and is
  recorded in the activity log ("Identity documents hidden again", event `kyc.documents.closed`). New route `POST
  admin/kyc/clients/{client}/lock` (permission `kyc.documents.view` or `kyc.documents.review`); no schema change.
- Documents uploaded before images were re-encoded (#286) can be re-encoded with `php artisan kyc:reencode-images`
  (#287). A document whose images could not be re-encoded shows a note on its staff review card asking for a new
  upload.

### Client import

- **Admin → Clients → Import clients**: upload a CSV file of clients (up to 20 MB, 100,000 rows and 200 columns; each
  a setting) into a named source. Files must be UTF-8 (other encodings are refused with the fix); the separator
  (comma, semicolon or tab) is detected or chosen. The file is encrypted before it is stored, read on the `imports`
  queue into encrypted rows 500 at a time, and then deleted. Columns named like passwords, card numbers or security
  answers are never stored. The page shows the progress, a "did not finish" alert after 10 minutes without a worker
  with a button to continue, every earlier upload, and a notice when the same file was uploaded to the source before.
- **Map columns**: each column of the file becomes a client field, the External ID, an imported field (kept as is with
  its header as label) or nothing. Suggestions come from the header names, with a preset for the WHMCS clients export;
  password and card columns stay ignored. First name, last name and email are required. Dates are read in the chosen
  order (year-first is picked automatically). Options: what to do when an email matches a client (skip or update),
  blank cells on update, closed clients, company-name and tax-ID duplicate flags, logins, and trusting the file's
  verified column.
- **Check**: every row is checked on the `imports` queue, 1,000 rows per job, with the admin client form's own rules
  (shared in Core, so the two cannot disagree), ISO country codes, enabled currencies (blank means the default) and
  client groups by name. Client groups not found stop the check until each is mapped, created or dropped; currencies
  not enabled can be mapped; unrecognised countries are listed. Spreadsheet damage (numbers in scientific notation,
  Indian postcodes missing a leading zero) is a warning.
- **Duplicates** (client-import spec §7): the same email or External ID twice in the file makes the later row an error
  naming the first; a row imported from the source before comes out unchanged or as an update; an email that matches
  one client is skipped or updated by the option, with the fields an update changes; several matches, a closed match,
  a match whose email changed in the last 30 days, and (when flagged) the same company name or tax ID need a decision;
  a merged match resolves to the client it was merged into; an email that belongs to a contact is a warning.
- **Check results**: summary cards (new clients, updates, unchanged, skipped, needs a decision, errors, tax-exempt),
  tabs with counts, and every row with its file row number, the matching client and its notes; search by row number
  or exact email. Nothing is imported yet: reviewing and applying is the next step.
- **Import** (client-import spec §5.6): confirming needs a recent password, the digest of the rows reviewed (a new
  check or a changed decision needs a new review), no row left needing a decision, and an acknowledgement when
  tax-exempt clients are included. One import runs per install at a time. Rows are applied on the `imports` queue,
  200 per job, each in its own transaction: a stopped import continues where it stopped without creating anything
  twice, and a new client whose email someone added after the review is not created. Clients are created through
  Core's importer with origin `import`, the email source `import`, `imported_at`, the sign-up date as their creation
  date, imported fields, and a marketing consent row with its evidence (never turning a withdrawal into a grant);
  closed rows are created closed without a login. An update changes only the fields the file maps (defaults such as
  currency, status and tax exemption apply to new clients only). **An import never changes the email of an existing
  client or of any login**: the review and the report say "Email in the file differs: kept the current one". A
  currency change on a client that already has invoices, quotes, payments, credit, services, orders or domains is a
  row error. Each new open
  client gets an owner login whose password hash is of a random secret nobody keeps, so it cannot sign in until an
  invitation sets a password. **An import never links an existing login on its own**: a row whose email belongs to a
  client login needs a decision (link it, only when its email was verified at least 30 days ago, or create the client
  without a login); a staff login's email gets no login. The id map records every row, so the same file again comes
  out unchanged. No email is sent, and no `ClientRegistered` or other client event fires; `MigrationRunCompleted` fires
  once, after commit, and `MigrationRunPaused` when an import stops. Continue goes through the same one-at-a-time
  check as confirm, and each row is claimed under a lock, so no row is applied twice. The page shows the progress and
  the counts by outcome.
- **Review and report**: each checked row can be switched to the choices it allows (create, update the matched client,
  or skip; for an email that belongs to a client login, "create and link the existing login" when that login's email
  was verified 30 days ago or more, or "create without a login"), and the counts and review digest follow without
  reading any row payload. A refused decision shows its reason on the page. "Accept all proposed" creates the rows whose
  company name or tax ID matched a client and leaves rows matched to a closed client, several clients or a recently
  changed email, and rows whose email belongs to a login, for staff, saying how many. After the import the rows are
  listed by outcome (created, updated, unchanged, skipped, not imported) with a link to each client. The rows with
  errors download as CSV (row, column, reason and the original cells; formula-like cells prefixed with an apostrophe),
  generated on request and never stored; staff need `migration.runs.execute` and a recent password.
- **Send invitations** (import report → Send invitations, client-import spec §5.7): lists the owner logins the import
  created that nobody has used (no sign-in, no two-factor authentication, the password they were created with), by
  state (not invited, waiting to send, invitation sent, expired), filtered by clients created or also updated by the
  import, client group and status. Closed clients are never invited, and a login staff chose to link (it has its own
  password) is never listed. Sending needs `migration.runs.execute` and a recent password; it queues the logins not
  invited yet or whose invitation expired (an open invitation is not replaced in bulk) and sends at most
  `migration.invite_per_hour` an hour, install-wide (default 500, Admin → Settings → Client import). Each login gets
  its own single-use link through the same invitation flow as a login staff add (it sets the password and confirms
  the email address) with the new editable template **Invitation after a client import**
  (`client.migration_invitation`): never copied (CC and BCC are dropped), the link only in the button, never in the
  subject, and never resent from the email log. A login that signed in, or whose client closed, after it was queued
  is skipped. Without the Platform module, or while the link host check fails, the button is disabled with the reason,
  and a sender that finds links unsendable stops and leaves the rest queued. A send that fails, or a sender that dies
  part way, marks that login "Not sent" with the reason, and it can be sent again; an invitation the template system
  refused leaves no open invitation behind (also for invitations staff send from a client's logins). The hourly limit
  needs a cache store shared by every worker (database or Redis). While email is switched off ("Stop sending email")
  or the template is turned off, the sender holds: no link is issued, the logins stay "Waiting to send", the page says
  why, and "Continue sending" sends them once mail is on.
- **Opening credit balances** (client-import spec §6.12): a column mapped to **Opening credit balance** (suggested for
  headers such as Credit or Opening balance) is read as an amount in the client's currency, never as a float: plain
  digits, western grouping (1,234,567) or lakh grouping (1,25,000), with at most two decimals after a point. A decimal
  comma ("250,50"), loose grouping ("1,2,3"), a negative amount or a credit in another currency than the client's is a
  row error. Billing posts the balance automatically only for a client the import creates ("Opening balance from
  <source>", reason `import`, in the client's currency, under the client's row lock), and on a later run of the same
  client only the difference, while its credit has not moved since the last import. Otherwise (an existing client
  matched for the first time, or credit that moved) nothing is posted and the row says "Existing credit kept; post the
  opening balance manually if it is still owed." The review asks Billing what apply would do, so a balance shows as a
  change only when it would be posted, and the same file again comes out unchanged. Each run posts at most one entry
  per client. Undo, when it is built (spec PR 5), takes back exactly what a run posted while the balance still covers
  it. Without the Billing module the review warns that balances will not be imported.
- **Tax-number notes** (client-import spec §6.6): Nepal PAN/VAT numbers that are not 9 digits, Indian GSTINs that are not
  valid, and GSTINs registered in another state than the address (place of supply follows the GSTIN) are warnings
  in the review; a Nepal business without a PAN gets a note, and an EU VAT ID that makes invoices reverse charge gets a
  note. None of them blocks a row. Numbers are stored as invoices use them (spaces removed, uppercase, for Nepal, India
  and the EU).
- The client profile shows the columns an import kept, under **Imported fields**, with the import date and source.
- Uploads that are never applied lose their rows after 7 days (`migration.staging_days`, housekeeping task "Purge
  expired client imports"); their counts stay. Applied imports keep row details for 7 days (`migration.undo_days`); an
  import stopped partway and never continued loses them 30 days after its last activity and can no longer be continued.

### Storefront

- New home page (direction A): the host's own headline and text from the published website content, domain search with featured extensions and their
  first-year and renewal prices, the most popular plan (the catalog's Featured flag), and sections the host turns on
  and orders: why choose us, featured plans with a billing-cycle switch, more products, how can we help, news and
  network status, ways to pay. The product's own name and tagline never appear.
- One public layout for home, store, cart, checkout, domains, knowledge base, announcements, network status and the
  404 page. The header lists the catalog's visible product groups live (a link each up to three groups on wide screens,
  otherwise one menu with taglines and "from" prices in the visitor's currency), Domains (register, transfer, prices), Support
  (with an open-incident count where network status is public), a currency switcher, the cart and Log in or Client
  area; on phones it becomes a menu sheet. The footer shows the company details, payment methods enabled for the
  visitor's currency (official eSewa and Khalti logos, names for the rest), the tax statement and legal links.
- Price cards show the cycle price, "Renews at" and any setup fee; with `catalog.show_monthly_breakdown` on they lead
  with the per-month price and "Billed …". Each currency states tax for its own market from Billing's tax rules
  (`storefront.tax_markets`: "+13% VAT at checkout" for NPR, "+18% GST at checkout" for INR whatever the rules are
  named, by `storefront.tax_families`; "Tax added at checkout where applicable" for USD and other currencies). Showing prices with tax included is a per-currency choice, off by
  default, and adds tax only to taxed products (and to domains while domains are taxed).
- Home sections also include trust facts ("Hosting since 2014") under the headline and a "Ready to get online?" band
  with the lowest plan price; both can be switched off and reordered. The footer shows support hours, WhatsApp,
  registration and tax numbers (one "PAN/VAT no." row when the VAT number is the PAN), social links and "Pay an
  invoice"; the Support menu lists network status and Contact us, and the phone menu lists the cart. The 404 page
  links to Hosting plans, Knowledge base, Contact us and Home. Log-in and sign-up pages link back to the website.
- Customer surfaces (public site, login, sign-up, invitation) show the host's uploaded logo or mark, else the name;
  never the product's own logo or mark.
- Admin → Website: headline, text, sections on/off and order, trust facts, why-us points, the call-to-action band,
  featured groups and first billing cycle, news counts, header product menu, legal links, page title and description, and price display per currency
  (tax included or not, and the tax note's wording). Changes are saved as a draft, previewed at a signed staff-only
  link under `/admin/website/preview` (60 minutes, not indexed, no Referer, admin sign-in checks) and published after a
  dialog that lists the changes. Publishing refuses a draft that was saved again after the dialog opened, is
  audit-logged with the change list and fires `StorefrontPublished`; draft saves are audit-logged with the changed
  sections, and at most one draft can exist. A fresh install is marked "Needs your text" until the host publishes a headline.
- `/domains/pricing` lists register, renew and transfer prices for every extension sold in the visitor's currency.
  "Transfer a domain" opens the domain search with transfer preselected.
- Search: the home page and the 404 page send their title, description, canonical URL, robots and Open Graph tags
  in the first HTML response, plus `Organization` and `WebSite` JSON-LD on the home page. `/sitemap.xml` lists the
  home page, store, domain prices, public knowledge-base articles and live announcements.

### Performance

Measured on the load-test data at 20,000 clients (143,247 invoices), PostgreSQL 18 capped at 2 CPUs, one request at
a time: medians of three runs before and three after, on the same database, on a shared host (load 4 to 7).

- **Admin dashboard:** first paint with an empty cache 5.7 s → 0.75 s, the deferred widgets 6.0 s → 0.42 s, with the
  60-second cache warm 400 ms → 283 ms; peak memory 125 MB → 39 MB (the deferred request failed at PHP's 128 MB
  default). Receivables, monthly recurring revenue, tax collected and weekly sign-ups are summed in SQL, one row per
  currency, instead of reading every open or recently paid invoice and every service into PHP. The figures are
  unchanged: the full dashboard payload is identical byte for byte in UTC and in Asia/Kathmandu. MRR is cached for
  60 seconds, as the Billing figures already were.
- **Search on PostgreSQL:** admin global search 226 ms → 47 ms, invoice list search 220 ms → 113 ms, the client
  picker 70 ms → 28 ms; the services and clients list searches spend less time in the database (70 → 50 ms and
  42 → 27 ms). Contains searches use trigram indexes instead of reading the whole table (#48).
- Tests now fail if a dashboard query reads unbounded rows of invoices, services, clients, tickets or transactions,
  if the admin invoice, client, service and ticket lists or the client invoice and service lists query per row, or
  if a searched column has no usable index on PostgreSQL.

### Platform foundation

- `INTEGRATION_ENDPOINTS` and `INTEGRATION_CA_BUNDLE` point fixed-host integrations at local simulators (ADR 0016).
  They are honoured only when `APP_ENV` is `local` or `testing`; only https base URLs without credentials, query or
  fragment are used, and the connection stays verified and pinned. In any other environment they are ignored and
  logged as critical at boot, `app:install` fails its environment check, and the admin dashboard's system health shows
  a red "Integration endpoints" check (amber, with the keys, while they are active in development). No integration
  uses them yet. A malformed key is never shown, only counted, since it may be a pasted URL.
- Developers get `php artisan simulate:list|start|stop|clean|record|scan` (ADR 0016) from the dev-only `mohar/simulators`
  package in `tools/simulators`: a provider simulator runs on free local ports behind TLS signed by a per-scenario
  CA, with a token-gated control plane for faults (latency, 5xx, rate limits, slow or truncated bodies, a hang after
  commit, a reset before commit, certificate rotation) and signed callbacks. The first provider simulator is `whm`;
  `stripe` follows. The commands exist only when `APP_ENV` is `local` or `testing`, and a `composer install
  --no-dev` build contains neither the commands nor the package (checked in CI).
- Developers get a `whm` simulator (`php artisan simulate:start whm`), a stateful cPanel & WHM server. Point a cPanel
  server at it (host 127.0.0.1, the printed port and token), run Test connection and trust its certificate, and the
  real driver provisions, suspends, changes packages and passwords, terminates and opens login links against it,
  including root and reseller logins. `simulate:record whm` records a real server's read-only answers for
  comparison, and `simulate:scan` checks recordings for credentials and personal data before they are committed.
- Developers get a `stripe` simulator (`php artisan simulate:start stripe`), Stripe in test mode: Checkout with a
  hosted page (pay, a declined card, back), PaymentIntents, charges, refunds, disputes raised from its control plane,
  idempotency keys and the API version header, and signed webhooks for the app's endpoint. With the printed
  `INTEGRATION_ENDPOINTS` and the gateway's test keys set to the simulator's, the real Stripe gateway takes payments,
  settles them from webhooks and lookups, refunds and records disputes against it.
- Developers and CI get `php artisan simulate:e2e`, the hosting lifecycle end to end against the `whm` and `stripe`
  simulators on a throwaway database. It covers install, a trusted cPanel server, an order paid on Stripe Checkout,
  provisioning, overdue suspension and unsuspension, cancellation, a refund, a dispute, forged and duplicate webhooks,
  and a create that times out and is adopted. CI runs it in its own job, `E2E simulators`.
- `simulate:e2e` runs in NPR, the install default: the product, invoices and Stripe payments are in NPR instead of
  forcing USD at install.
- A refund or dispute webhook that arrives before its payment is booked (Stripe can send a dispute first) is answered
  409 and logged in the gateway log as "payment not booked yet; retry expected"; Stripe's retry books it.
- The Stripe gateway reaches its API and checks its checkout host through the integration endpoints
  (`stripe.api`, `stripe.checkout`). Without an override nothing changes.

- Laravel 13 app on the official React starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui),
  Fortify auth (2FA, passkeys), Wayfinder, Pest 5, Larastan level 7, Laravel Boost AI guidelines & skills.
- Modular monolith: auto-discovered `app/Modules/*` with conventions for config, migrations, routes, views,
  commands and scheduled tasks; `MODULES=` filter for isolated test runs (ADR 0001).
- Shared kernel: `Money` (minor units, ADR 0002), `BillingCycle`, `BillableType`, cross-module contracts
  (`Invoicer`, `TaxCalculator`, `CreditLedger`, `PriceBook`, `ServiceRegistry`, `DomainRegistry`,
  `DomainPricing`, `MergeFieldRegistry`), DTOs and 37 domain events.
- Server-driven sidebar navigation and dashboard-card registries; admin (`/admin`) and client (`/client`) areas.
- Object storage: `objects` disk backed by RustFS via `compose.yaml` + `objects:ensure-bucket` command (ADR 0004).
- CI back from about 14.5 to about 10 minutes (579–622 s per job): the PostgreSQL step went from 550 s to
  212–309 s (no pre-suite migrate and seed, direct container connection, savepoints released inside the test
  transaction, 4 workers, the database-free Unit and Arch suites on SQLite only, the demo billing history seeded once
  per worker with `useSeededDatabase()`), and Pint and tsc reuse cached results. The job warns past 12 of its 15
  minutes. `composer test:pgsql` no longer migrates or seeds the base database and skips Unit and Arch;
  `PEST_PROCESSES` sets its worker count (docs/devops/ci.md, ADR 0006 amended).

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

- Import accounts: a currency change on a client at the same moment as a confirmed import can no longer leave the
  client with an imported service in its old currency (#239). Each account reads the client again under a share lock
  before its service is created. If the currency changed since the review, the account is not imported and the
  report says why, for example "The client's currency changed from NPR to USD since the review, so it was not
  imported in USD. Review the account again."; review it again to import it in the new currency. A currency change
  that reaches the client while its service is being created waits for it and is then refused as usual. No
  migration and no permission change.
- Import accounts, step 1 (fetch and match; the page follows): staff with the import permission fetch every account
  on a server that can list them (cPanel/WHM). The fetch runs on the queue, and each account is matched once when it
  finishes: proposed when exactly one open client has its contact email and exactly one product uses its package on
  that server; unmatched with the reason otherwise; listed apart when another WHM user owns it (a reseller's customer
  on a root connection, which signs in as root when no username is set); linked when a live service already holds it,
  with differences in domain, package, suspension and IP. Live services whose account is missing on the server are
  recorded. WHM usernames and contact emails that are not valid are left out. Nothing is created or changed, on the
  server or here; the request and its result are recorded in the activity log.
- Import accounts page (Admin → Servers → server menu → Import accounts): summary cards, tabs with counts, search and
  pages over the stored match. The rows shown are re-checked against today (a service created since links the account,
  a client closed since is dropped, as is a product that no longer provisions there). "Match again" re-matches on the
  queue against today's clients, products and services, comparing owners with the user the fetch signed in as, without
  asking the server; it needs a recent password and is recorded in the activity log. Unmatched accounts link to creating a product for their package; live services missing on the
  server link to the service to cancel or terminate it; a fetch that never finished says so, with Fetch again.
- Import accounts, step 2: staff tick accounts (nothing is ticked by default; "Select all proposed" ticks the proposed
  ones and opens the review) and review them, a page at a time, with the price-book amount, totals per cycle and a list
  of accounts left out with the reason. Confirming imports exactly the reviewed rows: the matched client is pinned when
  the review is shown, and the import is refused if any account, client, product, date or price changed since. The
  import runs on the queue in chunks; the page shows its progress, then each account imported (with a link to its
  service) or left out (with the reason). Each account becomes a service, active or suspended as on the server, with
  no invoice, welcome email, module command or password, keeps its day of the month for billing, is recorded in the
  activity log, and dispatches `ServiceImported`. Choosing a client or product per account follows in the next step.
- Import accounts, choices: staff choose the client (a picker that lists the most recently added active clients
  before anything is typed; it needs the permission to view clients), product, billing cycle and next due date per
  account, and the cycle and due date for every ticked account at once from the review. Review rows link back to change
  them and say when a client group discount explains a lower price; the date field shows the renewal date in the app's
  format. "Select all proposed" leaves out, and the page flags, accounts whose matched client changed email in the last
  30 days. "Fetch again" keeps choices for accounts still on the server. A choice reaches an import only through a new
  review.
- Import accounts, stopped and failed runs: an import that stops partway keeps the ticks and choices of the accounts
  it did not reach, says once why it stopped ("The background job took too long.") and offers "Review the remaining N",
  which goes through a new review. The last import's report lists accounts not imported first, a page at a time
  behind "Show details", marks unreached accounts "Not reached", and stays on the page after "Fetch again" until the
  next import starts. A failed fetch no longer hides the last list: the error shows above it with a link to the server
  page to test the connection. The ticked count refreshes when an import finishes. The bulk cycle says how many ticked
  accounts it leaves out for lack of a price and can return them to each account's default. The choice dialog saves
  only the fields staff change: clearing the client or product (or picking "As matched") returns the account to its
  match, clearing the cycle or date returns it to its default, and a flagged match becomes a choice only through "Use
  this client". The client picker, on every page that uses it, shows a loading state, opens on click or typing rather
  than on focus, and works from the keyboard (arrow keys, Enter, Escape). The review's billing column wraps on phones.
- Import accounts, flagged clients: a matched client who changed email in the last 30 days, or who signed up and has
  not verified the email, is shown on the row with the date but not proposed, and "Select all proposed" leaves it out.
  The review checks again against the client it matches at that moment, shows the flag and keeps the account out unless
  staff chose that client themselves. A chosen due date that has passed by the review keeps the account out until a new
  date is chosen. "Set the cycle and due date" for ticked accounts accepts only the cycle and due date. Choices carried
  by "Fetch again" are dropped for an account re-created under the same username (another creation time on the server).
- Import accounts, continuing: going on after a stop keeps one report for both runs, the stop banner separates failed
  accounts from those not reached, paging the report keeps its details open ("Hide details"), and cycles read "no
  semi-annual price" in sentences.
- Import accounts page fixes: a service whose username differs only in case stays linked on the page; the Missing on
  server count matches its tab; a "Match again" whose queue job never ran no longer hides the list (it is usable again
  after 10 minutes); and on a list fetched before the connection user was recorded, Match again lists owned accounts
  apart instead of proposing them, until the list is fetched again.
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
  value, and a change is audited as "rotated". Custom fields without a module key are no longer sent to drivers. A failed save never flashes a secret into the
  session (#65).
- **cPanel & WHM driver** (`cpanel`, #69). Creates, suspends, unsuspends and terminates cPanel accounts through WHM
  API 1, changes their package and password (the MySQL password follows), and reads disk and bandwidth for the daily
  usage sync. It can also list the server's packages and accounts and create cPanel and WHM login links; the package
  select, login buttons and account import that use them come in follow-up releases. Sign-in uses an API token
  (recommended) or username and password, root or reseller. Every call goes over TLS to the checked address only and
  within a time limit; a self-signed server works after its certificate's SPKI fingerprint is trusted, and a changed
  key is refused. Create checks the username first and moves to the next free name when the server already holds
  it. An existing account is taken as the service's only when an earlier create with an unknown outcome made it
  (same username and domain, created after that attempt began). Test connection reports address and certificate,
  sign-in, token permissions and expiry, root or reseller, packages and name servers, and is limited to 6 runs a
  minute per staff member. `php artisan provisioning:cpanel-smoke` checks a real server (read-only unless
  `--lifecycle --confirm-lifecycle`).
- **Test connection opens the server form with a checklist** (#73): each step passes, warns or fails in a plain
  sentence, with the WHM version, root or reseller access, token expiry and name servers. When a server presents a
  certificate that is not trusted, staff see its subject, issuer, expiry and SPKI fingerprint and can trust exactly
  that fingerprint ("Trust this certificate": the sensitive server permission and a recent password, audited).
- **Unknown outcomes settle themselves.** `services:verify-unknown` runs every five minutes and asks the server whether
  a command whose outcome was unknown ran, for the first hour after the attempt; staff can press "Check now" later.
  A command that ran completes as if it had answered; a conflict or an account still in progress stays on the
  failed-actions queue with what the check found. For a create, "Create again" is offered only once the check finds
  no account. Works for any driver that can check its server (cPanel and the demo driver today). Each check answers
  for one attempt: a new attempt needs a new check, "Create again" needs a check from the last 15 minutes, and a
  service moved to another server or edited since the attempt is a conflict for staff. A check never settles an entry
  that was settled, dismissed or retried meanwhile, and a busy service is skipped with a message. Check now is limited
  to 6 a minute; "Mark resolved" and "Dismiss" are now "Mark fixed" and "Ignore". A "not on the server" answer opens
  Create again once, and the create re-checks it under the service lock before sending anything. A create settled by
  a check stores the account's IP and plan like any create.
- The server form shows only the connection fields the driver reads (cPanel: port, username, and an API token or a
  password per "Sign in with"). A trusted certificate is shown read-only with a Remove action (sensitive, recent
  password, audited); trusting one shows the fingerprint to compare and a copyable command, and an offer is dropped
  when the server's address is edited. Errors from Test connection and from trusting now show on the form. The form
  never sets a pin. Switching "Sign in with" removes the credential no longer used (audited), and the form says so
  before saving; it cannot be switched to a method whose credential is neither entered nor stored. Values WHM reports
  (IP, plan, owner, domain) are checked before they are stored, and a settled create never changes the service's
  username or password. Ignoring an entry cancels a Create again already requested for it.
- Module log entries of a create record the username and domain it sent. Log redaction also masks `session=` values
  in URLs.

### Core

- Each client records how it was created (sign-up, checkout, staff, import or API) and who set its current email
  (staff, import or the client), both written by the path that does it and never by a form. Account import proposes an
  email match only when staff or an importer set the address, or a login on that client (owner or not) has verified
  it, and the address has not changed in the last 30 days. Otherwise the row shows "Email set by the client and not
  verified" and staff choose the client themselves. Before, a client-area login could set the email of a
  staff-created client, or a sign-up's owner could delete their login, and after 30 days the match was proposed.

- Clients, client groups, contacts, multi-user client accounts (owner + per-area permissions), staff flag,
  roles with wildcard permissions, currencies, settings store, activity log.
- Admin: client list/search/filter, create, view, edit. Client area: dashboard, account details, account switcher.
- Registration creates an owned client account.
- Seeders: currencies (USD/EUR/GBP), roles, `admin@example.com` / `demo@example.com` (password: `password`).

### Platform admin

- Currencies: the ISO code is fixed once a currency exists. The edit form shows it read-only with the reason, the
  update request refuses a different code, saving display settings never writes the code, and the `Currency` model
  refuses any other attempt to rename it (#238).
- Automation → Run now queues one run at a time: while a run waits or runs, another request is not queued, and the
  page says "An automation run is already queued or running." During the scheduled run it says "An automation run
  is in progress"; a request queued just before a scheduled run starts is skipped and recorded in the activity log
  ("Automation run skipped"). Runs of any length finish on one worker (#82).
- `php artisan automation:release-locks` releases the automation locks a killed worker left behind (otherwise Run
  now is refused for about 65 minutes after the interrupted request). It asks for confirmation, `--force` skips it,
  and the release is recorded in the activity log.
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

- Registrar refs store (registrars.md C4, ADR 0009 PR C3): a new `registrar_refs` table (migration `500600`, expand
  only) behind the kernel `RegistrarRefs` contract, where registrar drivers keep the remote customers, contacts and
  profiles they create for local records; a profile credential is encrypted at rest. Nothing uses it until the first
  API registrar driver; no permission change. Upgrade note for driver authors: the unused module registrar interface
  and its DTOs and exceptions are deleted (see the kernel changelog).
- Registrar drivers run on the kernel registrar contracts (ADR 0009, PR C2), so a package can ship a registrar
  driver without the Domains module's code. Admin → Domains → Registrars and the domain pages list the same
  capabilities as before, plus "Connection test" for drivers that offer one; for a driver without it the "Test
  connection" button is disabled with "This registrar has no connection test." The connection test is now written to
  the registrar command log and still runs while the registrar is disabled, so new credentials can be checked before
  enabling it; the account's credentials are masked in what staff see. Every message a registrar driver returns (and
  a transfer's EPP code, if a driver echoes it) is masked before it is stored or shown: command log, last error,
  activity log, the "registrar action failed" event and mail. No schema or permission change. Upgrade note for driver
  authors: `RegistrarManager::extend()` is removed, and the module interface
  `App\Modules\Domains\Drivers\Registrars\Registrar` and its `Data` DTOs are no longer used (deleted in #265);
  implement `App\Contracts\Domains\Registrar\RegistrarDriver` and register it with
  `RegistrarDriverRegistry::register()` (see the kernel changelog).
- `.np` self-registration flow (registrars.md §9). TLDs can be marked "registered by the client at the registry" (on
  by default for `.np` and its second levels). Domain search explains that the client registers these names at the
  registry, links to "How to register", and offers "I already have it", which opens the store with "Use a domain I own"
  preselected. Checkout accepts `.np` names only under the registry's second levels (`.com.np`, `.org.np`, `.edu.np`
  and the rest) and creates no registrar order. The client's hosting service shows the nameservers to set at the
  registry, with a Copy button: the server's, else the default nameservers an admin saved (never the shipped
  placeholder). Until real nameservers exist, staff get the to-do "Set your nameservers so clients with their own
  domain know where to point it." A bare `.np` name in the search explains the second levels instead of offering
  "I already have it". The demo marks `.com.np`, `.org.np`, `.edu.np` and `.net.np`
  this way.
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
- Registrar prerequisites (registrars.md §2): a submission or renewal whose outcome was lost is checked in the
  registrar account with `findSubmission` (executed → applied; in progress → waits for sync; absent or unknown →
  staff review, never re-sent); registrar ids such as order and transfer ids are kept in `registrar_meta.remote`;
  the hourly sync recovers submissions a killed worker left pending (#12); a shared contract test every registrar
  driver runs (`tests/Support/Contracts/RegistrarDriverContract.php`).
- Log redaction masks a credential pair quoted behind a label, as in `Rejected: api-key=…` (it was kept whole).
  The registrar gateway also masks the account's configured credentials by value in its log and in the error text
  that reaches the domain's last error, the activity log and `RegistrarActionFailed`.

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

- **Renewals and checkouts no longer fail when the client's credit is spent at the same time** (#192). Issuing an
  invoice that applies account credit, and placing an order, lock the client before inserting anything for it. On
  PostgreSQL two renewals, or two checkouts, of one client applying credit no longer deadlock (one of them used to
  fail). The credit applied is read under that lock: credit spent meanwhile lowers it, and with none left the invoice
  is issued unpaid. Checkouts of one client now run one after the other.
- **Fewer database queries per issued invoice** (renewal runs, and every other invoice):
    - On 20,000 clients a billing day's 4,251 renewals took 42.7 queries each, now 34.7. On this shared host that was
      138 → 126 ms per renewal (medians of three alternating runs).
    - Issuing reuses the client and the new lines it has just written instead of reading them again, and reads the
      order's payment guard only when there is account credit to apply.
    - Invoice, proforma and quote numbers take one query fewer after a period's first number.
    - Webhooks no longer build a payload when no endpoint subscribes to the event. As a result, an error in a payload
      resolver for such an event is no longer reported to the error log; with a subscribed endpoint it still is.
    - The stored result is unchanged: a full renewal run on that data leaves every invoice, line, service link, credit
      entry, transaction, email and queued job identical.
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
- Late gateway payments (#130): each one writes an activity entry ("Late payment received after the attempt
  closed: check and refund if needed") and raises the dashboard to-do "Late payments to check" until staff mark it
  checked on Payment attempts (new "Late payments" filter; marking needs a recent password and is recorded once).
  A hosted payment that goes to account credit (the invoice was already paid or cancelled, or the payment was held
  for review) gets the same to-do. The client sees the payment on the invoice and any surplus on the account credit
  page. A provider refund of such a payment takes the credit back, up to what is left, once per refund id (one
  credit line, even when nothing was left; staff see a zero line, the client does not); the rest goes to "Gateway
  payments to review", as do a closed attempt the provider reports as partly refunded and a late payment whose
  attempt no longer matches the invoice currency. "Check now" also works on expired and failed attempts. The
  customer's return looks a closed attempt up only within the late window, and a session the provider reports as
  expired, failed or cancelled is not swept again.
- Payment tests (no behaviour change): the gaps and duplicates listed in `docs/testing/integration-test-matrix.md`
  are closed. New tests cover hosted payments on part-paid invoices, the invoice lock against a second checkout (a
  real concurrent PostgreSQL session), cancel on the sandbox hosted page, Stripe failing or timing out on session
  create, unsupported currencies and minimum amounts for Stripe and eSewa, a dispute or chargeback that arrives before
  its payment, and the `REDIRECT` capability rule. Per-driver duplicates are folded into datasets over every hosted
  driver. Found: the sandbox card driver's `initiate()` accepts a currency it does not list (#155); Billing refuses
  that currency before the driver is called.

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
- eSewa: an attempt lasts eSewa's 50-minute booking window plus a margin (60 minutes), and a cancel on eSewa's page
  ends the attempt at once, so the customer can pay again (see the upgrade note). The eSewa "Test connection"
  reports `ES104` as a wrong product code or secret key, and any other refusal, such as a duplicate transaction
  uuid, as a refused test booking.

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

### Notifications

- Email templates: 66 system templates for client and staff mail, per-language translations (recipient language,
  then the default, then English), custom templates, preview in light and dark with sample data, test send and a
  template select on products and addons. Templates use a sandboxed syntax (`{{ path | filter }}`, `{% if %}`,
  `{% for %}`) that runs no code, escapes HTML and Markdown and has size and loop limits.
- One branded layout for every email, framework and module mail included: logo (light and dark), the key-facts
  block, a primary button, a text part, and a footer with address, help line, help-centre link and, for client mail
  that can be switched off, the email-preferences link. The sender name is the company name; senders are set per
  category (default, billing), and every mail has a Reply-To (billing, the ticket's department, or support).
- Events from Billing, Orders, Services, Domains, Support and Core send the mapped templates, once per occurrence.
  Recipients follow the account's email preferences, with the owner as fallback for invoice and domain mail; closed
  accounts get none. Staff alerts go to the roles chosen per category under Setup → Staff email alerts.
- Clients manage their email preferences and see their email history in the client area; at least one address on
  the account keeps receiving each required category.
- Email log with search, status, the stored copy, Resend, and retention of bodies (`notifications.prune` in
  housekeeping). Failed mail shows in "Needs attention".
- Outbound webhooks: endpoints per event type (28 events), HMAC-SHA256 signatures with secret rotation, https only,
  private and reserved addresses refused, retries with backoff (1 minute up to 24 hours between attempts), automatic
  disabling after repeated failures with a staff alert, a test ping, and a delivery log with resend.
- Private links reach only their owner. Password-reset, verification and invitation links, and a guest's ticket
  link (which carries its access key), are never part of template text: the system adds them as its own button,
  checks the finished mail before it is sent, and masks them as •••• in the log. Mail that carries one is never
  copied (no CC, BCC or global BCC); ticket CCs get the reply without the link. Account-mail text is plain and uses
  first names and the company name only.
- Resend is disabled for logged mail that carried a private link, with the reason shown; for ticket mail the page
  links to the ticket.
- Admin dashboard: "Account email" health check.
- Every email uses design direction A, "two-card receipt": the brand above a summary card (who it's from, a large
  amount or a title, a status line such as "Due in 14 days" or "Overdue by 3 days", one button that names the action
  and amount, "Pay ₹588.82", download links and key facts) and a record card (what was bought with subtotal, tax
  lines and total, then reassurance and the help line). The card is 640 px wide and fluid below that, renders in
  light, dark and Outlook.com dark modes, and keeps AA contrast; the brand name is live text next to the mark so it
  stays readable when a mail client inverts colours. A theme accent is used only when its email colour pairs pass AA.
- Plain-text parts are written text in the same order: status, amount, opening, "Label: link" actions, aligned key
  facts, line items and the footer. Lists become "- " lines and links "text (url)"; no Markdown or HTML.
- Templates have a preheader (the inbox preview line), editable in the template editor with a character count.
- Suspension email names the overdue invoice and its amount ("Your invoice INV-1906 for Rs 12,769.57 is overdue")
  with a button that pays it; reason codes and staff notes are never shown to the client.
- Payment reminders and updated invoices have their own subjects, with the due date.
- The footer's "write to" address is the address replies go to (billing, the ticket's department, or support).
- System mail carries `Auto-Submitted: auto-generated` and `X-Auto-Response-Suppress: All`; mail staff write by
  hand does not.
- Dates in email use the date-format setting and the company time zone; times add the zone.
- The email log shows template names instead of keys.
- A button comes only from the template's own action line or from the system: a link in a client's message or in
  a merged value never becomes the main button. Staff ticket mail puts the "Open ticket" button first and quotes the
  client's message below it. Links typed into names and ticket subjects are shown inert (`https[:]//evil[.]example/pay`);
  names such as "Example.com Ltd" stay as typed.
- An invitation whose link fails the security check is not sent by any other path: staff see "Invitation not sent"
  on the form and a security event is logged. Laravel's own notification is used only when the Notifications module
  is absent, with its link built on `APP_URL`.
- Partly paid invoices show a "Paid" row between the total and the balance; refunds and credit notes state their
  amount; the text part drops links that the HTML drops. Staff-written ticket replies are not marked automatic.
- Email wording uses "Log in" and "Forgot your password?" throughout.
- The template editor shows "New default available" when a release changes the wording of a template you edited,
  with your text and the new default side by side and "Use new default", which keeps your text as the previous text.
- A new client login's invitation opens its own page to choose a password. The link works once, for the configured
  number of days; the email says how long and until when. Sending a new invitation (client page → login actions →
  "Send a new invitation", shown with an "Invited" or "Invitation expired" badge) or removing the login revokes the
  old link. Expired, used and unknown links get a clear message. Staff invitations state their expiry the same way.
  An invitation only ever activates a never-used login: its link stops working once the login changes its email
  address, gets a password another way (for example "Forgot your password?"), turns on two-factor authentication,
  signs in, or loses access to the account, and such a login can't be invited again ("Send a password reset" instead).
  Accepting never signs in past two-factor authentication. A client merge moves open invitations with their logins.

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
- Registrar driver contracts (ADR 0009, PR C1): `RegistrarDriver` (`settingsFields`, `register`, `renew`, `info`)
  plus capability interfaces (`ChecksAvailability`, `SuggestsNames`, `TransfersDomains`, `RestoresDomains`,
  `ManagesNameservers`, `ManagesLock`, `ManagesContacts`, `ProvidesEppCode`, `ManagesIdProtection`, `SyncsDomains`,
  `ReconcilesSubmissions`, `TestsRegistrarConnection`), the stateless `RegistrarConfig` every call receives, the
  `DomainRef` snapshot instead of the domain model, the result DTOs with remote ids (C1), `RegistrarResult` and
  `SubmissionLookup` gaining `withMessage()`, the `RegistrarRefs` contract for remote customers and contacts (C4), and
  the final `RegistrarDriverRegistry`. A package registers a registrar driver from its own service provider without
  the Domains module being loaded, and every registrar driver passes a shared contract dataset. Domains still runs on
  its own driver interface until PR C2; nothing changes for users.
- `PriceBook::moduleInputs()` (options and custom fields by module key, hidden, admin-only and retired included) and
  `PriceBook::productsForDriver()`; provisioning snapshots carry options by module key. `ProvisioningDrivers::all()`
  gains `fields` in the shared `SettingField` shape (`config_fields` deprecated). Module keys of custom fields are unique per product (#65).
- `Notifications\SystemMailer` contract (Core binds a null default, Notifications the templated one): Platform sends
  invitations and account security notices through editable templates, one recipient per call. It returns
  `App\Enums\SystemMailOutcome` (`Taken`, `Refused`, `Unavailable`); callers fall back only on `Unavailable`.
  `AccountLinks::problem()` is the one check that account links can be mailed. `Brand::$emailMark` carries the
  host's raster mark for mail; `BrandProvider::emailLogoUrl()` is now the opt-in email logo.
- `ServiceImported` event; `ConnectionCheck` gains an optional checklist (`ConnectionCheckStep`, `CheckStatus`) and
  details; `ProvisioningDrivers::remoteOptions()` for remote-filled selects; `GatewayDriver` extends
  `IntegrationDriver`.

### Security

- Security review 2026-10-19 fixes: the inbound email webhook secret is encrypted at rest, never sent to the browser,
  masked in the audit log and behind a sensitive permission; stored server credentials are never sent to a host
  other than the one they were saved for, and the server form warns before an address change removes them;
  registration, password reset and client hosting-password changes are throttled; a failed password reset shows
  the same message whether or not the address has an account; webhook deliveries are pinned through the kernel
  outbound guard. Two tests now guard every future change: one fails when a route behind a sensitive permission
  lacks step-up, the other when a credential-like setting is not a secret.
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

### Payments — Stripe

- Not yet verified against a live Stripe account: tested with recorded Stripe API fixtures and, once ADR 0016 S3
  lands, the Stripe simulator. The live sandbox smoke run (`docs/devops/install.md` go-live checklist) is required
  before a host takes real card payments.
- Stripe gateway as an extension module (`app/Modules/PaymentsStripe`, spec `docs/product/payments-stripe.md`):
  invoices are paid on Stripe Checkout and settled only from a server-side retrieve of the session and its
  PaymentIntent, matched on amount, currency and reference. REST calls through Laravel's HTTP client with a pinned
  `Stripe-Version` and an idempotency key on every POST; no SDK.
- Signed webhooks (constant-time `Stripe-Signature` check, 5-minute tolerance, mode check) trigger a retrieve and
  never trust the posted body: payments, Dashboard refunds (`refund.created`/`refund.updated`) and disputes
  (`charge.dispute.created`, recorded as chargebacks). Duplicates and races with the return route and the poller
  record one transaction.
- Full and partial refunds through the Refunds API; exact amounts for zero-decimal currencies; test mode refused
  in production; connection test; Stripe's official wordmark; `php artisan payments:stripe-smoke` for a real
  sandbox run.

### DevOps

- Deployment, part 4 (D4): the manual "Build image" workflow (`workflow_dispatch` only) builds the image for amd64,
  arm64 or both as an OCI artifact with SBOM and provenance (7 days), reports each platform's compressed size against
  a limit, packs the release tarball for hosts without Docker (new Dockerfile target `release`), and optionally pushes
  the very same archive to GHCR (`skopeo copy --preserve-digests`, `packages: write` only in that job). cPanel shared
  hosting is supported in cron mode (`docs/devops/cpanel.md`, `deploy/cpanel/crontab.example`), tested by
  `deploy/cpanel/simulate-cron`. The cron worker lines are those of `Queues::workerCommands()` (a Deploy probe
  fails on drift), so client imports are worked in cron mode. Cron mode has no catch-up for daily entries: the
  automation time must be a multiple of the cron interval (`docs/devops/cpanel.md`). The GHCR push runs only from the
  default branch and the build cache is scoped to the built ref.

- CI security audits only on dependency changes (owner decision 2026-10-21). `composer audit` and `npm audit` now
  block a PR or push only when it changes a Composer or npm manifest or lockfile, an `.npmrc` or a workflow file, so a
  newly published advisory no longer turns every open PR red. A new advisory against unchanged lockfiles shows up on
  the next dependency change or a manual `composer deps:check` (no nightly run: scheduled workflows run only from the
  default branch, `main`, which stays a landing page). Lockfile sync and the dependency cooldown check still run on
  every PR.
- `vite-plus` 0.3.3 → 1.1.0 (and the `vite` alias/override `@voidzero-dev/vite-plus-core` 0.3.3 → 1.1.0). The
  core bundled `launch-editor` 2.14.1, affected by GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw and GHSA-587h-7wmj-h5pm
  (published 2026-10-20; `npm audit --audit-level=high` failed CI); 1.1.0 is the first fixed release and bundles
  `launch-editor` 2.14.2. Brings Vitest 5.0.3, Oxlint 1.87.0 and Oxfmt 0.72.0; the new Oxfmt re-indents list-item
  continuation lines in 12 Markdown files (whitespace only). Requires Node `^22.18.0 || ^24.11.0 || >=26.0.0`
  (`.nvmrc` is 24.21.0). The `vite-plus` package no longer ships `oxlint`/`oxfmt` wrappers: editors use
  `vp lint --lsp` and `vp fmt --lsp`.
- Deployment, part 3 (D3): blue-green deploys on one host. `deploy/bin/deploy` checks and runs the migrations with
  the new image, starts the idle colour beside the live one, switches Caddy with a graceful reload once it is
  healthy, moves the single scheduler and drains the old workers (each finishes its current job), and keeps the old
  web container for `deploy/bin/deploy rollback`. `php artisan deploy:check-migrations` enforces the new
  expand-then-contract rule (`docs/architecture.md`): an `up()` that drops, renames or changes a column or table
  fails unless tagged `@contract` or `@compatible`. It runs before every deploy and locally (`--since`), not in CI.
  Caddy's config directory is mounted rather than the single Caddyfile, so a release that changes it takes effect
  at the deploy's reload (a file mount kept the pre-`git pull` file).

- Deployment, part 2 (D2): `deploy/compose.prod.yaml` runs the whole stack on one Docker host behind Caddy
  (automatic TLS, the only published ports): the app, the three ADR 0014 worker pools and the automation worker
  (`queue:work automation`, 3,660 s stop grace), the scheduler, a one-shot
  migrate, PostgreSQL and RustFS on an internal network. Hardened PHP containers, json-file log rotation and
  resource limits. Runbook: `docs/devops/production.md` (install, update, backup, restore, rollback, Traefik
  variant); settings: `deploy/.env.production.example`. `deploy/bin/smoke` and the manual "Deploy smoke" workflow
  (`workflow_dispatch` or the `deploy-smoke` PR label, never automatic) install the stack on a GitHub runner and
  check `/up`, sign-in and a queued job through Caddy.

- Deployment, part 1 (D1): one container image for web, workers, scheduler and migrations
  (`deploy/Dockerfile`, `docs/devops/container-image.md`). FrankenPHP 1.13 in classic mode on Debian trixie,
  a slim runtime stage without the PHP build toolchain (420 MB unpacked, 162 MB compressed, down from 1.2 GB),
  non-root, read-only root filesystem, OPcache, `/up` healthcheck, cold start 1.2–2.8 s. `deploy/compose.dev.yaml`
  runs the `dev` target with Vite. The dependency check now also requires digest pins and the 7-day cooldown for
  `FROM` and `COPY --from=` images in `deploy/`, and Dependabot watches `/deploy`.
- `TRUSTED_PROXIES` (`config/trustedproxy.php`): reverse proxies allowed to set the client IP and scheme. Unset
  means none, now also on `*.on-forge.com`, `*.on-vapor.com` and Laravel Cloud, where the framework used to trust
  every proxy; only the `X-Forwarded-For`, `-Host` and `-Proto` headers are honoured (not `-Port`, which Caddy
  passes through from clients).
- Inertia SSR is off by default (`INERTIA_SSR_ENABLED`). There is no SSR bundle, so production pages already
  rendered client-side; the per-request bundle lookup and, with Vite running, the dev-server SSR attempt are
  skipped. Set it to `true` once an SSR entry exists.
- Fixed: a parallel test worker ran out of memory (1 GB) once #69 added about 400 tests. Pest's arch plugin cached
  about 770 MB of parsed source in the worker that ran `tests/Arch` and never freed it. Each Arch file now releases
  the cache (`releaseArchCache()` in `tests/Pest.php`), and the rules are split across three files. The worst worker
  peak with #69 merged fell from 1,019 MB to about 330 MB of PHP memory (#75).
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
