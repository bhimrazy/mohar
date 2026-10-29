# Unpaid invoice and domain import — Product Spec (v1, phase 3)

Status: draft for engineering · Owner: Migration workstream (`app/Modules/Migration`) with the importers living in
Billing and Domains · Written 2026-10-29 · Open owner questions are in §16; nothing here is built.
Clean-room: behaviour comes from the public WHMCS API reference (read 2026-10-29), the docs already read for
`client-import.md` and `migration.md`, and our own specs and code. No WHMCS source, encoded files or licence internals
were used. WHMCS parameter and field names are quoted only where a connector must send or parse them; all wording is ours.

This spec **extends** `client-import.md` (phase 1 CSV clients, 2a API clients) and `migration.md` (§5.1 money and dates,
§5.2 mapping, §6 idempotency, §7 automation safety), and uses the importer contracts of ADR 0019. It is the "phase 3"
that the CEO review of 2026-10-29 ranks as the biggest gap between "built" and "switched": a host's clients and cPanel
accounts arrive, but the money they owe and the domains they hold do not.

## 1. Purpose and scope

A host leaving WHMCS must be able to bill its clients from Mohar on day one. That needs three things the earlier phases
do not bring: the **open invoices with what has already been paid on them**, the **domains** with the registrar record
that lets Mohar renew them, and the guarantee that none of this sends a mail, calls a provider or distorts the VAT
register.

Two sources, one pipeline, in this order:

1. **CSV (phases 3a and 3b, MUST).** Any host, from any system. For WHMCS hosts the files come from the admin's
   Reports → Exports group (invoices, transactions, domains; `client-import.md` §3.1) or from a spreadsheet the host
   prepares.
2. **WHMCS External API (phase 3c, SHOULD).** `GetInvoices`, `GetInvoice`, `GetClientsDomains`, through the connector of
   `client-import.md` phase 2a and the same importers, so a CSV first load can be topped up by an API run.

**In scope:**

- open invoices: Unpaid, Payment Pending, Collections and (optionally) Draft, with line items when available, tax as
  recorded, credit already applied, and the payments made so far (transactions);
- domains of any status, with registrar, order id and customer id claims, dates, flags and term;
- domains the client registered themselves (`.np` and any name held outside a registrar we drive) as records that Mohar
  never renews or syncs;
- review, apply, report and undo as in `client-import.md`.

**Not in scope** (§17): paid, cancelled and refunded invoice history, credit and debit notes, quotes, services (phase 2b),
tickets, stored pay methods, domain contacts and nameservers, EPP codes, anything that needs a call to a registrar or
gateway.

## 2. Boundaries with the existing specs

| Topic                                          | Decision here                                                                                                                                                        |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `migration.md` §5.2 says invoices of every status are imported, with a reconciliation | Narrowed: **open** invoices only (§4). Paid history is a separate decision (Q1) because a paid numbered invoice is a tax document |
| `migration.md` §5.2 says imported invoices are updatable before cutover as an exception to immutability | Kept for open invoices that are not yet numbered. A numbered invoice is never rewritten (§6.3)                                 |
| `migration.md` §6 delta and cutover               | Applies to the API source. A CSV run has no cutover: its baselines are applied when the run is applied (§9)                                                       |
| `client-import.md` §6.4 currency                   | An invoice's currency must equal its client's. A mismatch is a row error, never a conversion (§6.4)                                                                |
| `client-import.md` §6.12 opening credit           | The client's imported credit balance is already net of credit applied to open invoices; this import never posts to the ledger (§6.5)                              |
| ADR 0019 capabilities                              | `PreparesChunk` and `MatchesExisting` move from direction to built here, their first consumers (§14)                                                              |

## 3. Where the data comes from

### 3.1 CSV files (3a, 3b — MUST)

Three entity files, each uploaded as its own run (the run's `entity` is `invoices`, `invoice_items`, `payments` or
`domains`), through the same upload, map, check, review, confirm and apply flow as clients (`client-import.md` §5). The
WHMCS "Reports → Exports" group offers invoices, transactions and domains exports; their exact headers are not in the
public docs, so the presets are suggestions to be verified against a sample (Q8).

- **Invoices file** (one row per invoice): source invoice id, invoice number, client key, status, issue date, due date,
  currency, subtotal, tax, second tax, tax rates, credit applied, total, balance (optional, used to cross-check), payment
  method, notes.
- **Invoice items file** (optional): invoice reference, description, amount, taxed (yes/no), item type, domain name or
  service reference (optional), period start and end (optional). Without it, every invoice gets one synthesised line
  ("Balance brought forward from <source> invoice <number>"), taxable when the invoice carries tax, and the review says so.
  The invoice-level amounts stay authoritative either way.
- **Payments file** (optional): invoice reference, date, amount in, amount out, gateway, transaction id, fees, description.
  Rows for invoices that are not in an open state at import time are ignored and counted.
- **Domains file**: domain name, client key, status, registrar, registration type, registration date, expiry date, next due
  date, term in years, first payment, recurring amount, payment method, flags (ID protection, DNS management, email
  forwarding, do not renew), registrar order id (optional), registrar customer id (optional), notes.

**Client key**, in order: the external client id mapped in phase 1 (resolved through the id map of the same source or any
source of this install), the client's email (exact, via `email_hash`), the Mohar client number. A row whose client cannot be
found is an error with "Import this client first"; invoices and domains never create clients.

Mapping presets, delimiter and encoding detection, limits, encrypted staging, errors CSV and CSV-injection guard are
exactly those of `client-import.md` §5.1 to §5.5 and §12. Staged rows hold invoice and domain data, not card data; the
file still counts as personal data (client names, tax ids).

### 3.2 The WHMCS External API (3c — SHOULD)

Read-only actions only (`migration.md` §2.1). Facts from the API reference, read 2026-10-29:

- `GetInvoices` is paged (`limitstart`, `limitnum`, default 25), filterable by `userid` and `status` (including Overdue),
  and sortable. Each row has the id, `invoicenum`, the client, dates (`date`, `duedate`, `datepaid`, `date_refunded`,
  `date_cancelled`), `subtotal`, `credit`, `tax`, `tax2`, `total`, `taxrate`, `taxrate2`, `status`, `paymentmethod`,
  `notes`, and the currency code. It does not list items.
- `GetInvoice` returns one invoice with `items`, `transactions`, `balance`, `credit`, the tax fields and the same header
  data. The connector calls it only for invoices the list returned as open, and (delta) only when the list row changed.
- `GetTransactions` filters by `invoiceid` or `clientid`; its fields are `gateway`, `date`, `description`, `amountin`,
  `amountout`, `fees`, `rate`, `transid`, `invoiceid`, `refundid`. `GetInvoice.transactions` makes a separate call
  unnecessary for open invoices.
- `GetClientsDomains` is paged and filterable by `clientid`, `domainid` or `domain`. Per domain: `id`, `userid`, `orderid`
  (the WHMCS order, not a registrar order), `domainname`, `registrar`, `regtype`, `regperiod`, `firstpaymentamount`,
  `recurringamount`, `promoid`, `paymentmethod`, `regdate`, `expirydate`, `nextduedate`, `status`, `subscriptionid`,
  `dnsmanagement`, `emailforwarding`, `idprotection`, `donotrenew`, `notes`. It carries **no nameservers**, no registrar
  order id and no registrar customer id.

Consequences: (a) the registrar order id and customer id cannot come from this call, so the API path gets them from a
**supplementary CSV** (§7.3) or leaves them empty for staff to link; (b) nameservers would need `DomainGetNameservers`,
which makes WHMCS call the registrar for us, so the connector does not request it (Mohar reads them at its first sync);
(c) amounts arrive as decimal strings and are parsed without floats (`migration.md` §5.1).

**Verify before building (Q8):** whether any WHMCS 8.x or 9 install keeps the registrar's order id in a field the API
exposes, how the ResellerClub module names its customer reference, and the exact export headers. None is documented
publicly; this spec assumes they are not available.

## 4. Which invoices are imported

| Source status                         | Result                                                                                                                                                                  |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unpaid                                | `unpaid` (overdue is derived from the due date, never stored)                                                                                                           |
| Payment Pending                       | `unpaid`, with a warning and an admin note "Payment was pending at <source> on <date>; check before collecting." The row needs a decision: import, or skip (default skip) |
| Collections                           | `collections`                                                                                                                                                           |
| Draft                                 | Off by default (option "Include drafts"); when on, `draft` with no number; the source number is kept in `source_reference`                                                   |
| Paid, Cancelled, Refunded             | Not imported. Counted per status in the review ("1,204 paid invoices were not imported")                                                                                |
| Unknown or custom status              | Row error until mapped on the `status.invoice` value map                                                                                                                |

The import never asks Mohar to calculate anything: lines, tax and totals are taken as recorded; `Invoicer::issue` is
not used (`migration.md` §5.2). Every imported invoice stores `source_reference` (the number or id it had at the source),
the run id and `imported_at`.

## 5. Entities and key fields

Migration module tables from `client-import.md` §4 are reused (`migration_runs` gains the entities above;
`migration_records`, `migration_id_map` with entities `invoice`, `invoice_item`, `payment`, `domain`; `migration_issues`).
What the owning modules add:

**Billing** (`invoices`, additive and nullable): `imported_run_id`, `imported_at`, `source_reference` (string, 64). Source
payments become ordinary `transactions` rows with `imported_run_id` and `type = payment` (or `refund` when `amountout >
0`, linked by `refundid`), `gateway_txn_id` = the source transaction id or `import:<source id>` when empty (unique per
gateway as today), `occurred_at` as the source date. Credit applied becomes one `invoice_adjustments` row
(`reason = credit_applied`) flagged as imported, with **no credit ledger post** (§6.5). A reconciliation difference is one
`manual` adjustment named "Migration reconciliation" (§6.6).

**Domains** (`domains`, additive): `imported_run_id`, `imported_at`, `origin` (`registered_here` default, `imported_registrar`,
`client_registered`), and in `registrar_meta` an `import_claim` object `{order_id, customer_id, claimed_at}` that is
**not** the linked order (§7.2). Nameservers and `epp_code` stay empty. `expiry_date` is nullable for `client_registered`.

No new tables outside Migration's. All columns are nullable or defaulted, so the PRs need no data backfill.

## 6. Invoice rules

### 6.1 Identity and idempotency

The id map key is the source invoice id (`invoice:<source id>`), or, for CSV without an id column, the invoice number
within the source; the review states that numbers are the key in that case. Equal checksum: unchanged. Different
checksum on an invoice that is still open and unnumbered: update of the changed fields (the review lists them). A
different checksum on an invoice that is now paid, partly paid in Mohar, numbered, or has a note: **never overwritten**;
the row is skipped with the reason. An invoice paid or cancelled at the source in a later file/run is **reported** ("Paid
at <source> since import") and left alone; staff decide in Mohar. Records that disappear from the source are reported,
never deleted.

### 6.2 Client, currency and money

- The invoice currency (or, when blank, the client's) must equal the client's currency; other currencies are an error
  (`ClientCurrencyUsage` is why a client's currency cannot be changed afterwards). The currency must be enabled in Mohar.
- Amounts are decimal strings parsed to minor units by the ISO-4217 decimals of the currency, half-up, no floats;
  lakh grouping is accepted as in `client-import.md` §6.12. NPR has 2 decimals.
- `total` must equal `subtotal + tax + tax2` (after the discount lines if any) within one minor unit; otherwise the row is an
  error "The invoice does not add up", because the import never recalculates. Items must sum to the subtotal the same way;
  if they do not, the synthesised single line is offered instead.
- Tax: the rate columns and amounts are kept. The operator maps each distinct source tax (name and rate) to a Mohar tax
  rule on the "Mappings required" step; the invoice stores that rule's name, flags and compound/inclusive settings, so
  that the Nepal VAT split (ADR 0024, Amendment #432) classifies it when the invoice is paid. A tax amount with no mapped
  rule is a blocking issue.

### 6.3 Numbers and VAT mode (ADR 0024, `vat-register.md`)

An invoice with a legal `number` is a fiscal document and is immutable. Imported open invoices therefore follow the
install's numbering mode, read when the run is applied:

| Install state                                                              | What the import writes                                                                                                                                                                 |
| -------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Proforma mode on (`sequential_paid_numbering`) — always so with Nepal VAT mode enabled or scheduled | `proforma_number` = the source number, `number` = null, `numbered_at` = null, **issue date kept as at the source**. The invoice becomes a tax invoice only when Mohar settles it: next gap-free legal number, issue date set to the payment date, fiscal snapshot taken then (ADR 0024 §2) |
| Proforma mode off, Nepal VAT mode never enabled | Default: the source number goes to `proforma_number` and Mohar assigns a new legal number from its own sequence at import. Option "Keep source numbers as legal numbers" (default off): the source number becomes `number`; it must be a valid format and not collide with an existing number, and the sequence moves past the highest imported numeric number afterwards (`migration.md` §5.2). The fiscal snapshot is left to the `billing:fiscal-snapshot` backfill, and the review warns that enabling Nepal VAT mode later is refused until that backfill has run |

Rules:

- With Nepal VAT mode enabled, the "keep numbers" option is not offered. The import cannot create a fiscal document: no
  row it writes appears in the VAT register, and `fiscal_*` columns are null. **MUST**
- The WHMCS number is never reused as a Mohar legal number in proforma mode; two systems cannot both hold the right to
  issue number N in the same sequence. A paid invoice therefore carries both: the Mohar legal number and, in
  `source_reference`, the old reference. **MUST**
- A partial payment imported onto a proforma does not number it. It numbers only when the balance reaches zero in Mohar. The
  tax point in the register is that final payment date; whether the host and its accountant accept this for money already
  received under the old system is Q3 and Q4.
- Enabling the Nepal VAT mode is not blocked by an import that used the default options. **MUST**
- The line `tax_amount` per item stays null; the invoice-level tax is authoritative (`migration.md` §5.2).
- `client_snapshot` is built from the client at import time (the API has no historic address). The `seller_snapshot` is the
  seller at import time. Because both are rebuilt when a proforma is numbered, the document the client finally receives
  shows the seller details of that day.

### 6.4 Balance and payments

Balance in Mohar is computed as always: `total − credit applied − Σ payments in + Σ payments out`. The importer cross-checks it with the
source `balance` when the file has one (always, for the API):

- equal: nothing to do;
- differs: the row needs a decision. "Import as recorded" adds one `manual` adjustment, "Migration reconciliation", for
  the difference, so Mohar's balance equals the source's; "Skip" leaves the invoice out. The default is skip, because a
  difference usually means the file is stale or the payments file is incomplete. **MUST**
- payments exceeding the total, or a balance below zero: an error, never an automatic credit.

Imported payments are written without gateway calls, without `PaymentReceived` and without receipts (§9). A source
`amountout` is a refund line; a payment with `amountin = 0` and `amountout = 0` is dropped with a note. The source
`fees` are stored as the transaction fee and do not change the invoice.

### 6.5 Credit

`credit` on the invoice (credit the client applied at the source) is stored as an imported `credit_applied` adjustment
and reduces the balance. The client's credit ledger is not touched: the opening balance imported in phase 1 (`client-import.md` §6.12) or by the API already reflects credit used
at the source, so posting again would debit twice. If the client's credit has not been imported, the review warns "Credit
applied to this invoice at the source has no matching balance in Mohar". Removing that credit later in Mohar works as for any invoice.

### 6.6 Dates and the clock

Date-only fields are calendar dates with no zone shift; datetimes (`datepaid`, transaction dates) are read in the
source timezone the operator confirms and stored in UTC (`migration.md` §5.1; ADR 0013). `issue_date` is kept as recorded in
all modes (§6.3). A due date earlier than the issue date is a warning, not an error.

### 6.7 Links to domains and services

An item row that names a domain (or a service already imported) links `billable_type` and `billable_id` through the id map. When it names a domain
that is not in Mohar (yet), the item is kept as a plain line and the review lists it. For links that resolve, the item gets
`period_start` = the invoice due date for renewal lines, which is what the renewal guards read, so the next renewal run
does not invoice the same period twice (§9).

## 7. Domain rules

### 7.1 Identity, TLD and status

- `name` is lowercased and punycoded; `sld` and `tld` come from the longest matching configured `tlds.extension`. A name
  whose TLD is not configured needs a decision: add the TLD first, or "create a retired TLD record" (no registration
  or transfer terms, `allow_register=false`), the stub rule of `migration.md` §5.4 (Q7).
- Domains are matched by name through the `MatchesExisting` capability, not only by id map: a name that already has a
  non-terminal row in Mohar is an update of that row, or skip (default skip), never a second row (the unique-active-name
  rule of `domains.md`). Two rows in one file for the same active name: both flagged, none imported.
- Status uses the map in `migration.md` §5.3. Dates `regdate`, `expirydate`, `nextduedate` are calendar dates.
  `next_invoice_date` is `next_due_date` unless an open imported invoice has a line for this domain with that due date, in
  which case it is advanced one term (§9).
- `period_years` from the term, `auto_renew = !donotrenew`, flags as given, `currency` = the client's currency.
- `recurring_amount` and `first_payment_amount` keep the source figures (the client's agreed price) with a flag
  "imported price"; the review shows where they differ from Mohar's current TLD price, and a later bulk action reprices on demand (Q6).
- No contacts, nameservers, EPP codes or `registrar_lock` are imported; they stay empty/unknown until the first registrar
  sync after go-live.

### 7.2 Registrar records (ResellerClub and others) — #338, #372

Background: since #335 a ResellerClub domain is "ours" only through an order id Mohar recorded itself, and the **Link
order** action verifies that the order belongs to the host's reseller, matches the name exactly, still holds the name,
and belongs to the default customer or, in per-client mode, to the client's recorded customer. Imported clients have no
recorded customer, so per-client domains could not be linked (#372).

Design: **the import records claims; verification promotes them.**

1. The domains file/API row names a registrar. The operator maps each distinct source registrar to a Mohar `registrar_key`
   or to "none (managed by hand)". Unmapped registrars block the run.
2. If the row has a registrar order id (and optionally a customer id), the importer stores them in
   `registrar_meta.import_claim` and leaves the domain **unlinked**. The importer makes no call to the registrar (§9), so
   it cannot verify, and an unverified id must not drive a renewal or an EPP request.
3. After apply, the domains list offers a bulk action **Verify imported orders** (permission `domains.domains.update`, step-up, queued on `imports`, throttled by the registrar
   driver's own limits). For each claim it runs exactly the checks of the Link order action, then links the order. One sync follows each success.
   The result per domain: linked, refused (with the reason, e.g. another host's order), or still unverifiable (registrar unreachable;
   retried). The action is also available per domain. Both write activity entries.
4. **Per-client mode.** When the registrar's `customer_mode` is `per_client`, the claim's customer id is recorded as the
   client's `registrar_refs` customer only if all of these hold: the order verified; the order's customer id equals the
   claim; and the registrar's customer record, read at verification time, has a username (its login email) that
   equals the client's email in Mohar case-insensitively. The email match is the second proof that stops one client's claim from
   adopting another client's customer. An order that would verify only by trusting the claim is refused (Q2 asks the owner to approve this rule or choose the alternative below).
5. **Default-customer mode.** The claim's customer id is not needed; the order must belong to the default customer.
6. **Rows without a claim** stay unlinked; the domains list shows "Registrar order not linked" with the existing Link
   order action, and the review counts them. A claim that another domain row already holds is a row error.

Alternative if the owner does not want claims: a staff-only path, where verification asks the reseller account to list
orders of one customer and staff pick matches. Not recommended: it is the manual work this import exists to remove.

Registrars without a Mohar driver: `origin = imported_registrar`, `registrar_key = null`, a visible "managed outside Mohar" badge; no sync, no renewal call, reminders
and renewal invoices still follow `next_due_date` if `auto_renew` (staff renew by hand at the registrar and mark renewed, as for manual domains in `domains.md`).

### 7.3 Order ids from a supplementary file (API path)

For the API source, the connector does not get order or customer ids (§3.2). The run page accepts an optional second file
(domain name, order id, customer id) uploaded as a normal `domains` CSV run with only those columns; it matches by name and
only writes `import_claim`. Hosts that kept the ResellerClub order list can export it from the ResellerClub control panel.

### 7.4 Domains the client registered (`.np` and others)

Per `registrars.md` §9 (owner decision 2026-10-20), Mohar sells no `.np` registration: the client registers at the
registry and points names to the host's nameservers. Checkout creates no domain record for such a name. The import
differs on purpose, because a migrating host has these names in WHMCS and staff look them up there.

- A row is `client_registered` when its TLD is in `domains.self_registered_tlds` (the `.np` second levels by default), or its
  registrar is mapped to "client registered".
- Created as `origin = client_registered`, `registrar_key = null`, status `active` (or the mapped status), no
  registrar order, no claim, `expiry_date` kept only if given (a `.com.np` has no expiry; the registry's reverification
  is the client's concern), `next_due_date` and `next_invoice_date` null, `auto_renew` false, recurring amount zero.
- Never in renewal invoicing, sync, expiry reminders, ICANN-style notices, or grace and redemption transitions; the state
  machine refuses to move them. Staff may still set the status (for example cancelled).
- Shown in the client area as "Registered by you at the registry; points to our nameservers" with the guide link
  (`domains.self_registration_url`).
- Whether these should be records at all, or stay only as the note on the service, is Q5; the assumption is records.

## 8. Admin and client capabilities

**Admin:** Admin → Setup → Migration gets entity choices Invoices, Invoice items, Payments and Domains on the same upload
page; the same review cards (new, updates, unchanged, needs a decision, errors) plus **Open balance** (total per
currency) and **Not linked at registrar**; Verify imported orders on the domains list; "Imported from <source> on <date>"
on invoice and domain pages; a run-level report with per-currency totals of invoiced, paid and open amounts, which staff compare to
the source's aging report before confirming (the confirm dialog shows these totals).

**Client:** nothing changes until their invitation (`client-import.md` §5.7). After it, imported open invoices appear like any other and can
be paid through the gateways enabled in Mohar; an imported domain appears with its status and renewal date. The client never sees a
"newly issued" notification for them.

## 9. Automation safety (MUST)

The rules of `migration.md` §7 apply unchanged: `saveQuietly()`, no `App\Events\{Core,Billing,Domains,...}` events, no mail or
notification, no driver or provider resolution (the arch test in `tests/Arch/ExtensionsTest.php` covers `App\Modules\*\Import`), automation pause on first
commit. Specific to this phase:

| Risk                                          | Mechanism                                                                                                                                                                                                                                         |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Invoice, receipt or "payment received" mail    | No `InvoiceIssued`, `InvoicePaid`, `PaymentReceived` events; PDFs are not generated at import; staff can send an invoice later by hand                                                                                                              |
| Back-dated late fees and reminders             | The importer sets `late_fee_applied_at` and seeds `reminder_log` for every rule whose trigger date is before the baseline date. For a CSV run the baseline is the apply time (option "Start reminders from", default today); for the API it is the cutover. Invoices due after the baseline follow the normal rules |
| Auto-capture of old invoices                   | No pay methods are imported, so nothing can be captured; `last_capture_attempt_at` is stamped at the baseline anyway                                                                                                                              |
| Auto-cancel and suspension of services        | Services that depend on an overdue imported invoice get the grace window of `migration.md` §7 (`suspend_exempt_until`) through Services' own cutover, when services are imported                                                                |
| Double renewal                                 | Linked renewal lines carry `period_start`; the domain's `next_invoice_date` is advanced past a due date that an open imported invoice covers (§7.1); `IssueRenewalInvoices` is blocked by `hasInvoiceForPeriod`. Domains without a linked invoice start at `next_due_date`; staff-visible `import_hold` applies to those more than 60 days overdue without an open invoice, as for services |
| Registrar side effects                         | No call at import. Sync is off for imported domains until go-live (`migration.enable_domain_sync_after_cutover`); verification is an explicit staff action (§7.2)                                                                                   |
| Affiliate commissions                          | None: `PaymentReceived` is not dispatched, and imported payments carry `imported_run_id`, which Affiliates must ignore (§13 contract note)                                                                                                         |
| Domain expiry notices                          | `domain_reminders` are seeded for offsets already past at the baseline                                                                                                                                                                             |
| Gateway subscriptions                          | Domains with a `subscriptionid` are counted in the report; the cutover runbook asks the host to cancel or keep them (`migration.md` §7)                                                                                                            |

## 10. Permissions, step-up and audit

`migration.runs.view`, `migration.runs.execute`, `migration.runs.undo` as in `client-import.md` §11. Confirm and undo need
`password.recent`. **Verify imported orders** needs `domains.domains.update` and a recent password. The run's confirm dialog
states the money consequence: "Create 3,412 unpaid invoices with an open balance of NPR 41,20,500.00. No email is sent. You can undo until <date>."
Audit: run-level entries as for clients; one bulk entry per chunk for created invoices/payments/domains; each verification and order link
writes its own entry with the order id. No registrar response bodies are stored in logs (ADR 0023).

## 11. Undo (via `RevertsImport`, ADR 0019 addendum)

Within `migration.undo_days` (7), per entity in reverse order (payments, adjustments, items, invoices; then domains):

- an invoice is deleted only if it is still unnumbered, unpaid by anything other than imported payments, has no note, no mail
  log, no staff edits since, no gateway attempt, and no later run wrote to it; otherwise it is kept and listed with the reason;
- imported payments are removed with their invoice; a payment made in Mohar after import blocks the undo of that invoice;
- a domain is deleted only if it has no order, no renewal invoice, no verified order link, no sync result newer than
  import, and no edits; a verified link blocks undo (the registrar relation is real). `import_claim` is cleared on undo;
- undo is audited, writes no events other than `MigrationRunUndone`, and ends the undo window.

## 12. Edge cases

- One WHMCS "mass payment" invoice that wrapped others: imported only if open, flagged `W_MASSPAY_INVOICE` and as a plain invoice; payment batches are not recreated.
- Invoice with a negative line (discount or promo): kept as a negative item; totals still checked.
- Invoice for a closed client: imported, the client stays closed; the review shows it.
- Client merged since (phase 1 map points to the survivor): resolved through the map.
- Same invoice number twice in one file: both rows are errors; with an id column they are two invoices if ids differ and a warning.
- A number that already exists in Mohar's legal sequence (option "keep numbers"): row error.
- A source invoice whose items use a tax the operator mapped to "none" while `tax > 0`: blocked.
- Domain with expiry in the past and status Active: imported as recorded with a warning; the state machine moves it to grace on its first run after go-live, so the run page recommends syncing statuses first.
- Domain in Redemption/Transferred Away/Cancelled: imported with the status, never renewed, kept for history only when the option "Include inactive domains" is on (default on for Expired, off for Cancelled and Transferred Away).
- A domain name under a client other than the one the registrar holds: claim verification fails in per-client mode; the row shows the reason.
- Staff upload the same file twice: SHA-256 warning, then equal checksums give `unchanged`.
- Currency decimals: NPR and INR 2, JPY 0; a fraction in a zero-decimal currency is a warning and is rounded.
- 20 MB or 100,000-row limits are those of `client-import.md`; invoices are rows, so the limits fit one WHMCS install's open invoices.

## 13. Events, integration and contract changes (ADR 0008, 0010, 0011, 0019)

**Emitted:** only `MigrationRunCompleted`, `MigrationRunPaused`, `MigrationRunUndone` (`app/Events/Migration`), whose counts gain the entities `invoice`, `invoice_item`,
`payment` and `domain`. **Consumed:** none. Notifications continues to ignore Migration events apart from staff summaries.

Integration, each owning module through its own importer registered in `bootModule()` (Migration imports no module classes):

| Registry key | Module  | `dependsOn`                  | Notes                                                                                                   |
| ------------ | ------- | ---------------------------- | ------------------------------------------------------------------------------------------------------- |
| `domain`     | Domains | `client`                     | Implements `RecordImporter`, `RevertsImport`, `MatchesExisting`, `PreparesChunk`                         |
| `invoice`    | Billing | `client`, `domain`, `service` | Items and payments are `children` of the `ImportRecord`; implements `RevertsImport`, `PreparesChunk`     |

Proposed kernel changes (CTO decides, ADR 0010: all additive):

1. **`MatchesExisting`** and **`PreparesChunk`** capability interfaces (ADR 0012 shape) land now with their first consumers (ADR 0019 direction); `ImportIdResolver::targetIds()` batch method.
2. `ImportRecord::$children` keys `items` and `payments` are documented as a schema; no class change.
3. A read contract from Domains to Billing, or a Billing-owned `InvoicedPeriods` read, so Domains `finalize()` can ask "does an open invoice cover this domain's due date?" without reading Billing tables. If `Invoicer`/`InvoiceQueries` already expose it, no change.
4. A Core DTO for the verified order claim is **not** needed: `import_claim` lives in Domains' `registrar_meta`.
5. Domains: new `origin` values and a nullable `expiry_date`/`next_due_date` for `client_registered`; the state machine and automation jobs skip that origin. Amends `domains.md` §2 and §3, and `registrars.md` §9 (checkout creates no record; import does).
6. Billing: the Nepal VAT tax mapping must be callable by the importer (the same service the checkout uses to snapshot tax rules); and `imported_run_id` on payments is ignored by Affiliates and by the "payments received" reports' new-business counts.
7. `Queues::IMPORTS` (already added by `client-import.md` A1) carries the new jobs.
8. Registrars' Link order action is reused by the bulk action; it needs a batch entry point that takes a claimed id instead of one typed by staff. No driver contract change.

## 14. Performance and large files

Targets on the small profile, one `imports` worker, PostgreSQL: 20,000 invoices with 60,000 items and 25,000 payments staged in under 3 minutes, checked in under 5
and applied in under 15; 5,000 domains each in a fraction of that. Rules as in `client-import.md` §13: streaming, fixed chunks (1,000 check, 200 apply), one query per lookup per chunk
(clients by email hash and id map, currencies, tax rules, domain names, invoice numbers), `$timeout`/`$tries` on every job, and a query-budget test.
The API source is bounded by the source (about 2 calls per open invoice and 1 per 25 domains by default at 5 requests a second); the page shows the estimate.

## 15. Where we do better than the category

- **Nothing is re-typed and nothing is trusted blindly.** Blesta and HostBill read the WHMCS database; the Upmind and Paymenter importers carry
  whatever they find. We import open money and claims, and verify a registrar link before it can act on a real order (#335's takeover case).
- **Tax stays exact.** Open invoices land as proforma, so the first legal number and the VAT-register line are created by Mohar on payment, not copied from another system's sequence.
- **The review shows the money.** Totals per currency before confirm, a balance cross-check per invoice, and a downloadable errors file.
- **Self-registered `.np` names are first-class** instead of hidden in a note.

## 16. Open owner questions

Asked of the owner by the CTO before the money-touching parts are built. Each has an assumed default so work can start.

1. **Q1 — Paid history.** Do hosts need paid, cancelled and refunded WHMCS invoices in Mohar? Assumed: no (they stay in WHMCS as read-only archive
   or PDF export); importing them would need a historical-document flag so they never reach the VAT register. Recommendation: not in v1.
2. **Q2 — #372, per-client ResellerClub mode.** Does the host run `per_client` (a ResellerClub customer per client) or the default customer? In per-client mode, may the
   customer id from the host's file be recorded when the order verifies **and** the customer's login email equals the client's email (§7.2 step 4)? Assumed: yes. Alternative: a staff pick list; or run the whole host in default-customer mode.
3. **Q3 — Nepal workflow, old invoice numbers.** Owner to confirm: WHMCS unpaid invoices were sent to clients as the bill; at cutover the host voids them in WHMCS without VAT being claimed on them, and the client then pays the Mohar copy, which gets
   a new legal number on payment (D1 of `vat-register.md`). If the accountant has already reported VAT on the WHMCS invoices, the same supply would be counted twice.
4. **Q4 — Part payments.** Owner to confirm: a client who has paid part of an invoice in WHMCS and the rest in Mohar gets the legal tax invoice dated on the final payment, for the full amount. The accountant should say whether VAT on the earlier part is reported in the WHMCS period.
5. **Q5 — `.np` records.** Owner to confirm: client-registered domains are kept as records (not renewed, not billed, no expiry) so staff can see them. Or are they only useful as notes?
6. **Q6 — Domain renewal price.** Keep each domain's agreed recurring price from the source, or move to Mohar's TLD price at import? Assumed: keep, with a flagged bulk reprice.
7. **Q7 — Unknown TLDs.** For a source domain whose TLD Mohar does not sell, create a retired TLD record automatically, or block until staff add the TLD? Assumed: offer both as a decision.
8. **Q8 — Samples.** Can the owner provide fake-data WHMCS exports of invoices, transactions and domains, and `GetInvoice` and `GetClientsDomains` responses, and say whether the ResellerClub module's order and customer ids are visible anywhere in WHMCS? They settle the **verify** items in §3. The free ResellerClub demo account (CEO review rec. 2) lets verification be proven live.
9. **Q9 — Payment Pending and Draft.** Skip them by default? Assumed: Payment Pending needs staff decision per row; Draft excluded.
10. **Q10 — Legacy numbering hosts.** For installs with proforma mode off and no Nepal VAT mode (India, global), is "keep source numbers" worth offering in v1, or always renumber? Assumed: offered, off by default. Technical sign-off from the principal architect on the backfill interaction (ADR 0024 §8).

## 17. Out of scope (v1)

Paid, cancelled and refunded invoices; credit and debit notes; quotes; services and addons (phase 2b); tickets; stored pay methods and gateway
subscriptions; domain contacts, nameservers, EPP codes, DNS records and lock state; live registrar lookups and bulk "sync all"; WHMCS database
reads; Blesta, HostBill or other API connectors; Excel files; payment batches; two-way sync.

## 18. Phases and PR plan

Each PR stays under 1,500 changed lines including tests and fixtures. PR 0 is a kernel PR by the CTO session.

| PR  | Phase | Contents                                                                                                           | Criteria |
| --- | ----- | ------------------------------------------------------------------------------------------------------------------ | -------- |
| 0   | 3     | Kernel: `MatchesExisting`, `PreparesChunk`, batch resolver, entity vocabulary, arch rules                          | 1–2      |
| 1   | 3a    | Billing invoice importer: columns, mapping step incl. tax and status maps, rules §4 and §6.1–6.3, check and review  | 3–15     |
| 2   | 3a    | Items and payments files, balance cross-check, reconciliation, automation baselines (§9), undo                     | 16–27    |
| 3   | 3b    | Domains importer: TLD, status, matching, client-registered origin; `domains` columns and state-machine skips        | 28–37    |
| 4   | 3b    | Registrar claims and **Verify imported orders** (reuses Link order), per-client customer rule                      | 38–45    |
| 5   | 3c    | API connector actions `GetInvoices`, `GetInvoice`, `GetClientsDomains`, delta, supplementary claims file             | 46–50    |
| 6   | 3     | Performance fixtures, load-test scenario, run report totals                                                         | 51–52    |

Dependencies: client import PRs 3–5 (clients, id map, undo); #338's Link order action (merged); PR 4 needs the open owner answer Q2; PR 1 needs Q3 and Q10.

## 19. Acceptance criteria

MUST unless marked SHOULD.

**Kernel and pipeline**

1. `MatchesExisting` and `PreparesChunk` exist as optional interfaces; `RecordImporter` gains no method; an importer without them still works.
2. The arch rule forbids `Mail`, `Event`, `Notification` facades, other modules' events and any driver or registrar contract in `App\Modules\*\Import` for the new importers.

**Invoices — identity, status, money**

3. Given a file with Unpaid, Payment Pending, Collections, Paid, Cancelled and Refunded rows, then only the first three are candidates (Payment Pending needs a decision), and the others are counted by status in the review and not staged for apply.
4. Given an invoice row for a client not in Mohar, then the row is an error "Import this client first" and no client is created.
5. Given an invoice whose currency differs from its client's, then the row is an error and nothing is converted.
6. Given `"12,34,567.50"` NPR, then 123456750 minor units; given a comma-decimal or a negative total, then a row error.
7. Given `subtotal 1000.00`, `tax 130.00`, `total 1130.00`, then the invoice is stored with those amounts and no recalculation, even when Mohar's tax rules differ.
8. Given a total that does not equal subtotal plus taxes, then the row is an error "The invoice does not add up".
9. Given tax above zero and no mapped tax rule, then the run reports a blocking issue and confirm is refused.
10. Given no items file, then each invoice has one synthesised "Balance brought forward" line and the review says so; with an items file, items sum to the subtotal or the row offers the single-line fallback.
11. Given the same file applied twice, then the second run is all `unchanged` and no `updated_at` changes.
12. Given an imported open invoice that has since been paid in Mohar, then a changed row is skipped with the reason and not overwritten.
13. Given an invoice paid at the source in a later file, then it is reported and left unchanged (SHOULD show a count on the run page).
14. Given a source id missing from a later API run, then it is marked missing and reported, never deleted.
15. Given an invoice with `source_reference`, `imported_run_id` and `imported_at`, then the invoice page shows "Imported from <source>".

**Invoices — numbering and VAT**

16. Given Nepal VAT mode enabled (or scheduled), then every imported invoice has `number` null, `numbered_at` null and `proforma_number` = the source number; the "keep source numbers" option is not shown.
17. Given an imported proforma invoice paid in full in Mohar, then it gets the next gap-free legal number, its issue date is the payment date, the fiscal snapshot is taken then, and `source_reference` is unchanged.
18. Given imported open invoices, then the VAT register lists none of them before payment (detail, IRD and summary layouts).
19. Given a partial imported payment on a proforma, then the invoice stays unnumbered; given the remaining balance paid in Mohar, then it is numbered on that payment.
20. Given proforma mode off and "keep numbers" on, when a number collides with an existing legal number, then the row is an error; when it does not, the sequence is moved past the highest imported number after the run.
21. Given the default options, when Nepal VAT mode is enabled afterwards, then enabling is not refused because of the import.
22. Given a numbered imported invoice (legacy path), then no import ever modifies its frozen columns.

**Invoices — balance, payments, credit**

23. Given a source balance that equals total minus credit minus payments in plus out, then no adjustment is written.
24. Given a difference, then the row needs a decision, defaults to skip, and "Import as recorded" writes exactly one "Migration reconciliation" adjustment so that Mohar's balance equals the source's.
25. Given payments that exceed the total, then the row is an error and no credit is created.
26. Given imported payments, then they are `transactions` with the source date and transaction id (or `import:<id>`), no gateway is contacted, and no receipt or `PaymentReceived` is produced.
27. Given credit applied at the source, then an imported `credit_applied` adjustment reduces the balance and the client's credit ledger is unchanged; with no imported credit balance the review warns.

**Domains — identity and records**

28. Given a name with no matching configured TLD, then the row needs a decision (add TLD or retired TLD record) and nothing is created before the choice.
29. Given a name that already has a non-terminal Mohar domain, then the row is an update-or-skip decision, never a second row; two rows for the same active name in one file are both flagged.
30. Given a source status of Pending Registration, Grace, Redemption, Expired, Transferred Away or Cancelled, then the mapped status is stored and the state machine does not run at import.
31. Given a domain with an open imported invoice line for its due date, then `next_invoice_date` is advanced one term and `IssueRenewalInvoices` creates nothing for that period.
32. Given an imported domain, then contacts, nameservers, EPP code and lock state are empty and the first sync after go-live fills them; sync is not run at import.
33. Given a source registrar with no mapping, then the run reports a blocking issue; given "none (managed by hand)", then the domain is `imported_registrar` with a "managed outside Mohar" badge and no registrar call is ever made for it.
34. Given a `.np` second-level name, then it is stored as `client_registered`, with no registrar, order, renewal, sync, reminder or expiry, and the client area shows the registry guidance.
35. Given a `client_registered` domain, then renewal invoicing, grace/redemption transitions and expiry reminders skip it (SHOULD: staff can still cancel it).
36. Given recurring and first-payment amounts, then they are kept in the client's currency with the "imported price" flag and the review shows the difference from the current TLD price.
37. Given a domain for a client in a different currency, then the row is an error.

**Domains — registrar claims (#338, #372)**

38. Given a row with a ResellerClub order id, then the id is stored in `registrar_meta.import_claim`, the domain is not linked, and sync, renew and EPP actions still refuse with "No ResellerClub order is linked".
39. Given the import under `Http::fake()` for the registrar, then no registrar request is made.
40. Given **Verify imported orders** on claims that pass the Link order checks (name, reseller, still held, customer), then the order is linked, one sync is queued, and an activity entry records the id; failing rows list their reason.
41. Given a claim whose order id is held by another domain row (deleted rows included), then the row is an error at check time and the link is refused at verification.
42. Given per-client mode, a claim whose customer id matches the order and whose registrar customer login email equals the client's email, then the customer is recorded in `registrar_refs` for that client and the order links; given a mismatching email, then nothing is recorded and the reason is shown.
43. Given per-client mode and a claim with no customer id, then the order is not linked and the row says a customer is needed.
44. Given default-customer mode, then the order links when it belongs to the default customer, and no customer is recorded for the client.
45. Given the bulk action, then it needs `domains.domains.update` and a recent password, runs on `imports`, and is idempotent (re-running changes nothing already linked).

**API source**

46. Given `GetInvoices` with `status=Unpaid` over 230 invoices at page size 100, then 3 pages are requested and 230 rows staged; `GetInvoice` is then called once per open invoice only.
47. Given a delta run, then `GetInvoice` is called only for list rows whose checksum changed.
48. Given `GetClientsDomains` pages, then domains are staged with no order id or customer id; a supplementary claims file adds `import_claim` by name only.
49. Given the connector, then `Http::fake()` shows only allow-listed read actions, and `DomainGetNameservers` is never called.
50. Given an HTTP failure on page 3, then `--resume` continues at that page without duplicates.

**Performance and report**

51. Given the fixture of 20,000 invoices, 60,000 items and 25,000 payments, then staging, check and apply meet the targets of §14 and the review page stays within its query budget.
52. Given a completed run, then the report shows per-currency counts and totals (invoiced, paid at source, open) that equal the sum of the stored rows, and the confirm dialog showed the same open total.

**Cross-cutting**

53. Given a full fixture import under `Event::fake()`, `Mail::fake()`, `Notification::fake()` and `Queue::fake()`, then only `App\Events\Migration\*` events are dispatched, no mail is sent, and no provisioning, gateway or registrar job is queued.
54. Given an imported overdue invoice at the baseline, then no late fee is applied and no reminder is sent on the first `automation:run`; given one due after the baseline, then normal reminders apply.
55. Given an undo within the window, then unchanged, unpaid, unnumbered invoices and unverified domains are removed with their imported payments; invoices with a Mohar payment, a note or a mail, and domains with a verified link, are kept with the reason.
56. Given staff without `migration.runs.execute`, then every action returns 403, and every action writes an `activity_log` entry with no cell values in logs, exceptions or queue payloads.
57. SHOULD: the errors CSV prefixes cells starting with `=`, `+`, `-`, `@`, tab or carriage return.

## 20. Sources

WHMCS (read 2026-10-29): API reference pages GetInvoices https://developers.whmcs.com/api-reference/getinvoices/ · GetInvoice
https://developers.whmcs.com/api-reference/getinvoice/ · GetClientsDomains https://developers.whmcs.com/api-reference/getclientsdomains/
· GetTransactions https://developers.whmcs.com/api-reference/gettransactions/. These pages list fields only through example JSON, so
per-field semantics are marked **verify** above. Reports → Exports (invoices, transactions, domains) as read for `client-import.md`
on 2026-10-20 (https://docs.whmcs.com/8-13/system/reports/); not re-read. Other products' importers as in `migration.md` §15 (read 2026-10-17 and 2026-10-20).
Regulation: Nepal VAT and IRD behaviour is taken from `vat-register.md` and ADR 0024; the IRD pages were not re-read for this note. India GST is not touched.
ResellerClub behaviour comes from `registrars.md` (§5 `lookupOrder`, §9 `.np`) and from #335, #338, #365 and #372; no ResellerClub page was re-read today.
Our code and specs: `client-import.md`, `migration.md`, `domains.md`, `registrars.md`, `vat-register.md`, `billing.md`, ADR 0008, 0013, 0019 (and addendum), 0023, 0024.
