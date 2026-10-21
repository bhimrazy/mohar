# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Progress, status and screenshots: [docs/progress.md](docs/progress.md).

## [Unreleased]

### Upgrade notes

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
  built concurrently on PostgreSQL. Not handled yet: a dispute the merchant wins books nothing, so correct the
  credit by hand. Disputes and refunds booked before the upgrade are not revisited: check clients whose payments
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
- Uploads that are never applied lose their rows after 7 days (`migration.staging_days`, housekeeping task "Purge
  expired client imports"); their counts stay.

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
