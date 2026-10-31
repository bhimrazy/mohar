# Client import — Product Spec (v1)

Status: draft for engineering · Owner: Migration workstream (module `app/Modules/Migration`, block `98xxxx`) · Kernel
changes need CTO approval (§16) · Owner decision 2026-10-20: this comes before cPanel PR 2.
Clean-room: behaviour comes from the public WHMCS user and developer docs, the public docs of Blesta, HostBill, Upmind,
Paymenter, ClientExec and FOSSBilling (§23), and our own code. No WHMCS source, encoded files or licence internals were
used. WHMCS parameter and field names are quoted only where a connector must send or parse them; all other wording is ours.

This spec **extends** `migration.md` (the WHMCS importer). It builds the first slice of that pipeline (clients, contacts,
logins, groups, currencies, credit) and puts a CSV connector in front of it. Where it changes `migration.md`, the change
is listed in §2 and noted at the top of that file.

## 1. Purpose and scope

The cPanel account import (#87, #101, #95) links accounts on a WHM server to clients that already exist in Mohar. A host
switching from WHMCS has no clients in Mohar yet, so that import has nothing to link to. Client import fills that gap
first, in two steps:

1. **CSV client import (phase 1).** Any host, from any system: upload a file, map its columns, check every row, review
   duplicates, confirm, and the import runs on the queue in chunks. A report follows, with an undo window and an audit
   trail. This is what most hosts need first, and what a WHMCS host can do today with WHMCS's own client export.
2. **WHMCS importer (phase 2 onward).** Clients and contacts first, through the documented WHMCS API and the same
   importers; then products and services, invoices with balances, transactions, domains and tickets, as in
   `migration.md`. The phase plan is §19.

**In scope (phase 1):** clients, owner logins (without a password), client groups (map or create by name), client
currency, tax id and exemption, marketing consent with its evidence, email-verification state (only when trusted),
custom columns kept as imported fields, opening credit balance, sign-up date, status, language, admin notes; contacts as
a second file type (SHOULD).
**In scope (phase 2a):** the same data from WHMCS 8.0+ through the API, plus users with their per-account permissions.
**Never:** plaintext passwords, password hashes in a CSV file, card or bank numbers, 2FA secrets, security answers, or
anything that needs the WHMCS encryption key.

## 2. Relationship to `migration.md`

Kept as written: the read-only API allowlist (§2.1), the id map and checksums (§6), automation safety (§7), the dry-run
report shape (§8), source credentials and the SSRF guard (§9), and the `RecordImporter` / `ImportRegistry` kernel
contracts (§12). Client import is their first consumer.

Amended (each is called out in §16 and at the top of `migration.md`):

| #   | `migration.md` says                                       | This spec                                                                                                                                           | Why                                                                                                     |
| --- | --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| A1  | Jobs run on queue `migration`                             | Jobs run on `imports`, a new `App\Support\Queues::IMPORTS` constant                                                                                 | ADR 0014 allows only the named constants; a 50k import must not starve `default`                        |
| A2  | Unmapped client custom fields are appended to admin notes | They are kept as **imported fields** (label and value per client), shown on the profile                                                             | Notes are free text that staff edit; structured fields can be converted to custom fields later          |
| A3  | `GetContacts` has no documented paging; fetch per client  | It is paged (`limitstart`, `limitnum`) and filterable by `userid`; the connector pages per client                                                   | The API reference documents both (read 2026-10-20)                                                      |
| A4  | Marketing preferences are not covered                     | Consent is imported with its evidence into `client_marketing_consents` (§6.8)                                                                       | A boolean is not evidence of consent                                                                    |
| A5  | Importers dispatch only `App\Events\Migration\*`          | Unchanged, and `MigrationRunUndone` is added. Importers also set `email_changed_at` themselves, because `saveQuietly()` skips the model hook (§6.2) | The cPanel import trusts that column (3b-2)                                                             |
| A6  | Direct database connector: out of scope                   | Still out of scope by default. A narrow, read-only "gap reader" is an **owner decision** (§3.3, Q1)                                                 | It is the only way to carry password hashes; the 2026-10-17 decision stands unless the owner changes it |
| A7  | The Migration module is a WHMCS importer                  | The Migration module also hosts the CSV connector and the import pages; a run has a `source_kind` (`csv`, `whmcs_api`)                              | One pipeline, one id map, one report for every source                                                   |
| A8  | `ImportOutcome(targetId, action, issues)`                 | Gains a trailing `changes` (list of field names) so the review can say what an update would change                                                  | The review must show what an update does before it happens                                              |
| A9  | The API allowlist is read-only                            | Unchanged for the importer. Sign-in carry-over (§3.4) uses a **second** credential and a separate allowlist naming only `ValidateLogin`             | It is the only documented way to check a WHMCS password without a database export                       |

## 3. Where the data comes from

### 3.1 A CSV file (phase 1, MUST)

Any spreadsheet. For a WHMCS host the file comes from **Reports → Exports → Clients** in the WHMCS admin, which builds a
custom client export with up to five filters, and **Tools → Export to CSV** on the report (WHMCS docs, Reports, read
2026-10-20). The same Exports group has Domains, Invoices, Services and Transactions exports, which phase 3 can reuse.
The per-client "Export Client Data" tool produces JSON for one client and is not a bulk path.

We ship a mapping **preset** named "WHMCS clients export" that recognises likely headers. The exact column headers of that
export are not in the public docs: **verify** against a sample export with fake data (Q11). The preset only suggests
mappings; staff can change each one. **Status (2026-10-20, #162): the preset's headers are unverified until a sample
export arrives.** It maps `ID` to External ID and otherwise relies on the generic header words.

### 3.2 The WHMCS External API (phase 2a, MUST)

As in `migration.md` §2.1, with these actions for the client phase: `WhmcsDetails`, `GetCurrencies`, `GetClientGroups`,
`GetClients` (with `orderby=id`, 8.2+), `GetClientsDetails`, `GetContacts` (paged, by `userid`), `GetUsers` (8.1+),
`GetUserPermissions` (8.0+), `GetCredits`. What `GetClientsDetails` returns that matters here: names, company, address,
`countrycode`, phone, `tax_id`, `taxexempt`, `currency` and `currency_code`, `groupid`, `language`, `status`, `notes`,
`credit`, `customfields` (id and value pairs only, no names), `email_preferences` (7.10+), `email_verified`,
`marketing_emails_opt_in`, `emailoptout`, `uuid`, `users` with `is_owner` (8.0+), and `lastlogin` (read 2026-10-20).

What the API does **not** give: password hashes for 8.x logins (`GetClientPassword` is deprecated since 8.0 and only ever
covered the client record), 2FA state, client custom-field names, the date a client opted in to marketing, and the
per-user verification date. **Verify:** the `GetUserPermissions` reference shows only `result` in its example, and
`GetUsers` lists a `validationdata` field without describing it. The connector must be tested against a real 8.x
response before we rely on either (Q11).

Throughput is bounded by the source: about 3 calls per client (`GetClientsDetails`, `GetContacts`, and
`GetUserPermissions` per login link), plus list pages. At the default 5 requests a second, 50,000 clients take about
8 hours; the host can raise `migration.whmcs.max_rps` for their own server. The page shows an
estimate before the run starts. For a large host the CSV path (§3.1) is faster for the first load, and the API run then
adds what the CSV lacks (users, permissions, consent, verification) through the same id map, matched by email.

### 3.3 A read of the WHMCS database with the host's own credentials (owner decision, Q1)

Blesta's Import Manager and HostBill's importer read the WHMCS database directly and ask for the WHMCS encryption hash to
decrypt stored cards; Paymenter imports a database dump into a temporary database (all read 2026-10-20). The 2026-10-17
decision (`requirements.md`) rejected a database connector. This spec keeps that default and offers a narrow option for
the owner:

- **Gap reader (only if approved, phase 2a.1):** read-only database credentials that the host creates; the connector reads
  only the columns the API cannot give: login password hashes, a "2FA enabled" flag (never the secret), the custom field
  names, and the user verification state. It reads nothing else: no card, bank, pay-method or configuration tables, no
  admin table, no encryption hash, no service passwords.
- **Where the schema knowledge comes from:** the host's own database (the connector lists the columns it finds and
  refuses to run when an expected column is missing), and table names that public WHMCS docs mention. Reading data the
  host owns, with the host's credentials, is not decoding WHMCS; the boundary in `CLAUDE.md` is about WHMCS's code.
- **Cost:** a schema can change between versions without notice, so each supported version needs a fixture, and the
  host must open their database port to us (or run the reader on their side). Recommendation: **not in v1**; reconsider
  after the first real migration, and only if hosts ask for password carry-over (Q2).

### 3.4 Sign-in carry-over through the WHMCS API (phase 2a.2, SHOULD; proposed 2026-10-31, Q13)

Research and the comparison of options: `docs/research/2026-10-31-whmcs-login-migration.md`. Kernel hook: ADR 0018
Amendment (#555).

The goal is that returning clients sign in with their old email and password and never reset them. WHMCS documents
`ValidateLogin`: it checks an email and password against a WHMCS user and returns `userid`, `twoFactorEnabled` and a
WHMCS session token. The token comes back only when no 2FA is required (API reference, read 2026-10-31). We check each
password once, at the client's first Mohar sign-in, and then keep our own hash. No hash or export moves, so this works
under the API-only decision (Q1).

- **Setup (per WHMCS source).**
    - A second API credential whose role grants only `ValidateLogin`. It is stored encrypted and declared in
      `EncryptedStorage` (ADR 0022).
    - A base URL on HTTPS whose host is not Mohar's own `APP_URL` host. The old billing hostname usually moves to
      Mohar, so the old WHMCS keeps a hostname of its own.
    - A cut-off date, `login_carry_over_until`, on the company clock (ADR 0013, ending 23:59:59): default 90 days after
      switching on, at most 180. It can be shortened at any time and extended only up to the maximum.
    - "Test" sends one check for a reserved `.invalid` address and expects "Email or Password Invalid". It shows any
      other answer with its reason.
    - The wizard says that `ValidateLogin` updates the last-login time at the source, so it is not a read-only call.
- **Eligibility.**
    - The WHMCS API run (PR 8) writes one `migration_login_checks` row per WHMCS user it turns into a Mohar login that
      has no password of its own: owners and non-owner users alike (§6.11).
    - CSV-only logins are not eligible, and neither are contacts or logins from WHMCS before 8.0 (Q10).
    - A row closes when the login gets a password any other way: an invitation, a reset, or staff setting one.
- **Sign-in.** Mohar's own hash is checked first. Only when it fails does the kernel hook (`PendingPasswordCheck`,
  ADR 0018 amendment) ask Migration. Migration calls `ValidateLogin` when all of these hold:
    - the row is open;
    - the login's email still equals the email as imported (a keyed hash);
    - the cut-off has not passed;
    - the source is not paused;
    - the budgets allow it.

  The call uses the email as imported and the typed password, over `OutboundAddressGuard` (ADR 0015: pinned, no
  proxy, no redirects, TLS verified, `http://` refused).
    - Success with `twoFactorEnabled` false and the `userid` recorded at import: in one locked transaction, store Mohar's
      bcrypt hash of the typed password and mark the row `migrated`. The sign-in continues like any password sign-in.
      The session token is discarded unread.
    - Success with `twoFactorEnabled` true: no sign-in. The bcrypt hash is held in the row for 30 minutes, and a
      single-use link goes to the login's address, bound to the browser by a nonce cookie (the social sign-in SS-17
      pattern). Opening the link stores the hash, verifies the email, signs the client in through the normal session
      steps, and sets the "had 2FA" flag (§6.10). The template is `client.migration_confirm_signin`, always sent; at
      most 3 links an hour per login.
    - "Email or Password Invalid": an ordinary failed sign-in.
    - A different `userid`, a malformed answer, a redirect, a timeout, a 5xx or an unknown action: a failed sign-in and
      a **fault**. 10 faults in 10 minutes, or one "unknown action" (WHMCS has said `ValidateLogin` may be deprecated),
      pause the source and alert staff.
- **Budgets and timing.**
    - The remote check runs after the `login` limiter, within 5 checks an hour per login, and within 5 a second and at
      most 4 at once per source.
    - Counting is atomic, with the add-then-increment pattern of `PasswordGuesses` (#536).
    - Timeout: 5 seconds in total. Over the concurrency cap, the answer is "try again in a moment" and no call is made.
    - While any source has carry-over on, every failed password sign-in answers no sooner than
      `migration.login_carry_over.min_failure_ms` (default 1000, with jitter), so the remote wait does not reveal
      which emails are imported.
    - The answer text is the same for an unknown email, a wrong password, a remote refusal and a fault. Unknown emails
      are never sent to WHMCS.
- **After a carry-over.**
    - `email_verified_at` is unchanged, because knowing the password does not prove the mailbox (§6.7). The 2FA path
      above is the exception.
    - The typed password is checked against Mohar's rules. When it fails them, the client must choose a new one before
      using the client area (SHOULD).
    - The activity log records `auth.password_carried_over` with the source, never the password.
- **Secrets.**
    - `password2` and `passwordhash` join the redaction keys (ADR 0023).
    - The connector never logs request bodies.
    - The password is never stored in plain text, queued, cached or put in a job payload. The call is synchronous
      inside the sign-in request.
- **After the cut-off** (or when staff pause or switch it off): the open rows expire, and the hook answers "not
  applicable". Those clients use "Forgot password", or the invitation (§5.7), whose page gains the filter "not carried
  over".
- **Admin.** Migration → Sources → <source> → Sign-in carry-over:
    - the status (on, paused with the reason, or off) and the days left;
    - counts: eligible, carried over, confirmed by email, waiting for confirmation, expired, and the percentage;
    - the last 24 hours: accepted, refused, faults; the last test;
    - actions: Test, Pause or Resume, Change the cut-off, and Invite everyone not carried over.

  Changing anything needs `migration.sources.manage` and step-up. Viewing needs `migration.runs.view`.
- **Client.** During the window the sign-in page shows "Moved from our old client area? Your old password works until
  <date>." It is on by default per source, and the date is in the client's language.

## 4. Entities and key fields

Migration module (prefix `98`). Tables from `migration.md` §4 are reused; the columns below are what client import adds.

- **`migration_sources`** gains `driver = 'csv'`. A CSV source is a named origin ("Old billing spreadsheet"), so its id map
  is scoped: the same external id in two sources is two records. No credentials for CSV.
- **`migration_runs`** (one upload, or one API run): `source_kind` (csv|whmcs_api), `entity` (clients|contacts), `status`
  (uploaded → staging → mapping → checking → ready → applying → completed | failed | expired; completed → undoing →
  undone), `file_key` (objects disk, encrypted, null once deleted), `file_name`, `file_sha256`, `file_bytes`, `row_count`,
  `header` (encrypted json), `mapping` (json: column → target field, transform), `options` (json, §5.3), `review_digest`
  (char 64), counts as plain integer columns (`ready_count`, `update_count`, `unchanged_count`, `decision_count`,
  `error_count`, `created_count`, `updated_count`, `skipped_count`, `failed_count`), `requested_by`, `applied_by`,
  `applied_at`, `undo_until`, `expires_at`, `purged_at`, timestamps. Counts are columns so the page never decrypts rows
  to show them (the cPanel import does the same).
- **`migration_records`** (staging, one per row): `run_id`, `row_number`, `raw` (encrypted json, the cells as uploaded),
  `normalized` (encrypted json), `email_hash` (char 64, HMAC-SHA256 of the lowercase email with a key derived from
  `APP_KEY`; used for in-file duplicates and exact search without decrypting), `checksum`, `status` (ok|warning|error),
  `decision` (create|update|unchanged|skip|needs_decision), `match_client_id` (nullable), `problems` (json list of
  codes and row-safe parameters, no personal data), `outcome` (created|updated|unchanged|skipped|failed, null before
  apply), `target_id`, `before` (encrypted json, the changed fields' previous values, for undo), `applied_at`. Indexes:
  `(run_id, row_number)` unique, `(run_id, decision)`, `(run_id, status)`, `(run_id, email_hash)`.
- **`migration_id_map`** as in `migration.md` §4. For CSV the source id is the mapped "External ID" column, or, when no
  such column is mapped, `email:<email_hash>` (re-runs then depend on the email, and the review says so).
- **`migration_issues`** as in `migration.md` §4.

Core (Core migrations, prefix `0001xx`):

- `clients.imported_at` (timestamp, nullable) and `clients.imported_fields` (json, nullable: list of
  `{label, value, source}`, at most 50 per client, values up to 1,000 characters). Shown read-only on the client profile
  under "Imported fields"; staff can remove one.
- `clients.email_changed_at` comes from cPanel import 3b-2 (#95). Whichever PR merges first adds the column; the other
  drops its copy of the migration.
- **`client_marketing_consents`** (append-only): `client_id`, `state` (granted|withdrawn|unknown), `channel` (email),
  `source` (signup|client|staff|import_csv|import_whmcs|import_undo), `evidence` (text up to 500: what the source said,
  in our words), `consented_at` (nullable: when the person consented, if known), `recorded_at`, `recorded_by`
  (nullable user), `migration_run_id` (nullable). The current state is the latest row. Signup and the profile write rows
  later; this spec only needs the table and the import rows.
- `users.password_imported_at` (timestamp, nullable): only if Q2 is approved (§6.9).

Billing: `CreditReason::Import` (module enum) for opening balances.

## 5. Flows (CSV)

### 5.1 Upload

Admin → Clients → **Import clients** (also listed in Admin → Setup → Migration with every past run). Staff choose a
source (or name a new one), the entity (Clients; Contacts once built) and a file.

- Limits (settings): `migration.csv.max_bytes` 20 MB, `migration.csv.max_rows` 100,000, `migration.csv.max_columns` 200,
  and 32 KB per cell. A file over a limit is refused before it is stored, with the limit in the message.
- Encoding: UTF-8, with or without a BOM. Anything else (UTF-16, Windows-1252) is detected on the first 64 KB and
  refused with "Save the file as CSV UTF-8 and upload it again". Delimiter (comma, semicolon, tab) and quote handling are
  detected and shown; staff can override them.
- The upload is encrypted with the app key **before** it reaches the objects disk (streamed in fixed-size chunks, so
  memory stays flat) and the PHP temporary file is deleted at the end of the request. The run row stores the file's
  SHA-256; uploading a file already imported into this source shows "This file was imported on 20 Oct 2026 by Asha" with
  a link, and lets staff continue.
- A queued job (`StageCsvRun`) streams the file, writes one staging row per line in batches of 500, then **deletes the
  file**. From then on only the encrypted staging rows exist. Mapping changes re-normalise from `raw`, so no re-upload is
  needed.

### 5.2 Map columns

One row per file column: header, three sample values (from the first rows, masked for anything that looks like a
secret), and a target. Targets: every client field in §6, "External ID", "Imported field" (keep as is), and "Ignore".
Suggestions come from header synonyms and the chosen preset; staff confirm them. Required for a run to check: first
name, last name and email, or an External ID plus those three for new rows.

- A header that names a password, hash, card, CVV, security answer or 2FA secret is **forced to Ignore** with the reason
  "Passwords and card data cannot be imported. Clients set a password from their invitation." Its cells are dropped from
  `raw` at staging, so they are never stored.
- Transforms where a field needs one: date format for the sign-up date (`Y-m-d`, `d/m/Y`, `m/d/Y`, chosen from samples),
  yes/no words for flags (`1/0`, `yes/no`, `true/false`, `y/n`, `on/off`, `checked`), and "split full name at the last
  space" when the file has only a full name (SHOULD).
- Values that need a decision are listed with counts at the bottom of the step: client groups not found (map to a group,
  create it, or no group), currencies not enabled (map or leave as errors), and countries not recognised.
- Mappings can be saved per source and reused on the next upload (SHOULD).

### 5.3 Options (on the map step)

- **When an email matches an existing client:** Skip (default) or Update. There is no "create anyway": a second client
  with the same email cannot have its own login, and duplicates are what the import must avoid.
- **On update, blank cells:** keep the current value (default) or clear it.
- **Also flag possible duplicates by:** company name, tax id (both off by default; §7).
- **Logins:** create an owner login for each new client (default on). No password is set; invitations are a separate,
  later action (§5.7).
- **Trust the file's "email verified" column:** off by default (§6.7).
- **Closed clients in the file:** import as closed, without a login (default), or skip.

### 5.4 Check

`CheckRun` runs on the queue in chunks of 1,000 rows. Each chunk normalises its rows, validates them with the same rules
as the admin client form (one shared rule set, so the importer and the form can never disagree), looks up duplicates
with one query per chunk (`lower(email)` index, the id map, and the optional company and tax-id keys), and writes the
row's status, decision and problems. Totals update after each chunk. The page shows progress, and a "did not finish"
alert when no worker has reported for 10 minutes (as the cPanel import does).

### 5.5 Review

Summary cards first, then the rows (product standards: summary, then detail):

| Card             | Meaning                                                                                                                                            |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| New clients      | Rows that will create a client                                                                                                                     |
| Updates          | Rows that will change an existing client; the review lists the fields                                                                              |
| Unchanged        | Rows that match a client already imported from this source with the same data                                                                      |
| Needs a decision | Possible duplicates, a match whose email changed in the last 30 days, a closed or merged match, an email that belongs to an existing login (§6.11) |
| Errors           | Rows that cannot be imported; each shows the reason and the cell                                                                                   |
| Tax-exempt       | New or updated rows that set tax exemption (shown only when above zero)                                                                            |

Tabs carry the same counts. Rows show the file row number ("Row 1,204"), the name, the email, the decision, the notes and,
for a match, the matched client's name (linked), never a database id. Search covers row number, and exact email via
`email_hash`. Per row, staff can switch the decision (create, update, skip; for an email that belongs to a client login, "create and
link the existing login" or "create without a login", §6.11) where it is allowed; a refused decision shows its reason. "Accept all proposed"
leaves flagged rows unticked and says how many it left (the cPanel import's rule for a recent email change).

Errors can be downloaded as a CSV of row number, column and reason, plus the original cells so the file can be fixed
(permission `migration.runs.execute`; generated on request, never stored; every cell that starts with `=`, `+`, `-`, `@`,
tab or carriage return is prefixed with an apostrophe so a spreadsheet will not run it).

### 5.6 Confirm and apply

The confirm dialog states the consequence: "Create 48,212 clients and update 1,033. No email is sent. You can undo this
import until 27 Oct 2026." When tax-exempt rows exist it adds a checkbox: "312 clients will be invoiced without tax."
Confirming needs a recent password (step-up) and sends the review digest; the server refuses when the reviewed rows
changed since (the cPanel import's digest rule).

`ApplyRun` dispatches one `ApplyChunk` job per 200 rows on `imports`. Each row runs in its own transaction: re-check the
match (a client created since the review with the same email fails the row with that reason), call Core's client
importer through `ImportRegistry`, write the id map row, the consent row and the staging outcome together. One apply runs
per install at a time (a lock), because `clients.email` is not unique and two runs could create the same person twice.
A crashed chunk is retried; rows already applied are recorded and skipped, so a retry never duplicates.

### 5.7 Report, invitations and undo

- **Report:** counts by outcome, per-row outcomes with links to the clients, the errors CSV, who applied it and when.
  `MigrationRunCompleted` is dispatched once (§15).
- **Invitations (separate action):** "Send invitations" lists how many owner logins have no password, with filters
  (client group, status, created by this run). Sending needs step-up, is queued, and is throttled
  (`migration.invite_per_hour`, default 100, as in `migration.md` §9). It goes through a kernel contract to Platform's
  invitation flow (§16, C3), with the editable template `client.migration_invitation` ("We have moved to a new client
  area. Set your password to sign in."). Logins of closed clients are never invited.
  **As built (importer B2a):** the contract is `App\Contracts\Auth\ClientLoginInvitations` (C3); the throttle is install-wide and counts invitations sent in the last hour; an open
  invitation is not replaced in bulk (staff send a new link from the client's logins); the 2FA line in the mail
  (§6.10) waits for a source of the flag, which only the gap reader (Q1) would provide.
- **Undo (within `migration.undo_days`, default 7):** a queued run that reverses what is still safe to reverse:
    - a created client is deleted only when nothing else refers to it: the client-merge participants (`ClientMergeRegistry`)
      report no rows apart from the import's own, no login signed in or accepted an invitation, and the client was not
      edited after the import. Otherwise it is kept and listed with the reason;
    - an updated client gets each changed field back only while the field still holds the imported value;
    - an opening credit balance is reversed only while the balance still covers it;
    - consent changes are reversed by a new consent row (`import_undo`), never by deleting history.

    Undo is itself audited, cannot be undone, and ends the run's undo window. After the window, `before` values are purged.

### 5.8 Retention

The file is deleted when staging ends. Staging payloads (`raw`, `normalized`, `before`) are nulled when the undo window
ends, 7 days after upload for a run never applied (`expires_at`), or 30 days after the last activity of an import that
stopped partway and was never continued (the run keeps its status, is marked purged and can no longer be continued).
Counts, outcomes, target ids, problem codes and the id map stay. The housekeeping automation stage does the purge and is
idempotent.

## 6. Field rules

### 6.1 Identity and names

`first_name`, `last_name` (required, 100), `company_name` (150), `email` (required, 255). Values are trimmed, control
characters are removed, and length is validated against the column (PostgreSQL rejects overlong values). The sign-up date,
when mapped, becomes `created_at`; otherwise `created_at` is the import time. `imported_at` is always set.

### 6.2 Email and `email_changed_at`

Lowercased and validated as an address with no display name. On **create**, the client's email source is `import` and
`email_changed_at` stays null. **An import never changes the email of an existing client or of any login (CTO rule,
2026-10-21):** on update, email is not a writable field, whatever matched the row. A difference shows in the review and
the report as "Email in the file differs: kept the current one"; staff change an address on the profile, which sends
the usual security notice.

### 6.3 Address, phone, language

Country as ISO-2, ISO-3 or an English country name, stored as ISO-2; unknown is an error. Indian states are normalised
with the Billing state list (place of supply depends on them). Postcodes and phones are kept as text; a 5-digit Indian
postcode or a phone that lost its leading zero (a spreadsheet artefact) is a warning. Language must be a supported locale,
otherwise the default with a warning.

### 6.4 Currency

ISO code of an enabled currency; blank means the default currency. On update, a currency change is refused when the
client already has invoices, quotes, payments, credit, services, orders or domains: amounts are stored per currency
and cannot be re-labelled. Each module that stores such amounts answers through the kernel registry
`ClientCurrencyUsage`; a module that is not installed holds none.

### 6.5 Client group

Matched by name, case-insensitive. Unknown names are resolved on the map step: map, create (name only, no discount), or
no group. The WHMCS API path imports groups with their discount, suspension exemption and separate-invoices flags
(`GetClientGroups`: `discountpercent`, `susptermexempt`, `separateinvoices`).

### 6.6 Tax id and exemption

- `tax_id` up to 50 characters, uppercased with spaces removed for India and the EU.
- India (`country = IN`): the GSTIN format and state code are checked with Billing's GSTIN rules. An invalid GSTIN is a
  warning ("invoices will show it, but tax uses the address state"). A valid GSTIN whose state differs from the address
  state is a warning that names both, because place of supply follows the GSTIN.
- Nepal (`country = NP`): a PAN/VAT number is 9 digits; anything else is a warning.
- EU: a VAT id with a valid format for another member state makes invoices reverse-charge when VAT is enabled; the row
  note says so.
- `tax_exempt` from a yes/no column. Exempt rows are counted on a card and confirmed explicitly (§5.6). There is no
  exemption reason field on clients; a reason column, if any, becomes an imported field.

### 6.7 Email verification

Mohar keeps verification on the login (`users.email_verified_at`). A new login is unverified unless:

- the source is WHMCS and `email_verified` is true: `email_verified_at` is the import time, and the audit entry says it
  was carried from WHMCS; or
- the source is CSV, the "Trust the verified column" option is on, and the cell says yes.

Accepting the invitation verifies the address anyway (the existing invitation flow), so this matters mainly for hosts
that require verification before checkout. Accepting an invitation in WHMCS does not count as verification (WHMCS docs,
Users and Client Accounts, read 2026-10-20); in Mohar it does, because our link is bound to the address it was sent to.

### 6.8 Marketing consent and its evidence

- CSV: a consent column (yes, no, blank), and optionally a consent date and an evidence column. Yes writes a `granted`
  row with `consented_at` from the date column when present; no writes `withdrawn`; blank writes nothing (state
  unknown). Yes **without** a date still records `granted`, with evidence "Imported from <source> on <date>; the date of
  consent was not in the file", and the review counts these rows.
- WHMCS API: `marketing_emails_opt_in` true writes `granted` with evidence "WHMCS reported this client as opted in on
  <fetch date>"; false with `emailoptout` true writes `withdrawn`; false with false is unknown. The API gives no consent
  date (Q6).
- Consent is never inferred from anything else, and an import never turns `withdrawn` into `granted` for an existing
  client without a per-row decision.
- Contact email preferences (general, invoice, support, product, domain) are transactional categories, not marketing
  consent. They map to `contacts.email_preferences`; WHMCS's `affiliate` category has no counterpart and is dropped
  with a count.

### 6.9 Passwords

- **CSV:** never. Password-like columns are dropped at staging (§5.2). As built (PR B1a, #184), a new login gets the
  bcrypt hash of a random 64-character secret that is discarded, one hash per chunk of 200 rows, so no password signs
  in until the invitation sets one and 50,000 logins cost 250 hashes, not 50,000. It does not wait for #112's nullable
  password. A password reset is possible and acceptable: its link goes only to the login's own address.
- **WHMCS API:** no hashes are available for 8.x logins. Same as CSV at import. With sign-in carry-over on (§3.4), a
  returning client's password is checked once against WHMCS at their first sign-in, and Mohar then stores its own hash.
- **Gap reader (only if Q1 and Q2 are approved):** WHMCS has hashed client passwords with bcrypt since 6.3 (community
  posts citing the 6.3 release notes; the deprecated `GetClientPassword` example shows a `$2y$10$` value). Carrying such
  a hash is safe in the sense that we would store a bcrypt hash anyway, and Laravel verifies `$2y$` hashes and rehashes on
  the next login at our cost (`hashing.rehash_on_login` is on). Rules if approved:
    - accept only `$2y$`/`$2b$` hashes with cost 10 to 14, by strict pattern; refuse the legacy MD5-with-salt format and
      anything else, so those logins get an invitation;
    - set `password_imported_at`; on the first successful login, check the typed password against our policy (length,
      reuse) and set `must_change_password` when it fails;
    - never carry the hash of a login that had 2FA at the source (§6.10): dropping the second factor while keeping the
      password would lower that account's protection without telling anyone, so those logins use the invitation link;
    - the setting `migration.imported_password_max_days` (default 90) stops carried hashes from working after that many
      days without a login; the person then resets the password.

    Hashes are never accepted from a file, because a file is easy to edit: anyone with the import permission could set a
    known password on any login.

### 6.10 Two-factor authentication

It cannot be migrated: the second-factor secret is encrypted with the source system's key, and we never decrypt source
data. WHMCS supports time-based tokens, Duo and YubiKey for clients (WHMCS docs, Two-Factor Authentication, read
2026-10-20); none of them transfer. What happens:

- every imported login starts without 2FA;
- sign-in carry-over (§3.4) learns the flag from `ValidateLogin`'s `twoFactorEnabled` for each client who comes back,
  and sets it when the mailbox confirmation is opened. The banner below then applies;
- when the source says the login had 2FA (gap reader; the import API does not say), the login is flagged: the invitation
  mail says "Two-factor sign-in was not carried over; set it up again after you sign in", and the client area shows a
  "Set up two-factor sign-in" banner until it is enabled or dismissed (SHOULD);
- the report counts flagged logins. Staff are not imported in phase 1 or 2a.

### 6.11 Contacts, logins and permissions

- Mohar has **contacts** (no login, email preferences) and **logins** linked to a client through `client_user` with
  `is_owner` and area permissions (`billing`, `services`, `domains`, `support`, `profile`, `contacts`). WHMCS 8.0
  replaced sub-accounts with users that can own or access several accounts, one owner per account (WHMCS docs, read
  2026-10-20), so the two models line up.
- CSV phase 1: one owner login per new client, with the client's email, when the option is on. **An import never links an
  existing login on its own (CTO rule, 2026-10-21).** If a login with that email exists: a **staff** login is never
  linked (row warning, no login); a client login makes the row "needs a decision": "Link to existing login" (allowed
  only when that login's email was verified at least 30 days ago, and behind step-up) or "Don't link" (the client is
  created without an owner login; the invitation handles it later). Apply checks the rule again.
- WHMCS 8.0+ (phase 2a): each user becomes a login (matched by email); each `clients[{id, isOwner}]` link becomes a
  `client_user` row. Permissions map as follows; anything unlisted is dropped and counted:

    | WHMCS permission                             | Mohar area |
    | -------------------------------------------- | ---------- |
    | profile                                      | profile    |
    | contacts                                     | contacts   |
    | products, manageproducts, productsso, orders | services   |
    | domains, managedomains                       | domains    |
    | invoices, quotes                             | billing    |
    | tickets                                      | support    |
    | emails, affiliates                           | (dropped)  |

    **Verify** the permission names on a real `GetUserPermissions` response (§3.2). Owners get every area, as in WHMCS.

- WHMCS before 8.0: contacts are imported as contacts; sub-account logins are not created (Q10).

### 6.12 Credit balance

A credit column in the client's currency, parsed from the decimal string into minor units without floats: plain
digits, western grouping (1,234,567) or lakh grouping (12,34,567), with an optional point and one or two decimals.
Anything else (a decimal comma such as "250,50", loose grouping such as "1,2,3") and negative values are row errors.
A credit in a currency other than the client's is a row error (§17). Billing's importer posts automatically in two
cases only (CTO decision, 2026-10-22):

- the client is created by this run: one ledger entry (`CreditReason::Import`, "Opening balance from <source>");
- a re-run, while the client's credit has not moved since its last import entry: the difference, as a new entry
  ("changed by a later import"); history is never edited.

In every other case (an existing client matched for the first time, or credit that moved since the last import) the
balance is kept, nothing is posted, and the row says "Existing credit kept; post the opening balance manually if it is
still owed." The review asks Billing (a dry run) what apply would do, so it lists the balance as a change only when it
would be posted, and a re-run that would post nothing comes out unchanged. Phase 2c, which imports
WHMCS's full credit log with invoices, reverses the opening entry and reconciles to the source balance
(`migration.md` §5.2).

### 6.13 Custom fields

There are no client custom fields in Mohar yet (parity: missing). Columns mapped to "Imported field", and WHMCS
`customfields` values, are stored in `clients.imported_fields` with their label (the CSV header, or for WHMCS the name
from the gap reader or a staff-entered label per field id on the map step). Password-type custom fields are never
imported. When client custom fields exist, a "convert to a custom field" action can move them (out of scope here; Q5).

### 6.14 Status and notes

`active`, `inactive` or `closed` (WHMCS: Active, Inactive, Closed). Closed rows are created closed with `closed_at` as the
import time and no login, or skipped (§5.3). A re-run never reopens a client closed in Mohar. `notes` (5,000) map to admin
notes.

## 7. Duplicate detection

| Check                                   | Result                                                                                                                                                                           |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Same email twice in the file            | The later row is an error naming the first ("Same email as row 88")                                                                                                              |
| Same External ID twice in the file      | Error                                                                                                                                                                            |
| External ID in the id map               | Update or unchanged (by checksum), whatever the email                                                                                                                            |
| Email matches one Mohar client          | Skip or update, by the run option                                                                                                                                                |
| Email matches several Mohar clients     | Needs a decision; the staff member picks one or skips                                                                                                                            |
| Match was merged into another client    | Follows `merged_into_client_id` to the surviving client, with a note                                                                                                             |
| Match is closed                         | Needs a decision; never updated in bulk                                                                                                                                          |
| Match changed email in the last 30 days | Needs a decision; never ticked by "Accept all proposed"                                                                                                                          |
| Company name matches (option)           | Needs a decision: create, link to the match, or skip. Names are compared case-folded, without punctuation and common legal suffixes (Pvt Ltd, Ltd, LLC, Inc, GmbH)               |
| Tax id matches (option)                 | Needs a decision, same choices                                                                                                                                                   |
| Email belongs to a contact of a client  | Warning only                                                                                                                                                                     |
| Email belongs to a client login         | Needs a decision: link the new client to that login (only when its email was verified 30 days ago or more, behind step-up) or create it without a login; never linked on its own |
| Email belongs to a staff login          | Warning; the client gets no login                                                                                                                                                |

**Better:** WHMCS's ImportAssist treats a matching email as the same client and merges the incoming currency, status,
group and items into it without asking (WHMCS docs, ImportAssist, read 2026-10-20). HostBill skips duplicate emails
silently. Mohar shows each match and changes nothing without a decision.

## 8. Idempotent re-runs

Every applied row writes `migration_id_map (source, 'client', source_id) → client_id` and the normalised checksum. A second
upload into the same source, or a second WHMCS run, finds the map row: equal checksum → unchanged (no write, `updated_at`
untouched); different → update of the mapped fields only. Without an External ID column the key is the email, and the
review warns "Re-running this import matches clients by email." A client merged after import keeps its map row pointing at
the surviving client (listener on `ClientMerged`). A client deleted in Mohar makes its map row stale; the next run reports
it and creates nothing.

## 9. Automation safety

Following `migration.md` §7: importers write quietly and dispatch no Core, Billing or Notifications domain events, so no
welcome, verification or invoice mail is sent, no `ClientRegistered` fires, and no webhook goes out per client. Only the
run events in §15 are dispatched. Invitations are a separate, explicit, throttled action (§5.7). Imported clients are
excluded from "new clients" counts on the dashboard and in reports when `imported_at` is set (SHOULD; Q9), so an import
does not look like a month of sign-ups.

## 10. Admin and client capabilities

**Admin:** upload, map, check, review, confirm, report, error download, invitations, undo, past runs per source, and the
imported fields and consent history on the client profile. The client profile shows "Imported from <source> on <date>"
with a link to the run.

**Client:** nothing changes until an invitation arrives. The invitation sets a password and verifies the address; a
one-time notice in the client area asks the client to check their details, and the 2FA banner shows when flagged.

## 11. Permissions, step-up and audit

| Permission                 | Grants                                                         | Sensitive |
| -------------------------- | -------------------------------------------------------------- | --------- |
| `migration.runs.view`      | See runs, reviews and reports (no error download)              | no        |
| `migration.runs.execute`   | Upload, map, check, confirm, download errors, send invitations | yes       |
| `migration.runs.undo`      | Undo a run                                                     | yes       |
| `migration.sources.manage` | Create sources; enter WHMCS API credentials; switch on, pause or change sign-in carry-over (§3.4) | yes       |
| `migration.secrets.import` | Carry password hashes (only if Q2 is approved)                 | yes       |

Sensitive permissions are never granted by a wildcard (security standards §2). Upload, confirm, invitations, undo and
source credentials sit behind `password.recent` (ADR 0018 §4); a login without a password cannot pass step-up, so the
response offers "Set a password".

Audit (`ActivityLogger`, fixed sentences, values in properties, nothing secret): run uploaded, mapping saved, run
confirmed (counts), run completed, invitations sent (count), run undone (counts), file deleted, payload purged; and one
entry per created or updated client ("Client created by import", with the run). For 50,000 clients the per-client entries
are written in bulk through a Core `ActivityLogger::logMany()` (one insert per chunk).

## 12. Personal data in uploads

- Encrypted at rest from the first write (§5.1); never on local disk after the request; deleted when staging ends.
- Staging rows encrypted (`encrypted:array`); the only plain derivative of an email is its keyed hash.
- No cell value in logs, exceptions, queue payloads or `Context`: jobs carry run ids and row ranges only, and are
  `ShouldBeEncrypted` anyway (ADR 0014).
- Purge on schedule (§5.8). A run can also be purged early by staff with `migration.runs.execute` (typed confirmation).
- `APP_KEY` rotation: staging written under the previous key stays readable through `APP_PREVIOUS_KEYS`.

## 13. Performance at 50,000 clients

The load test (#108) seeds 50,000 clients; the import must work at that size on the small profile.

- Streaming everywhere: the CSV is read row by row from a decrypting stream; staging inserts 500 rows per statement;
  checks and applies work on fixed chunks (1,000 and 200 rows) by `row_number` range, never by loading the run.
- One query per chunk for each lookup (emails, id map, groups, currencies, logins), never one per row.
- One password hash per chunk during apply, never one per login (§6.9).
- Each job sets `$timeout` (120 s) and `$tries`, and is sized to finish in under 60 s on the small profile, below the
  `imports` queue's `retry_after` (ADR 0017 §3).
- Targets on the small profile, one `imports` worker, PostgreSQL: staging 50,000 rows in under 2 minutes, checking in
  under 5, applying in under 15; peak memory under 192 MB per job; review page queries under a fixed budget and p95
  under 300 ms with 50,000 staged rows. The load test gets an import scenario (§19, PR 7) and the sizing guide reports the
  measured numbers.

## 14. Import page UX

Consistent with the cPanel import pages (`admin/services/servers/import`, `import-review`):

- Breadcrumb Clients → Import clients → run name; the run name is the file name and date, never an id.
- A stepper (Upload, Map, Check, Review, Done). Running steps show an alert with progress ("12,000 of 50,000 rows
  checked. This page updates as it goes."), a stalled alert after 10 minutes without progress, and a failed alert with
  the reason and the next action.
- Summary `StatCard`s, then `Tabs` with counts, a `FilterBar`, a `DataTable` with pagination (50 rows), and an
  `EmptyState` for each empty tab.
- Every action that cannot run is disabled with the reason ("Fix or skip the 12 rows that need a decision first").
  Destructive actions state the consequence and need confirmation; purge needs typed confirmation.
- Money (credit balances) uses the currency's display, with lakh grouping for INR and NPR. Dates use the company clock.
- Works at 320 px: the tabs scroll, the table collapses to cards, in light and dark mode.

## 15. Events

Emitted (all `App\Events\Migration`, ADR 0011 shape: final, `DomainEvent`, after commit, ids and scalars only):

- `MigrationRunCompleted(runId, sourceSystem, counts)`, counts keyed by entity (created, updated, unchanged, skipped,
  failed). Dispatched once, on the applying → completed transition, after commit; a stopped run never dispatches it.
- `MigrationRunUndone(runId, reverted, kept)` (new)
- `MigrationRunPaused(runId, reasonCode)` (from `migration.md`), when an import stops and waits for staff
  (`apply_failed`).

Notifications may send staff a summary from these; nothing goes to clients. No per-client domain event is dispatched.

Consumed: `App\Events\Core\ClientMerged` (re-point id map rows to the surviving client).

## 16. Integration and contract changes (ADR 0008, 0010, 0011, 0014)

The Migration module imports only kernel namespaces and `App\Modules\Core\*` (architecture rule 1). Core registers the
`client_group`, `client`, `contact` and `user` importers; Billing registers `credit_balance`.
Platform owns invitations, so Migration reaches them through a contract.

| Id  | Change                                                                                                                                                                                                                                                                                                                                                                                                                                  | Why                                 |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| C1  | Kernel `App\Contracts\Migration\{RecordImporter, ImportIdResolver}`, the final registry `App\Support\Migration\ImportRegistry` (ADR 0012, keys `client`, `client_group`, `user`), `App\Data\Migration\{ImportRecord, ImportContext, ImportOutcome, ImportIssue}`, enums `ImportAction` (open), `ImportSeverity`, with `ImportOutcome::$changes` and `ImportContext::$actorId` as trailing defaulted fields (as built in #160; ADR 0019) | First consumer; A8                  |
| C2  | `App\Support\Queues::IMPORTS = 'imports'`, in the dev worker after `default`, worked by the default pool (ADR 0014 amendment)                                                                                                                                                                                                                                                                                                           | A1; keeps 50k imports off `default` |
| C3  | `App\Contracts\Auth\ClientLoginInvitations::invite(int $clientId, int $userId, InvitationPurpose $purpose, ?int $actorId): InvitationOutcome` (Sent, Skipped, Refused, Held, Unavailable) and `problem(): ?string`. Core null default: Unavailable, "not installed"; Platform binds its invitation flow (as built in #255)                                                                                                              | Migration cannot import Platform    |
| C4  | Events `Migration\MigrationRunCompleted`, `MigrationRunUndone`, `MigrationRunPaused`, each added with the code that dispatches it (ADR 0019 shapes)                                                                                                                                                                                                                                                                                     | §15                                 |
| C5  | Core schema: `clients.imported_at`, `clients.imported_fields`, `client_marketing_consents`; `users.password_imported_at` only if Q2                                                                                                                                                                                                                                                                                                     | §4                                  |
| C6  | Core-internal: shared client validation rules (form and importer), `ActivityLogger::logMany()`                                                                                                                                                                                                                                                                                                                                          | §5.4, §11                           |
| C7  | Undo reads `ClientMergeRegistry` participant counts as a client's footprint (no API change; the architect may prefer a renamed registry)                                                                                                                                                                                                                                                                                                | §5.7                                |
| C8  | Billing-internal: `CreditReason::Import` and the `billing.credit_balance` importer                                                                                                                                                                                                                                                                                                                                                      | §6.12                               |
| C9  | Notifications template key `client.migration_invitation`                                                                                                                                                                                                                                                                                                                                                                                | Every mail has an editable template |
| C10 | `clients.email_changed_at` shared with cPanel 3b-2 (#95)                                                                                                                                                                                                                                                                                                                                                                                | §6.2                                |
| C11 | Kernel `App\Contracts\Auth\PendingPasswordCheck::check(User, string $password, Request): PendingPasswordOutcome` and `active(): bool`, enum `App\Enums\PendingPasswordOutcome` (NotApplicable, Verified, ConfirmByEmail, Rejected, Unavailable); Core null default with `bindIf`; called by the sign-in pipeline only after the local password check fails; failed sign-in time floor while `active()`; redaction keys `password2`, `passwordhash` (ADR 0018 Amendment (#555), ADR 0023) | §3.4; Fortify cannot import Migration |
| C12 | Notifications template key `client.migration_confirm_signin` (always sent)                                                                                                                                                                                                                                                                                                                                                             | §3.4 2FA path                       |

## 17. Edge cases

- The same file uploaded twice into one source: warned, and every row comes out unchanged on the second run.
- Two staff upload at once: both can map and check; only one apply runs per install at a time, and the second waits with
  "Another import is being applied".
- A client created by hand between review and apply with the same email: that row fails with the reason; the rest apply.
- A worker dies mid-chunk: the chunk is retried; applied rows are skipped by their recorded outcome.
- No worker running: the stalled alert names the queue.
- A cell starting with `=`: stored as text; escaped in the errors CSV.
- Duplicate or empty headers: the map step names them and requires a choice; a row with more cells than headers is an
  error.
- Postcodes or phone numbers mangled by a spreadsheet (lost leading zeros, scientific notation): warnings with the
  original value.
- Ambiguous dates (03/04/2024) with no date format chosen: the map step asks for the format before checking.
- A company-only row without a person's name: an error with the hint to map a contact name column.
- A currency in the file that is not enabled: an error per row, and the map step offers to map it.
- Credit given in a currency other than the client's: an error.
- A login email shared with a staff member: no login is created, and the row says so.
- A client edited in Mohar after import, then re-run: mapped non-blank columns from the file win; the report lists
  each overwritten field with both values (encrypted, purged with the run).
- A source deleted: its id map rows are kept until the source is purged, so a later run cannot duplicate.
- An undo after a client paid an invoice: that client is kept and listed with the reason.

## 18. Where we do better than the category

- **Decisions, not silent merges:** ImportAssist merges on email; HostBill skips silently. We show every match (§7).
- **One file, any size:** HostBill suggests splitting files into 300 rows; we take 50,000 in one file, queued and bounded.
- **Into a live install, repeatably:** Paymenter's importer wipes the target, and Blesta's needs a fresh install. Ours
  re-runs safely through the id map.
- **Undo instead of "restore your backup":** WHMCS and Blesta tell you to back up first. We undo what is still safe.
- **Consent with evidence:** WHMCS stores an opt-in flag; we store who said so, when, and from where.
- **No decryption, ever:** HostBill and Blesta ask for the WHMCS encryption hash to decrypt cards. We never ask for it.
- **PII handled as PII:** encrypted from upload, deleted on schedule, never in logs.

## 19. Phases and PR plan

Each PR stays under **1,500 changed lines** including tests and fixtures (the cap for money and security work,
operating model). PR 0 is a kernel PR made by the CTO session.

| PR  | Phase | Contents                                                                                                                                                                      | Criteria       |
| --- | ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| 0   | 1     | Kernel: C1, C2, C3 (Core null default), C4. Arch rules for `App\Modules\*\Import`                                                                                             | 1–3            |
| 1   | 1     | Migration module skeleton: tables, permissions, routes; upload with encryption, limits, encoding and delimiter detection; staging job; file deletion; purge task; upload page | 4–11           |
| 2   | 1     | Map and check: shared Core rules, Core client and group importers (validate only), mapping step with presets, options, check job, duplicate rules (§7)                        | 12–22          |
| 3   | 1     | Review and apply: review page, digest-bound confirm, apply chunks, id map, logins, `email_changed_at`, consent rows, imported fields, report, errors CSV                      | 23–33          |
| 4   | 1     | Billing opening balances (C8); invitations through C3 with Platform's binding, throttle and template (C9); profile panels (imported fields, consent history, "Imported from") | 34–38          |
| 5   | 1     | Undo (C7) and run history; contacts file (SHOULD)                                                                                                                             | 39–42          |
| 6   | 1     | Performance: query budgets, a 50k-row test fixture, the load-test import scenario in `loadtest/`                                                                              | 43–44          |
| 7   | 2a    | WHMCS API connector (`migration.md` §2.1 ACs 1–5), currencies and groups stages                                                                                               | 45             |
| 8   | 2a    | WHMCS clients, contacts, users and permissions, consent, verification, credit balance, delta re-runs, through the same importers                                              | 46–50          |
| 8a  | 2a.2  | Kernel (CTO): C11, the sign-in pipeline hook and its pinned test, the failure time floor, redaction keys                                                                    | 54–56          |
| 8b  | 2a.2  | Sign-in carry-over (§3.4): `migration_login_checks`, the second credential and allowlist, state machine, budgets, faults and pause, cut-off, `ValidateLogin` in the WHMCS simulator | 57–66          |
| 8c  | 2a.2  | 2FA mailbox confirmation (C12), the admin panel, the sign-in page notice, the "not carried over" invitation filter                                                           | 67–70          |
| 9   | 2a.1  | Gap reader, only if Q1/Q2 are approved                                                                                                                                        | 51–53          |
| —   | 2b    | Products and services (`migration.md` stages 7, 8, 12). Imported services carry server and username, so the cPanel import then shows them as linked                           | `migration.md` |
| —   | 2c    | Invoices with balances, transactions, credit log and reconciliation (`migration.md` stage 15)                                                                                 | `migration.md` |
| —   | 2d    | Domains (stage 13); 2e: tickets (stage 17)                                                                                                                                    | `migration.md` |

Dependencies: PR 3 needs #112 (nullable `users.password`, `password.recent` in the kernel) and the
`email_changed_at` column from #95 or its own copy (C10). Invitations (PR 4) refuse to start while the account-link host
check fails (`AccountLinks::problem()`), with that reason shown on the button. PR 1 to 3 can be reviewed in sequence
while PR 4 is built. PR 8b needs PR 8 (users imported with their WHMCS ids) and PR 8a; PR 8c needs 8b. None of 8a–8c
needs ADR 0018's sign-in-method kernel (social sign-in PR 0), which is not built yet. Phase 2b and later each get a short delivery section in
`migration.md` when they start; their acceptance criteria are already there (§13).

## 20. Acceptance criteria

Pest feature tests unless noted; `Storage::fake('objects')`, `Queue::fake()` where relevant, and every criterion runs on
SQLite and PostgreSQL.

**Kernel (PR 0)**

1. MUST: `ImportRegistry::ordered()` returns `client_group` before `client` before `contact` and `user`, and throws
   on a cycle or an unknown dependency in the chains asked for.
2. MUST: an arch test fails when a class in `App\Modules\*\Import` uses the `Event`, `Mail` or `Notification` facades,
   an `App\Events` namespace other than `Migration`, or a driver contract.
3. MUST: with Platform absent, `ClientLoginInvitations::invite()` returns Unavailable and sends nothing.

**Upload and staging (PR 1)**

4. MUST: staff without `migration.runs.execute` get 403 on upload, confirm and error download; without a recent password
   confirmation the upload returns the step-up response.
5. MUST: a 21 MB file, or a file with 100,001 rows, is refused with the limit in the message, and nothing is stored.
6. MUST: a UTF-16 file is refused with the "CSV UTF-8" hint; a UTF-8 file with a BOM and semicolons is staged with the
   right headers.
7. MUST: the object stored on the `objects` disk does not contain any cell value in plain text (the test greps the stored
   bytes for a known email).
8. MUST: after staging, the file is gone from the `objects` disk, `file_key` is null, and the row count equals the file's
   data rows.
9. MUST: a column headed "Password" (or "Card number") is forced to Ignore, and no staging row contains its values.
10. MUST: no log record, failed-job payload or exception message written during staging and checking contains a cell
    value from the file.
11. MUST: an unapplied run is purged 7 days after upload: `raw` and `normalized` are null, counts remain.

**Map and check (PR 2)**

12. MUST: the importer and the admin client form reject the same values (a shared dataset of invalid rows runs against
    both).
13. MUST: a mapping without email is refused; with the WHMCS preset, likely headers are suggested and can be changed.
14. MUST: two rows with the same email (different case) make the later one an error naming the earlier row.
15. MUST: a row whose email matches one existing client is "skip" by default and "update" when the option is set, and the
    review lists the fields an update changes.
16. MUST: a match whose email changed 10 days ago is "needs a decision" and is not ticked by "Accept all proposed"; the
    toast states how many were left.
17. MUST: a match that was merged resolves to the surviving client; a closed match needs a decision.
18. SHOULD: with the company option on, "Acme Pvt. Ltd." matches an existing "ACME Pvt Ltd" and needs a decision.
19. MUST: an Indian row with a GSTIN for Karnataka and an address in Kerala gets the place-of-supply warning; an invalid
    GSTIN gets the invalid warning; neither blocks the row.
20. MUST: a currency that is not enabled is an error; a blank currency becomes the default currency.
21. MUST: an unknown group name blocks the check until staff map it, create it, or choose no group.
22. MUST: checking 1,000 rows issues a fixed number of queries independent of the row count (query-count test).

**Review and apply (PR 3)**

23. MUST: confirm with a digest that no longer matches (a row decision changed after the review) is refused with "Review
    the import again".
24. MUST: apply creates clients with `imported_at` set, owner logins with no usable password (§6.9), and no `ClientRegistered`, no
    mail and no notification (`Event::fake`, `Mail::fake`, `Notification::fake`).
25. MUST: running the same file again into the same source produces only "unchanged" outcomes, and no client's
    `updated_at` changes.
26. MUST: with an External ID column, a second run whose row has a new email keeps the client's and the login's email
    and says so; the other mapped fields update (CTO rule, §6.2).
27. MUST: a client created by hand with a row's email after the review makes that row fail with its reason; the other
    rows apply.
28. MUST: a chunk that throws after half its rows is retried and creates no duplicate client, login or id-map row.
29. MUST: an email belonging to a staff login creates the client and no login, with the row note.
30. MUST: a yes consent cell without a date writes a `granted` row whose evidence says the date was unknown; a blank cell
    writes nothing; an existing `withdrawn` client is not changed to `granted` without a per-row decision.
31. MUST: mapped "Imported field" columns appear on the client profile under Imported fields with their header as label.
32. MUST: the errors CSV prefixes a cell starting with `=` with an apostrophe and is not stored on any disk.
33. MUST: the review and report pages show row numbers, names and document-style labels, never a database id
    (`assertInertia` on the props).

**Credit, invitations, profile (PR 4)**

34. MUST: a credit cell of "1,250.50" for an INR client posts one `import` ledger entry of 125050 minor units; a second
    run with "1,300.00" posts 4950 more; "-5" is an error.
35. MUST: "Send invitations" needs step-up, sends at most `migration.invite_per_hour` invitations per hour, skips closed
    clients and logins that have a password, and uses the `client.migration_invitation` template.
36. MUST: accepting an imported login's invitation sets the password and verifies the email.
37. MUST: with "Trust the verified column" off, a yes in that column leaves the login unverified; with it on, the login
    is verified and the audit entry says so.
38. SHOULD: the dashboard's new-clients count for the import month excludes imported clients.

**Undo and history (PR 5)**

39. MUST: undo within the window deletes created clients with no other activity, restores updated fields that still hold
    the imported value, reverses opening balances, and writes `import_undo` consent rows; `MigrationRunUndone` carries the
    counts.
40. MUST: a created client that has an invoice, or whose login accepted an invitation, is kept by undo and listed with the
    reason.
41. MUST: after the window, undo is disabled with the date it ended, and `before` values are purged.
42. SHOULD: a contacts file links rows to clients by External ID or client email and maps the five email preferences.

**Performance (PR 6)**

43. MUST: a 50,000-row file stages, checks and applies with each job under 192 MB peak memory (measured in the test
    with a memory probe) and no job over its timeout.
44. SHOULD: the load test's import scenario reports staging, check and apply times on the small profile, and the sizing
    guide records them.

**WHMCS API (PR 7–8)**

45. MUST: `migration.md` §13 criteria 1–5 pass for the connector, and `GetContacts` is paged by `userid`.
46. MUST: a WHMCS client with `email_verified` true, `marketing_emails_opt_in` true, `taxexempt` true, `credit` "15.00"
    and two custom field values imports as a verified owner login, a `granted` consent row with WHMCS evidence, a
    tax-exempt client, a 1500 opening balance and two imported fields.
47. MUST: a WHMCS user linked to two clients, owner of one, becomes one login with an owner link and a non-owner link
    whose areas follow the §6.11 table; `emails` and `affiliates` are counted as dropped.
48. MUST: a second WHMCS run with unchanged fixtures produces only unchanged outcomes; a changed phone updates only that
    client.
49. MUST: a client imported first from CSV and then from WHMCS (same email, different sources) is matched by email and
    gets one id-map row per source, not a second client.
50. MUST: no WHMCS run stores or logs the API secret, and no action outside the allowlist is ever sent.

**Gap reader (PR 9, only if approved)**

51. MUST: a `$2y$10$` hash is stored and verifies the right password; a legacy MD5-with-salt value is refused and that
    login gets an invitation.
52. MUST: a login flagged with 2FA at the source never receives a carried hash.
53. MUST: the reader's query log contains only the allowlisted tables and columns.

**Sign-in carry-over (PR 8a–8c, §3.4; WHMCS simulator per ADR 0016, no real accounts)**

54. MUST: with `MODULES=Core` (Migration absent), the null `PendingPasswordCheck` answers NotApplicable, and password
    sign-in, the lockout and the 2FA challenge behave exactly as before (the existing sign-in tests pass unchanged).
55. MUST: a pipeline test pins the hook's position: it is called only after the local hash fails, never for step-up
    (`confirmPasswordsUsing`), and a Verified outcome reaches the same two-factor and session steps as a local match.
56. MUST: while `active()` is true, a failed password sign-in for an unknown email and one for an imported login whose
    remote check takes 300 ms both answer in no less than `min_failure_ms`, with the same message and status.
57. MUST: an imported login whose WHMCS password is correct signs in on its first attempt. The simulator records one
    `ValidateLogin` call. `users.password` then verifies the typed password locally, the row is `migrated`, the
    activity entry is written, and the next sign-in makes no remote call.
58. MUST: a wrong password gives the ordinary failure and counts towards the `login` limiter. After 5 remote checks in
    an hour for that login, a sixth attempt makes no call and gives the same failure.
59. MUST: a success whose `userid` differs from the id recorded at import does not sign in, does not store a hash, and
    counts as a fault.
60. MUST: an unknown email, a login without a row, a login whose email changed since import, and a date after the
    cut-off (company clock; 23:59:59 accepted, 00:00:00 the next day refused) each make no remote call.
61. MUST: a base URL on `http://`, a host equal to the `APP_URL` host, a 3xx answer, or an address the outbound policy
    refuses (ADR 0015) makes no call, or a refused one, and Test shows the reason.
62. MUST: a timeout (the simulator delays past 5 s), a 5xx, a non-JSON answer and an "unknown action" answer each fail
    the sign-in with the ordinary message. Ten faults in ten minutes, or one "unknown action", pause the source and
    alert staff.
63. MUST: with 4 checks already running for a source, a fifth sign-in makes no call and answers "try again in a
    moment".
64. MUST: two parallel sign-ins with the right password produce one stored hash and one `migrated` transition. Both
    sign in or the second re-checks the local hash.
65. MUST: after a run of sign-ins (success, failure, 2FA, fault), neither the typed password nor the returned
    `passwordhash` appears in the log files, failed-job payloads, activity rows, the `migration_login_checks` table or
    the cache store (grep).
66. MUST: a WHMCS user linked to three clients carries over once and keeps its three links and their areas.
67. MUST: a success with `twoFactorEnabled` true does not sign in, and mails `client.migration_confirm_signin`. Opening
    the link in the same browser within 30 minutes stores the hash, verifies the email, sets the "had 2FA" flag and
    signs in (the 2FA banner shows). Opening it in another browser, after 30 minutes, or twice is refused, and the held
    hash is deleted at expiry. A fourth request in an hour sends no mail.
68. MUST: a carry-over without the 2FA path leaves `email_verified_at` as imported.
69. MUST: the admin panel shows eligible, carried-over, confirmed, waiting and expired counts that match the table.
    Switching on, pausing and changing the cut-off need `migration.sources.manage` and step-up. The cut-off cannot be
    set beyond 180 days from switching on. "Invite everyone not carried over" queues invitations only for open or
    expired rows.
70. SHOULD: a carried-over password shorter than Mohar's minimum signs in and is then sent to choose a new one before
    any client-area page; the sign-in page shows the carry-over notice with the cut-off date while the window is open.

Count: 70 criteria (65 MUST, 5 SHOULD).

## 21. Owner decisions (2026-10-20: all recommendations accepted)

The questions below were answered by accepting each recommendation as written.

1. **Database read (Q1).** Keep API-only for WHMCS (the 2026-10-17 decision), or approve the narrow gap reader in §3.3?
   Recommendation: API-only in v1; revisit after the first real migration.
2. **Passwords (Q2).** Everyone sets a new password from an invitation, or carry bcrypt hashes through the gap reader
   (§6.9)? Recommendation: invitations for everyone in v1.
3. **Default for an email match (Q3).** Skip or update? Recommendation: skip, with update one option away.
4. **Undo window (Q4).** 7 days, and is undo required for phase 1? Recommendation: yes, 7 days.
5. **Client custom fields (Q5).** Build client custom fields (a WHMCS parity gap) before or with the importer, or keep
   imported fields until later? Recommendation: imported fields now; custom fields as their own spec.
6. **Consent without a date (Q6).** For WHMCS, record "granted" with a note (keeps the host's current practice), or
   "unknown" until the client confirms? Recommendation: granted with a note; for CSV, blank stays unknown.
7. **Closed clients (Q7).** Import them as closed without logins (keeps history for invoices later), or skip?
   Recommendation: import.
8. **Invitation volume (Q8).** 100 an hour means 50,000 invitations take about three weeks. Acceptable, or should the
   default be higher (depends on the host's mail provider)? Recommendation: keep 100 (owner decision 2026-10-22: cPanel and Google Workspace caps), send in waves by group.
9. **Dashboard (Q9).** Exclude imported clients from "new clients"? Recommendation: yes.
10. **WHMCS before 8.0 (Q10).** Support it with contacts only and no logins, or require 8.0+? Recommendation: 8.0+ for
    logins, contacts-only below.
11. **Sample data (Q11).** Can the owner provide a WHMCS "Clients" export and two API responses (`GetUserPermissions`,
    `GetUsers`) from a test install with fake data? They settle the **verify** items in §3.1 and §3.2.
12. **Limits (Q12).** 20 MB and 100,000 rows per file: enough?
13. **Sign-in carry-over (Q13, open, 2026-10-31).** Approve §3.4 as the default for WHMCS 8.0+ sources (90-day default
    window, 180 at most), with invitations only for the logins not carried over by the cut-off?
    Sub-questions:
    - (a) Is forwarding a returning client's typed password once, over TLS, to the host's own WHMCS acceptable, and
      should the wizard tell hosts to mention it in their privacy notice?
    - (b) Is a mailbox confirmation an acceptable second proof for accounts that had 2FA at WHMCS, or should they
      always use the invitation?
    - (c) Any concern with calling one API action that updates the last-login time at the source?
    - (d) Offer an optional TLS key pin for the old host?

    Recommendation: yes to the default and (a); a mailbox confirmation for (b); no pin in v1 for (d). Not legal
    advice: the licence side of (c) is a question for the owner.

## 22. Out of scope (v1)

Staff import; products, services, invoices, transactions, domains and tickets (phases 2b–2e, specified in `migration.md`);
client custom fields as a feature; marketing campaigns that use the consent records; importing from Blesta, HostBill or
other systems by API (the CSV path covers their exports, and the kernel contracts allow connectors later); two-way sync;
carrying 2FA, security questions, stored cards or bank accounts; Excel (`.xlsx`) files (save as CSV).

## 23. Sources (all read 2026-10-20 unless noted)

- WHMCS API reference: GetClientsDetails https://developers.whmcs.com/api-reference/getclientsdetails/ · GetClients
  https://developers.whmcs.com/api-reference/getclients/ · GetContacts https://developers.whmcs.com/api-reference/getcontacts/
  · GetUsers https://developers.whmcs.com/api-reference/getusers/ · GetUserPermissions
  https://developers.whmcs.com/api-reference/getuserpermissions/ · AddClient https://developers.whmcs.com/api-reference/addclient/
  · GetClientPassword https://developers.whmcs.com/api-reference/getclientpassword/ · GetCredits
  https://developers.whmcs.com/api-reference/getcredits/ · GetClientGroups https://developers.whmcs.com/api-reference/getclientgroups/
  · the other actions as listed in `migration.md` §15 (read 2026-10-17).
- WHMCS docs 8.13 (read with headless Playwright; the `whmcsdocs.devops.cpanel.net` mirror had an expired certificate):
  Users and Client Accounts https://whmcsdocs.devops.cpanel.net/8-13/clients/users-and-client-accounts · Users tab
  https://whmcsdocs.devops.cpanel.net/8-13/clients/client-profile/users-tab · Managing Users
  https://whmcsdocs.devops.cpanel.net/8-13/clients/managing-users · Two-Factor Authentication
  https://whmcsdocs.devops.cpanel.net/8-13/system/authentication/two-factor-authentication · General Settings, Other tab
  (marketing opt-in) https://whmcsdocs.devops.cpanel.net/8-13/system/general-settings/general-settings-other · Migrate Data
  to WHMCS https://docs.whmcs.com/8-13/getting-started/migrate-data-to-whmcs/ · ImportAssist
  https://docs.whmcs.com/8-13/addon-modules/importassist/ · Client Email Verification
  https://docs.whmcs.com/8-13/clients/client-email-verification/ · Custom Fields
  https://docs.whmcs.com/8-13/clients/the-client-area/custom-fields/ · Client Groups https://docs.whmcs.com/8-13/clients/client-groups/
  · Export Client Data https://docs.whmcs.com/8-13/clients/client-management-tutorials/export-client-data/ · Reports
  (Exports, CSV) https://docs.whmcs.com/8-13/system/reports/ · Require Opting-In to Emails
  https://docs.whmcs.com/8-13/clients/client-management-tutorials/require-opting-in-to-emails/ · Add a Client
  https://docs.whmcs.com/8-13/clients/client-management-tutorials/add-a-client/ · Configure GST for India
  https://docs.whmcs.com/8-13/payments/tax-tutorials/configure-gst-for-india/
- Sign-in carry-over (§3.4, read 2026-10-31): ValidateLogin https://developers.whmcs.com/api-reference/validatelogin/
  · OpenID Connect https://docs.whmcs.com/9-1/system/authentication/openid-connect/ · OpenID Connect Development
  https://docs.whmcs.com/9-1/system/authentication/openid-connect-development/ · Security settings
  https://docs.whmcs.com/9-1/system/general-settings/general-settings-security/ · the full list is in
  `docs/research/2026-10-31-whmcs-login-migration.md` §9.
- WHMCS community (secondary, for the bcrypt change in 6.3): https://www.ndchost.com/wiki/software/whmcs/client-password-hash ·
  https://whmcs.community/topic/255397-passwords-25-characters-after-upgrade-to-630/ · marketing opt-in fields:
  https://whmcs.community/topic/288661-query-clients-that-dont-have-marketing-opt-in-and-opt-out-confirmed
- Other products: Blesta Import Manager https://docs.blesta.com/integrations/plugins/import-manager · HostBill, Importing
  from WHMCS https://hostbill.atlassian.net/wiki/x/BgCYng · HostBill, Adding new clients (CSV)
  https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/408125609/Adding+new+clients · Paymenter WHMCS importer
  https://paymenter.org/docs/guides/whmcs-importer · Upmind, Importing CSV data
  https://docs.upmind.com/setup-and-configuration/importing-data/importing-csv-data.md · ClientExec CSV import
  https://docs.clientexec.com/en/article/how-to-importexport-clients-data-from-a-csv-file-1enqy21 · FOSSBilling forum (no
  importer) https://forum.fossbilling.org/archive/index.php/thread-329.html
- Our code: `app/Modules/Core` (clients, contacts, `client_user`, invitations), `app/Modules/Platform/Actions/Clients`,
  `app/Modules/Billing/Services/Tax`, `app/Support/Clients/ClientMergeRegistry.php`, and the cPanel import on
  `feature/provisioning-cpanel-import-choices` (#95: `ServerImportController`, `ImportServerAccounts`, `email_changed_at`).
