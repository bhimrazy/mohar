# Month-end reports pack — Product Spec (v1)

Status: draft for engineering · Owner: Reports workstream (Billing owns the reports that read only Billing's tables) ·
Refines `reports.md` §4 and §6 (rows `income_monthly`, `income_by_gateway`, `outstanding_aging`, `overdue_invoices`,
`credits`, `churn`) and adds renewals, refunds and the month-close page · Built on ADR 0025 (registry, export pipeline,
step-up), ADR 0020 and its Amendment (#515) (query budget and conformance kit), ADR 0024 and `vat-register.md`
(fiscal documents) · Sources read 2026-10-29 (§18) · Drives CEO review 2026-10-29 recommendation 5 and the WHMCS parity
review 2026-10-29, section 2.11 · Owner decisions Q1, Q3, Q4 and Q6 recorded 2026-10-29 (§15).
Clean-room: behaviour derived from public category docs and our own specs; names, schemas and wording are ours.

## 1. Purpose and scope

A Nepali host closes the books once a month, by BS month, for the accountant who files the VAT return. Today Mohar has
the VAT register and the credit-note register and nothing else for that day: no receivables aging, no income by
gateway or month, no list of renewals due, no refund or credit-balance account. The CEO and parity reviews of
2026-10-29 rank this a top gap (reports 3 of 8; "no aging, income or renewals report").

This spec defines eight reports and one month-close page, all as `Report` implementations in the ADR 0025 registry,
shown through `ReportViewer::page()`, exported through `ReportExporter` (CSV and PDF), and checked by
`ReportConformance` (paging and the 12-query budget). **No framework change**: new things are filters, columns,
layouts, indexes, three small tables (§4) and the shared period helper (§3.1).

| Key                 | Report                                  | Owner module | Source tables                                       | Tag    |
| ------------------- | --------------------------------------- | ------------ | --------------------------------------------------- | ------ |
| `outstanding_aging` | Receivables aging and pending proformas | Billing      | `invoices`                                          | MUST   |
| `income_monthly`    | Income by month (cash basis)            | Billing      | `transactions`                                      | MUST   |
| `income_by_gateway` | Income by gateway                       | Billing      | `transactions`, `gateways`                          | MUST   |
| `refunds_register`  | Refunds and chargebacks                 | Billing      | `transactions`, `tax_notes`, `invoices`             | MUST   |
| `credit_balances`   | Client credit balances (as of a date)   | Billing      | `credit_ledger`                                     | MUST   |
| `credit_movements`  | Credit account for the month            | Billing      | `credit_ledger`                                     | SHOULD |
| `renewals_due`      | Renewals due in the next period         | Reports      | `services`, `service_addons`, `domains`, `invoices` | MUST   |
| `service_movements` | New and churned services and domains    | Reports      | `report_service_movements` (§4)                     | SHOULD |
| `month_close`       | Month-close summary and reconciliation  | Reports      | `report_month_close_*` (§4), other reports          | SHOULD |

`overdue_invoices` in `reports.md` §6 becomes the `detail` layout of `outstanding_aging` (min-days filter); its row in
that table is superseded by this spec. `credits` becomes `credit_movements`. The MRR family (`mrr`, `mrr_movements`,
`churn`) stays in `reports.md`; this pack counts items, it does not value them.

The Reports module does not exist on `develop`. Billing's reports register from Billing's provider (as the VAT
registers do) and render at `Billing → Reports`. Cross-module reports (`renewals_due`, `service_movements`,
`month_close`) belong to the Reports module, which `architecture.md` allows to read other modules' tables through their
models. PR 1 of §19 creates the module as a library page over the same registry.

## 2. What hosts expect, and where we do better

WHMCS ships, in its built-in report library (read 2026-10-29): aging invoices, annual income by month, income by
product, monthly transactions, income forecast, sales tax liability, credits reviewer, new customers, client statement,
top clients and a domain renewal reminder log. It exports CSV or opens a printer view. It has no renewals-due list, no
refund register, no credit-balance account and no reconciliation. Its billing documents and reports use AD months.

Where we do better, in one line each:

- **BS month first.** The period picker offers BS months and fiscal years, defaulting to the last closed BS month,
  with the VAT register's exact boundaries (`BikramSambat`, company clock). An AD range stays available.
- **One page ties the books.** `month_close` puts cash in, refunds, deposits held as credit, receivables and the
  VAT-register totals side by side and shows every difference as a named line, never as a hidden remainder.
- **Refunds tie to credit notes.** In Nepal VAT mode each refund row shows the note number that corrects the VAT
  bill, and a refund with no note is flagged (the accountant's first question).
- **Multi-currency honest.** Totals are per currency and never added across currencies; v1 does no conversion.
- **Proformas kept out of the debtors total.** Aging shows unpaid proformas in a section of their own, so the
  receivables figure is only what is legally owed.
- **A renewals list that is actionable.** It shows whether the renewal is already invoiced, paid, set not to renew,
  or has a payment method on file, so the host knows whom to call.

## 3. Shared conventions (apply to every report here) — MUST

### 3.1 Period and date filters

- Filters follow the VAT register: `month` (BS, "2083-06"), `year` (BS fiscal year, "2083.084"), `from` and `to`
  (AD). `RegisterPeriod` today lives in Billing; the Reports module cannot import it (ADR 0001). The period
  parser and its filter set move to the kernel as `App\Support\Reports\CompanyPeriod` in PR 0 (CTO session, a kernel
  change, §16); `RegisterPeriod` becomes a thin user of it. Reports with a point-in-time basis (`outstanding_aging`,
  `credit_balances`) take a single `as_of` date instead.
- **Decided (owner, 2026-10-29, Q1).** Hosts close by BS month. With no filter, the period is the last closed BS month
  on the company calendar. The screen passes the default explicitly so an export records the period it covers (as the
  registers do).
- **Fiscal year close (Ashad end, Shrawan start).** The BS fiscal year runs 1 Shrawan to the end of Asar
  (vat-register F4). The `year` filter already offers it. v1 adds no special handling at the boundary: no year-end
  report, no carry-forward and no lock. The month-close for Asar is prepared like any other month.
- An AD month ("calendar month") is a normal `from`/`to` range with a quick-pick; the BS month is the default because
  the VAT return is filed by BS month (vat-register F4).
- Period boundaries are company-calendar days converted to UTC instants with `CompanyClock` (ADR 0013). A
  transaction at 23:30 Nepal time on the last day of a BS month belongs to that month. Never bucket with a UTC date.
- A range is at most 366 days (`MAX_RANGE_DAYS`), so `count()` and `totals()` stay bounded (ADR 0025 Amendment #428).
- Every date cell shows AD (ISO in CSV) and a second text column "Date (BS)" beside it where the row has a date, as
  the owner decided on 2026-10-22 (AD with BS beside). BS comes from `BikramSambat::fromAd`, in code, not stored.

### 3.2 Currency and money

- Money is integer minor units (ADR 0002). A report **never adds amounts of different currencies**. Footer and PDF
  totals are one line per currency (ADR 0025 §3). Rows keep their native currency in a `currency` column.
- **Decided (owner, 2026-10-29, Q6).** Income stays native-currency only. v1 has no converted ("Rs equivalent")
  column in any report, no base-currency total and no stored conversion rate on transactions. A later version adds
  conversion once the owner decides the rate rule (D6); the report columns and the `currency` filter are designed so
  that a converted column can be added beside the native ones without changing them.
- The filter `currency` (select, default "All") limits rows to one currency.
- Fraud-status orders and services are excluded; transactions of deleted clients still count (`reports.md` §2).

### 3.3 Permissions, export and audit

- Each report registers `reports.{key}.view` and `reports.{key}.export` through the registry (ADR 0025 §4). The
  seeded Accountant-style and Billing roles get view and export for the Billing-owned reports; Support roles get none.
- Exports: CSV (UTF-8 with BOM by default for these reports, so Excel reads Nepali client names; `ProvidesCsvOptions`)
  and PDF (A4 landscape, brand and "Printed by" from the kernel view). Sync caps apply (5,000 CSV rows, 1,000 PDF
  rows); an aging detail export above the cap is refused with "narrow the filters", as the registers are. Queued
  exports arrive with the Reports module's own PR (ADR 0025, deferred) and need no change here.
- **Sensitive?** These exports carry client names and amounts but no PAN, bank or card data. They are **not**
  `HasSensitiveExport`, except `outstanding_aging` detail, which lists who owes what: decided 2026-10-31 (§15, Q9) to be
  a sensitive export with the password step-up. It is one marker interface on that class.
- Screen routes are rate-limited per user (60/min, like `billing-registers`). Viewing is not logged; each export
  writes one `report.exported` entry.

### 3.4 Layouts

Reports offer `ProvidesLayouts`: a `summary` layout that fits an A4 landscape PDF (the kernel gives each text column at
least 90px) and a `detail` layout with every column. CSV default is `detail`; the print default is `summary`.

### 3.5 Query rules (ADR 0020 Amendment #515)

- `count()` and `totals()` are a fixed number of aggregate queries served by an index; `rows()` loads related names in
  bulk (client, gateway, product, invoice number) with one `whereIn` each. No per-row query.
- One page including totals runs at most 12 queries; each report passes `assertPages()` and `assertQueryBudget()`
  in its own test, and `tests/Arch/ReportsTest.php` enforces that.
- Every report is validated against a 50,000-client seed (the load profile of #108): the page request stays under
  one second on PostgreSQL. Indexes in §5 are added with `OnlineIndex::create` (CONCURRENTLY; expand-only, ADR 0006
  rules, `deploy:check-migrations`). An index is added only after `EXPLAIN` on the seed shows the need; the spec lists
  the expected ones.

### 3.6 Proforma versus tax invoice

With Nepal VAT mode on (D1), an invoice is a proforma until paid in full and becomes a tax invoice with a legal
number on payment (vat-register §3). Consequences for this pack:

- **Decided (owner, 2026-10-29, Q3).** Aging shows unpaid proformas in their own "Pending proformas" section with its
  own total. They are **excluded from the receivables total**. A _pending proforma_ is an unpaid invoice that has no
  legal `number` (only a `proforma_number`); every other open invoice (legally numbered, including legacy numbered
  invoices and invoices in `collections`) is a _receivable_. With proforma mode off, every issued invoice is numbered
  and the pending-proformas section is empty and hidden.
- A proforma is not yet a VAT sale and is not in the VAT register; the aging header says so.
- Income reports are cash basis and do not care about the number.
- `month_close` reconciles cash to the VAT register (§5.9) because the two differ by design.

## 4. Entities (Reports-owned, new)

All three are small, written by jobs or listeners, never by request handlers.

- `report_service_movements (id, occurred_on date, subject_type: service|domain, subject_id, client_id, product_id
  nullable, tld_id nullable, movement: new|reactivated|churned|imported|transferred_in|transferred_out|expired,
  from_status nullable, to_status, source_event_id unique, created_at)`. Index `(occurred_on, movement)` and
  `(subject_type, subject_id, id)`. Serves `service_movements`. **SHOULD**
- `report_month_close_runs (id, period_key, period_from, period_to, prepared_by, prepared_at, status:
  running|ready|failed, note)` and `report_month_close_lines (id, run_id, section, position, label, currency, amount
  bigint nullable, amount2 bigint nullable, source_report nullable, source_filters json nullable)`; index
  `(run_id, position)`. A run is immutable once `ready`; preparing again adds a run. **SHOULD**
- `report_ar_daily (as_of date, currency, section: receivable|proforma, bucket: not_due|d1_30|d31_60|d61_90|d91_plus,
  invoice_count, balance bigint)` unique `(as_of, currency, section, bucket)`: one row per day, currency, section and
  bucket (at most 10 per currency). Written nightly after the daily run (`reports:snapshot-ar`, 00:20 company time,
  idempotent). It lets "aging as of the last day of last month" answer exactly after the fact, because an invoice's
  current `balance` cannot say what it was last month. **SHOULD**

No change to `transactions` is made in v1 (Q6: no conversion).

## 5. The reports

Filters named below are in addition to the shared period filters of §3.1. "Perm" is the pair `reports.{key}.view` /
`.export`.

### 5.1 `outstanding_aging` — Receivables aging and pending proformas (Billing) — MUST

- **Purpose.** Who owes what, and for how long, on the day of the close. The host's collection list and the
  accountant's debtors schedule, with proformas that are still only offers kept apart.
- **Filters.** `as_of` (date, default today in company time; a past date needs the snapshot, see below), `currency`,
  `client` (text: id or name fragment), `gateway` (select: the invoice's chosen gateway), `section` (All, Receivables,
  Pending proformas), `min_days_overdue` (integer, default 0), `layout`.
- **Bands.** **Decided (owner, 2026-10-29, Q4).** Not yet due, 1–30, 31–60, 61–90 and 91+ days, counted from the
  **due date** (never the issue date). The edges are fixed in v1 and live in one constant so that a later version can
  make them a setting without changing the report.
- **Basis.** Invoices with status `unpaid`, `payment_pending` or `collections` and `invoices.balance` greater than
  zero. `draft`, `cancelled`, `paid`, `refunded` are excluded. Days overdue = `as_of − due_date` in company days.
  Section: an invoice with no legal `number` is a _pending proforma_, any other is a _receivable_ (§3.6).
  `collections` invoices keep their age and are also reported as the line "In collections" in the receivables
  totals. A partly paid invoice counts at its remaining `balance`.
- **Layouts.**
    - `summary`: two blocks on screen and in the PDF. _Receivables_: one row per client and currency, a column per
      band, a total column, sorted by total descending. _Pending proformas_: the same columns, its own block.
    - `detail`: one row per invoice, with a `Section` column: client number and name, invoice or proforma number,
      issue date, due date, days overdue, band, status, gateway, total, paid, credit applied, balance, last reminder
      date (from `reminder_log`), currency.
- **Totals.** Per currency, **two separate total blocks**: _Receivables_ (band totals, grand total, invoice and client
  counts, of which in collections) and _Pending proformas_ (band totals, grand total, counts). No grand total adds
  the two. The month-close and any PDF show the receivables total as "Receivables" and the proforma total as "Pending
  proformas (not receivables)".
- **As-of in the past.** For `as_of` before today the report reads `report_ar_daily` for the totals of both sections
  and shows only the `summary` totals (no invoice rows), with the note "Snapshot of <date>". If no snapshot exists
  for that day, the report refuses the date and names the nearest available. Rebuilding a past day from payment
  history is not attempted (invoice `balance` is mutable and approximate history would mislead an accountant).
- **Query approach.** `count()`: `count(*)` over a grouped subquery (`client_id, currency, section`) for the summary
  and plain `count(*)` for the detail, on `status in (...)`. `totals()`: one grouped aggregate by `currency, section`
  using `sum(balance) filter (where ...)` per band, with band edges passed as bound company dates (never
  `CURRENT_DATE`, which is the database's day). The section is `number IS NULL`. `rows()`: the grouped query for the
  page, then one lookup of clients. Banding is a `CASE` on `due_date` against bound dates, so it runs on SQLite too.
- **Indexes.** `invoices(status, due_date)` exists. Add, if `EXPLAIN` shows a scan on the 50k seed, a partial index
  `invoices_open_due_index (due_date, currency) INCLUDE (client_id, balance) WHERE status IN ('unpaid',
  'payment_pending','collections')`.
- **Edge cases.** An invoice in a currency different from the client's default stays in its own currency block. An
  invoice with a null `due_date` (should not exist once issued) is "Not yet due" and counted in a data warning.
  Partial payments in flight (`payment_pending`) are included with a "pending" marker, since the money may not have
  settled. A credit note that reduced a tax invoice's balance is already in `balance`. Invoices of closed clients are
  included (the debt remains). A proforma that is paid moves out of the proformas block on the next run (it becomes a
  tax invoice and is no longer open).

### 5.2 `income_monthly` — Income by month, cash basis (Billing) — MUST

- **Purpose.** What came in and what was kept, month by month for a BS fiscal year (or an AD year), the number the
  owner reads first.
- **Filters.** `year` (BS fiscal year, default the current one) or AD `from`/`to` up to 366 days, `currency`,
  `gateway`, `layout`.
- **Rows.** One row per month in the period (BS months for a BS year) and currency. Columns: month (BS name and
  number), AD range, payments in (count and amount), of which customer deposits (`add_funds` invoices), refunds,
  chargebacks net of reversals, gateway fees, income (payments − refunds − chargebacks), net income (income − fees −
  manual expenses), prior-year same month (income only, SHOULD). Definitions are exactly `reports.md` §4 (Gross cash
  in, Refunds, Chargebacks, Fees, Income, Net income). Only `status = succeeded` rows; `dispute_inquiry` rows
  (zero) are ignored.
- **Native currency only (Q6, decided 2026-10-29).** Every figure is in the transaction's own currency, one row per
  month and currency. There is no converted column, no base-currency total and no rate stored on transactions. A
  host with USD and NPR payments sees two rows per month.
- **Totals.** Per currency: the column sums over the period.
- **Deposits.** Cash received for `add_funds` invoices is in "payments in" (it is cash) and also shown as "of which
  deposits", so the host can see what is earned versus held as credit (liability, `credit_balances`). Applying credit
  to an invoice is never cash (`reports.md` AC 4).
- **Query approach.** Month bucketing is a `CASE` on `occurred_at` against UTC instants computed from the company
  calendar (at most 12 or 13 ranges), so there is one grouped query: `group by month_bucket, currency` with
  `sum(amount_in) filter (where type in ('payment','manual_income'))`, refunds, chargebacks, fees. `count()` is the
  number of month×currency groups (at most about 13 × currencies), computed in the same pass. No walk through rows.
- **Indexes.** `transactions(type, occurred_at)` exists. If the seed shows a scan, add a partial covering index
  `transactions_income_index (occurred_at) INCLUDE (type, currency, gateway, amount_in, amount_out, fee) WHERE
  status = 'succeeded'`.
- **Edge cases.** A refund counts in the refund's month, not the payment's (`reports.md` AC 2). A chargeback won
  later (`chargeback_reversal`) reduces the chargeback line in its own month. A payment by a deleted gateway still
  appears under its key. A payment recorded manually (`manual_income`) has gateway `manual` or `bank_transfer` and is
  included. Failed and pending rows are excluded.

### 5.3 `income_by_gateway` — Income by gateway (Billing) — MUST

- **Purpose.** Which gateway brings the money, and what it costs. The comparison of eSewa, Khalti, bank transfer,
  connectIPS and cards; the basis for deciding which gateways to keep.
- **Filters.** Period (§3.1, default last closed BS month), `currency`, `gateway`.
- **Rows.** One row per gateway and currency, native amounts: gateway display name and key, payment count, payments
  in, refunds, chargebacks net, fees, income, net income, average payment, share of income (percent, within the
  currency). Gateways with no activity in the period are not listed.
- **Totals.** Per currency. Share is computed on the currency's total, in basis points, rounding half-up, and sums to
  within one point of 100 (the rounding is shown, not forced).
- **Query approach.** One grouped query on `transactions` by `gateway, currency` for the period using the same
  `filter` sums as 5.2; one `whereIn` for gateway names from `gateways`.
- **Indexes.** As 5.2; a `gateway` column is not indexed because the period narrows first.
- **Edge cases.** Credit applied is not a gateway and does not appear. `add_funds` payments are in their gateway's
  income, with the "of which deposits" column as in 5.2. A refund made to account credit (mode `to_credit`) moves
  value to the credit ledger and is not money returned by the gateway; it does not reduce gateway income. **To
  confirm with the CTO against `RefundTransaction`** (§15, Q10).

### 5.4 `refunds_register` — Refunds and chargebacks (Billing) — MUST

- **Purpose.** The month's account of money returned, tied to the VAT credit notes. The accountant checks it against
  the credit-note register.
- **Filters.** Period, `currency`, `gateway`, `kind` (All, Refund, Chargeback, Chargeback reversed), `without_note`
  (boolean: only refunds that have no credit note, in VAT mode).
- **Rows.** One per `refund`, `chargeback` or `chargeback_reversal` transaction in the period: date (AD, BS), kind,
  client, gateway, provider reference, original payment date and amount, invoice number, amount, currency, mode
  (gateway, record only, to credit, account credit payout), credit note number and date (when `tax_note_id` is set),
  status text. Sorted by date.
- **Totals.** Per currency: refunds, chargebacks, reversals, net returned, and (VAT mode) "refunds with a credit
  note" and "refunds without a credit note".
- **Pending and reserved refunds.** A refund reservation not yet settled (ADR 0024 §5a) is shown above the table as a
  warning count with a link to the staff list; it is not a row and not in totals, because the money has not moved.
- **Query approach.** `count()` and `totals()`: grouped aggregates on `transactions` where `type in
  ('refund','chargeback','chargeback_reversal')` and `status = 'succeeded'` over the period, using
  `transactions(type, occurred_at)`. `rows()` loads `tax_notes` (number, date) with one `whereIn` on `tax_note_id`
  (the partial index exists), invoices by id, clients, and parent transactions for the original payment.
- **Edge cases.** In VAT mode a refund of an invoice-paid amount normally has a credit note (A4); a refund of account
  credit (`purpose = credit_refund`) needs none and is not flagged. A chargeback is not a credit note until the host
  decides (A5); it is listed with "no note" and not flagged as an error. A partial refund shows its own amount, not
  the invoice's. A refund of a prior-year payment is listed in the current period. With VAT mode off the note columns
  are hidden.

### 5.5 `credit_balances` — Client credit balances (Billing) — MUST

- **Purpose.** What the host owes clients in prepaid credit on the closing date; the liability line of the close.
- **Filters.** `as_of` (date, default today; any past date is exact), `currency`, `min_balance`, `client`.
- **Rows.** One per client and currency with a non-zero balance at the end of `as_of`: client, currency, balance,
  last movement date, last reason. Sorted by balance descending. Negative balances (a ledger anomaly) are listed
  and flagged.
- **Totals.** Per currency: total held, number of clients, and the largest balance.
- **Query approach.** The ledger is append-only with `balance_after`. Resolve the cutoff once: `cutoff_id =
  max(id) from credit_ledger where created_at < :next_day_start_utc` (served by the `created_at` index below). Rows:
  per `(client_id, currency)` the greatest `id <= cutoff_id` and its `balance_after` (`DISTINCT ON` on PostgreSQL,
  a `max(id)` join elsewhere), paged with a keyset on balance. `totals()` is `sum(amount) where id <= cutoff_id group
  by currency`, which equals the sum of closing balances (an invariant a test asserts) and avoids a per-client walk.
- **Indexes.** `credit_ledger(client_id, currency, id)` exists. Add `credit_ledger(created_at)`.
- **Edge cases.** The ledger carries no deletes, so past dates are exact. Different currencies per client are
  separate rows. Imported clients have an opening ledger row from the importer, or no credit (a client's balance
  before import is not reconstructable; the importer's baseline row is the start). Deposits are liabilities, not VAT
  sales (A3): the report header says "Customer deposits held; not income".

### 5.6 `credit_movements` — Credit account for the month (Billing) — SHOULD

- **Purpose.** Opening balance, what moved, closing balance, for each currency, so the close can prove the credit
  liability moved by the amounts the other reports show.
- **Filters.** Period, `currency`.
- **Rows.** One per currency and `reason` (`add_funds`, `overpayment`, `refund_to_credit`, `applied_to_invoice`,
  `manual`, `downgrade`, `reversal`): count, credits added, credits used. Header rows "Opening" and "Closing".
- **Invariant (tested).** Opening + Σ movements = Closing, to the paisa, per currency; closing equals
  `credit_balances` totals for the last day of the period.
- **Query approach.** Opening = `sum(amount)` for `id <= cutoff(start)`; movements = grouped sums for `id` between the
  two cutoffs; two cutoff lookups on `credit_ledger(created_at)`. Fixed number of queries.
- **Edge cases.** A refund to credit appears here as `refund_to_credit` and in `refunds_register` as mode `to_credit`.
  Credit applied to a tax invoice is a payment of that invoice for VAT purposes at the time it is applied (A3); the
  report only counts it as a use of credit.

### 5.7 `renewals_due` — Renewals due in the next period (Reports) — MUST

- **Purpose.** What will be invoiced or has to be collected soon; the call list and the cash forecast for the coming
  month, and the early warning for domains and services that will lapse.
- **Filters.** Period (default the **next** BS month, with quick picks 7, 15, 30, 60, 90 days from today; at most 366
  days), `type` (All, Service, Add-on, Domain), `currency`, `gateway` (the item's chosen gateway), `product` (select),
  `invoice_state` (All, Not yet invoiced, Invoiced unpaid, Paid), `auto` (All, Will renew, Will not renew).
- **Rows.** One per item per due date: type, client, item (service name and domain, add-on, or domain name), product,
  cycle, due date (AD, BS), days until due (negative if already past due and not yet renewed), renewal amount (ex
  tax, after recurring promotions and the group discount, as the renewal invoice would price it), currency, will
  renew (yes, or the reason: do-not-renew, terminate at period end, cycle limit reached, domain auto-renew off),
  renewal invoice (number and status, or "not yet issued", with the date it will be issued from `next_invoice_date`),
  card or wallet token on file (boolean, from `pay_methods`), gateway.
- **Totals.** Per currency: count, expected renewal value ex tax, split into "will renew" and "will not renew". With
  Nepal VAT on, a second column "with VAT estimate" at current rates (SHOULD, label "estimate").
- **Domains.** Domains the clients register themselves (`.np`, `tlds.registered_by_client`, auto-renew off by
  migration 2026_10_23) are not billed by the host; they are excluded from this report. A domain lists its
  `next_due_date`; `expiry_date` is shown as a second column because the registrar expiry and the billing due date
  differ.
- **Query approach.** Three branch queries (services, add-ons, domains), each a range filter on its
  `(status, next_due_date)`, merged in PHP only for the page window using keyset order `(due_date, type, id)`.
  `count()` and `totals()` are one aggregate per branch (three, plus one for invoice state counts). The renewal
  invoice status is a single `whereIn` on `invoice_items(type, billable_id)` for the page's items (not per row).
  Amounts use `recurring_amount` (already net of promotion cycles) and `renewal_discount`, never a per-row call
  into the pricing engine; items with a promotion ending inside the window show the post-promotion amount only if the
  stored fields give it directly, otherwise the current amount with a "promo ends" marker.
- **Indexes.** `services(status, next_due_date)` exists. Add `domains(status, next_due_date)` and
  `service_addons(status, next_due_date)` (each has a single-column index today).
- **Edge cases.** A monthly item whose period contains several due dates is listed once, at its next due date (the
  `include_projected` option, SHOULD, lists the later cycles with a "projected" marker). Items `suspended` are listed
  with their status and excluded from "will renew" value; `pending`, `cancelled`, `terminated` are not listed. An item
  already invoiced and paid in advance is shown as Paid with the next-cycle date after payment, not as due. Free and
  one-time items are not renewals. Quantity multiplies the amount (`quantity`). Different currencies stay separate. An
  item without a client (should not exist) is not listed and counts toward a warning.

### 5.8 `service_movements` — New and churned services and domains (Reports) — SHOULD

- **Purpose.** How the customer base changed this month, by count. This is the quick view; revenue churn stays in
  `churn` and MRR in `mrr_movements` (`reports.md`).
- **Filters.** Period, `type` (Service, Domain), `product_group`/`tld`.
- **Rows.** One per product (or TLD): opening active count, new, reactivated, churned, imported (baseline), closing
  active count, net change. Footer: sum. A second `clients` layout lists new clients and clients with no active item
  at period end (count and list), using `clients.created_at`.
- **Definitions.**
    - New: first transition into `active` (`ServiceActivated`, `DomainRegistered`, `DomainTransferred` in).
    - Reactivated: back to `active` from cancelled or terminated (not from suspended).
    - Churned: leaves `active` or `suspended` for `cancelled` or `terminated` (and for domains: transferred away or
      expired and not renewed). A cancel then terminate of the same service counts once (the recorder stores the
      previous status). An item that never activated is never churn (`reports.md` AC 11).
    - Imported: services present at import, counted once as a baseline movement on the import date so the opening
      count is right; they are not "new".
- **Invariant (tested).** Opening + new + reactivated + imported − churned = Closing, per row; Closing equals the live
  count of active items when the period ends today.
- **Query approach.** The recorder writes `report_service_movements` (§4). The report is one grouped query over
  `occurred_on` in the period plus one opening count `sum(case ...)` for `occurred_on < from`. Rows are keyed by
  product through a join on a `whereIn` of ids for the page.
- **Edge cases.** Events can arrive twice; `source_event_id` is unique so the second is ignored. A service with no
  recorded history before installation gets a baseline `imported` row from `reports:backfill-movements` (idempotent,
  marks itself approximate when history is unknown). Fraud orders are excluded. Free services are counted (they are
  customers).

### 5.9 `month_close` — Month-close summary and reconciliation (Reports) — SHOULD

- **Purpose.** One page and one PDF for the accountant per BS month: the figures of the other reports, side by side,
  with the differences between them named.
- **Filters.** `month` (BS, default last closed), `currency`.
- **How it works.** A staff member presses "Prepare month close". A job (`ShouldBeUnique` on the period) reads the
  other reports through the registry (`get($key)->totals()`), writes `report_month_close_lines` under a new
  `report_month_close_runs` row, and marks it ready. The `month_close` report then reads the latest ready run of the
  period with one query, so it respects the 12-query budget however many reports it summarises. Preparing again
  makes a new run; older runs stay visible. Nothing is locked: the pack is a record, not a period lock (§13). The
  Asar month is prepared like any other; there is no year-end handling in v1 (§3.1).
- **Sections and lines (per currency, native amounts).**
    1. _Cash:_ payments in, of which deposits, refunds, chargebacks net, fees, net income. By gateway as sub-lines.
    2. _Receivables:_ closing receivables by band and of which in collections (from the AR snapshot of the last day,
       or live if the month is still open). Then, **separately and not added in**, _Pending proformas_ with their
       own total.
    3. _Credit held:_ opening, movements, closing (5.6).
    4. _VAT (Nepal VAT mode):_ sales by tax invoice, credit notes, debit notes, net taxable, net VAT, exempt, export
       (from the VAT register totals).
    5. _Cash to register reconciliation:_ VAT-register gross (invoices − credit notes + debit notes) against cash
       applied to invoices (payments in − deposits) plus credit applied, with named difference lines: timing of
       numbering (partial payments, numbered on first full payment), proforma paid after month end, refunds without a
       note, documents in a currency other than the register's reporting currency (which the register converts with
       its own stored rate, outside this pack), and "unexplained" (should be zero; shown if not, never hidden).
    6. _Base:_ new and churned counts (5.8), renewals due next month (5.7) count and value.
- Each line stores `source_report` and `source_filters` so the screen links to the underlying report at the same
  period. Exports write the lines as rows `section, label, currency, amount, amount2` and use the `summary` PDF.
- **Unavailable inputs.** If a section's source is unavailable (VAT mode off, the AR snapshot missing), the section
  shows a line saying why instead of zero.

## 6. Staff flow at month end

1. On the 1st to 3rd of the next BS month the staff member opens Reports; the last closed BS month is preselected.
2. Prepare month close. Read section 5 (reconciliation); open any line to the detail report.
3. Fix what the reconciliation names (an unnumbered refund, a missing credit note, a payment recorded in the wrong
   month) in the normal Billing screens, then prepare again.
4. Export the month-close PDF and the detail CSVs for the accountant: aging, income by gateway, refunds, VAT
   register and credit-note register (these two already exist).
5. Before the 25th, the accountant files the VAT return from the register (vat-register F4).

## 7. Admin and client capabilities

- Admin only. No client-area report.
- Staff with a report's `view` permission see its screen and page through it; with `export`, they export. The
  library page lists only reports the viewer can open (PR 1).
- Saved filters, scheduled and emailed reports, and the report builder are out of scope (§13).

## 8. Automation

- `reports:snapshot-ar` daily 00:20 company time, after the daily run; idempotent; there is no backfill command
  (history is not reconstructable, §5.1). **SHOULD**
- `reports:backfill-movements` once after install of PR 7; idempotent. **SHOULD**
- Movement recorder: queued listeners (ADR 0020 decision 1) on `ServiceActivated`, `ServiceImported`,
  `ServiceCancelled`, `ServiceTerminated`, `DomainRegistered`, `DomainTransferred`, `DomainTransferredAway`,
  `DomainExpired`, `DomainStatusChanged`, idempotent on `source_event_id` (the event's occurrence id, ADR 0011).
  **SHOULD**
- `PrepareMonthClose` job on the `default` queue, unique per period. **SHOULD**
- Retention: month-close runs and AR snapshots are kept six years (Rule 23(7) of the VAT Rules, as vat-register F1
  reports it); movements are kept indefinitely (small rows).

## 9. Events emitted and consumed (`app/Events`)

- Emitted: none new. The export pipeline's `report.exported` activity entry applies (ADR 0025 §5). `reports.md` §9
  lists `ReportExported` and `MrrSnapshotCompleted`; this pack would add `MonthCloseReady(periodKey, runId,
  preparedBy)` to `app/Events/Reports` only if a later notification (email the accountant) needs it; v1 does not, so
  no kernel event is added. **COULD**
- Consumed (by the movement recorder): the Services and Domains events above, and `ClientRegistered` for the clients
  layout (optional; `clients.created_at` is enough).

## 10. Integration with other modules (ADR 0008 contracts)

- Billing-owned reports read only Billing tables plus `gateways` names. Client names come from `ClientDirectory`
  (an existing kernel read contract, bulk form) and currency data from `CurrencyDirectory`.
- The Reports module reads `services`, `service_addons`, `domains`, `invoices`, `invoice_items`, `pay_methods`
  through their models, the exception in `architecture.md` for the read model. It calls Billing's reports only through
  `ReportRegistry`. No Billing or Services code calls Reports.
- Nepal VAT facts (mode on, `enabled_from`) are read through `NepalVatMode` via its contract; the reports do not
  re-derive them.
- No new provider contract. The company period helper is a kernel support class (§16).

## 11. Edge cases (cross-cutting)

- **Multi-currency.** Totals per currency; no conversion in v1 (Q6). A client with invoices in two currencies
  appears twice.
- **Refunds.** In the month paid, not the original month. A refund to credit moves no cash. Cash-basis income can be
  lower than invoice-basis revenue in a month with many refunds; both are labelled by basis.
- **Credit notes in VAT mode.** A note reduces output VAT in its own period (F5). `refunds_register` shows the note
  number; `month_close` reconciles. A note on an invoice that was paid by credit shows in the register, not as cash
  out.
- **Proforma versus tax invoice.** See §3.6. A proforma paid after month end belongs to the later month's register
  and the later month's cash; `month_close` lists it as a named timing line, not as an error.
- **Partial payments and numbering.** An invoice is numbered on its first full payment; cash received earlier in the
  month is in income but not yet in the register. Named line in the reconciliation.
- **Deleted gateways, clients, products.** Rows keep the stored key or a "(deleted)" label; totals are unchanged.
- **Time zone.** Everything buckets in company time (`CompanyClock`); a payment at 23:50 on the last day belongs to
  that day. Nepal has no DST, but the code must not assume it.
- **Fiscal year boundary.** The last BS month of the fiscal year (Asar) and the first (Shrawan) are ordinary months in
  these reports; the `year` filter spans them. No special handling in v1 (Q1).
- **Large result sets.** Aging detail and refunds over the sync cap are refused with "narrow the filters" until the
  queued export exists.
- **Zero periods.** Every report shows an empty state with the period and "No activity", and the CSV has headers only.
- **Imported data.** Historic transactions imported from WHMCS carry the original gateway key and date; the importer's
  own rows are normal rows. Movement history before import is the baseline row.

## 12. Devanagari in PDF — known gap, and the plan

- **State.** dompdf has no Devanagari glyphs and does no OpenType shaping (ADR 0025, Known limitations; owner
  decision 2026-10-23: no font and no engine change). Labels and numbers in this pack are English and digits, so the
  only exposure is user-typed text, mainly the client name column in aging, refunds and credit balances. The CSV is
  unaffected.
- **Why a font alone is not enough.** Adding a Devanagari font to dompdf gives glyphs but not conjunct and vowel-sign
  reordering, so Nepali words would print wrong in a way a reader can see. The CEO review's "cheap font" would
  therefore produce incorrect text, not correct text. (This is from the engine's documented behaviour, not measured
  here; the spike in PR 10 measures it.)
- **In this pack (SHOULD).** The PDF writer's client columns print the client number plus the name, and when the
  name holds characters the PDF cannot draw, the cell prints the Latin part (company or transliterated contact name if
  present) or the client number, with a footnote "Full name in the CSV". No box glyphs appear in a PDF a host hands to
  an accountant.
- **Real fix (separate, owner approval, ADR 0007).** Spike a shaping-capable renderer (an Indic-capable PDF library,
  or headless Chromium) behind the existing `ExportWriter` seam, so no report changes. This and Nepali UI strings
  belong to ADR 0028 (frontend translations, Proposed), which recommends comparing Gotenberg and WeasyPrint as a
  separate PDF service; the PR 10 spike follows its decision on fonts and numerals.

## 13. Out of scope

Period locking and a "close the books" state; year-end (Ashad/Shrawan) handling; purchase and input VAT; the full VAT
return; currency conversion and base-currency totals (until the owner decides, Q6); configurable aging bands (later,
Q4); bank and gateway settlement reconciliation (settlement date, payout net of fee); scheduled or emailed reports;
saved filters; a custom report builder; the MRR/ARR/forecast family (still `reports.md`); XLSX (D4); ticket and staff
reports; client-area reports; India GST reports (later; the filters and currency handling carry over); Nepali UI and
Devanagari numerals; comparative charts (COULD, later).

## 14. Acceptance criteria

Test names use the key and number (for example `OutstandingAgingTest::ag_03`). Each report also passes
`ReportConformance::assertPages()` and `assertQueryBudget()` (CF).

**Shared**

1. CF-1 MUST: Every report in this pack passes `assertPages()` and `assertQueryBudget()` and appears in the arch test.
2. CF-2 MUST: Given no period filter, the report uses the last closed BS month on the company calendar and the export
   records that period.
3. CF-3 MUST: Given a payment at 23:50 Nepal time on the last day of a BS month, it is in that month in every
   report; at 00:05 the next day, in the next.
4. CF-4 MUST: Given NPR and USD rows, every footer and PDF total has one line per currency and none adds them.
5. CF-5 MUST: Given a range longer than 366 days, the filter is refused with a validation error.
6. CF-6 MUST: Given staff without `reports.{key}.view`, the screen is 403; without `.export`, export is 403.
7. CF-7 MUST: Given a CSV export, a client named `=HYPERLINK(...)` starts with `'`, a negative money cell is not
   prefixed, and the file starts with a BOM.
8. CF-8 MUST: Every export writes one `report.exported` entry with the period filters.
9. CF-9 SHOULD: Each date cell has a "Date (BS)" text column beside it, correct against the `BikramSambat` tables.
10. CF-10 MUST: On the 50,000-client seed, each report's first page responds in under 1 s on PostgreSQL.

**`outstanding_aging`**

11. AG-1 MUST: Given a numbered invoice total 100.00 with 40.00 paid, due yesterday, then balance 60.00 is in band
    1–30 and the receivables overdue count is 1.
12. AG-2 MUST: Given invoices due today, 30, 31, 60, 61, 90 and 91 days ago, then each falls in the right band
    (today in "Not yet due", 30 in 1–30, 31 in 31–60, and so on), counted from the due date.
13. AG-3 MUST: Given statuses draft, cancelled, paid and refunded, none is in the report.
14. AG-4 MUST: Given a `collections` invoice, it is in its age band and also in the "In collections" line of the
    receivables totals.
15. AG-5 MUST: Given an unpaid invoice with only a proforma number, it is listed in the "Pending proformas" section
    and not in the receivables rows.
16. AG-6 MUST: Given pending proformas of 500.00 and receivables of 300.00 in one currency, the receivables total
    is 300.00, the pending proformas total is 500.00, and no figure shows 800.00.
17. AG-7 MUST: Given `as_of` in the past with a snapshot, both sections' totals equal the snapshot; with no snapshot
    the report refuses the date and names the nearest day.
18. AG-8 MUST: Given the snapshot job runs twice for one day, one row per `(as_of, currency, section, bucket)`
    exists.
19. AG-9 MUST: Given a client with NPR and USD invoices, two summary rows; totals per currency.
20. AG-10 MUST: Given `min_days_overdue = 31`, only invoices 31+ days overdue are listed and the totals agree.
21. AG-11 MUST: Band edges use the company date, not the database date (test with the company clock 5h45 ahead of UTC).
22. AG-12 SHOULD: With proforma mode off, the pending-proformas section is empty and hidden, and the summary layout
    fits A4 landscape without truncating the band columns.

**`income_monthly`**

23. IM-1 MUST: Given payments 100.00 and 50.00 and a 20.00 refund in one month, income = 130.00 (`reports.md` AC 1).
24. IM-2 MUST: Given a 100.00 payment last month refunded this month, last month stays 100.00 and this month shows
    −100.00 (AC 2).
25. IM-3 MUST: Given 30.00 of credit applied to an invoice, payments in is unchanged (AC 4).
26. IM-4 MUST: Given an `add_funds` payment of 500.00, it is in payments in and in "of which deposits".
27. IM-5 MUST: Given a chargeback of 80.00 and a reversal of 80.00 in later months, each month shows its own effect.
28. IM-6 MUST: Given a failed and a pending transaction, neither is counted.
29. IM-7 MUST: Given a BS fiscal year, 12 month rows appear in order from Shrawan, each with its AD range.
30. IM-8 MUST: Fees reduce net income, not income; `manual_expense` reduces net income.
31. IM-9 MUST: Given NPR and USD payments in one month, there are two rows, each in its own currency, and no
    converted column or base-currency total exists.
32. IM-10 MUST: The one-year view runs at most 12 queries and no per-month query.

**`income_by_gateway`**

33. IG-1 MUST: Given payments through eSewa, Khalti and bank transfer, one row per gateway and currency with the
    right counts and sums.
34. IG-2 MUST: Given a deleted gateway with past payments, its row shows the key and "(deleted)".
35. IG-3 MUST: Shares per currency sum to 100% within rounding and the rounding is displayed.
36. IG-4 MUST: Given the `gateway` filter, only that row and totals appear.
37. IG-5 MUST: Given a refund to credit (no cash out), gateway income is not reduced (pending CTO confirmation, Q10).
38. IG-6 SHOULD: The month's income by gateway sums to the same figure as `income_monthly` for that month and currency
    (tested as an invariant).

**`refunds_register`**

39. RF-1 MUST: Given a 20.00 refund on a 50.00 payment, a row shows the amount, the original payment date, the invoice
    number and the mode.
40. RF-2 MUST: Given Nepal VAT mode and a refund with a credit note, the note number and date are shown; without a
    note the row is flagged and `without_note` finds it.
41. RF-3 MUST: Given a refund of account credit (`credit_refund`), it is not flagged as missing a note.
42. RF-4 MUST: Given a chargeback and its reversal, both are listed, and the net is correct per currency.
43. RF-5 MUST: Given an unsettled refund reservation, it is not a row, not in totals, and the warning count is 1.
44. RF-6 MUST: Given VAT mode off, the note columns are absent.
45. RF-7 MUST: Given a prior-year payment refunded this month, it is in this month.

**`credit_balances` and `credit_movements`**

46. CB-1 MUST: Given ledger rows with balance_after 40.00 then 10.00 on different days, `as_of` between them shows
    40.00 and after shows 10.00.
47. CB-2 MUST: The total per currency equals the sum of the closing balances (invariant test on a seeded ledger).
48. CB-3 MUST: Given a client with a zero balance at `as_of`, no row.
49. CB-4 MUST: Given a negative balance, the row is listed and flagged.
50. CB-5 MUST: Given the cutoff at the end of a company day, a ledger row created at 23:59 local counts for that
    day.
51. CM-1 SHOULD: Opening + Σ movements = Closing per currency, and Closing equals `credit_balances` for the last day.
52. CM-2 SHOULD: A refund to credit appears as `refund_to_credit` here and as mode `to_credit` in the refunds register.

**`renewals_due`**

53. RN-1 MUST: Given the default filters, the next BS month's items are listed and a service due the day after the
    month's end is not.
54. RN-2 MUST: Given a monthly service due in the window, a domain with auto-renew on, and an add-on, one row each
    with the right type.
55. RN-3 MUST: Given a domain registered by the client (`registered_by_client`), it is not listed.
56. RN-4 MUST: Given a service set to not renew, it is listed as "Will not renew" and excluded from the "will renew"
    total.
57. RN-5 MUST: Given a suspended service, it is listed with its status and excluded from the "will renew" total.
58. RN-6 MUST: Given a renewal already invoiced and unpaid, the row shows the invoice number and status; given one
    paid, the state is Paid.
59. RN-7 MUST: Given quantity 3 and a recurring amount 10.00, the amount is 30.00.
60. RN-8 MUST: Given two currencies, totals are per currency.
61. RN-9 MUST: Given `invoice_state = Not yet invoiced`, only those rows and a matching total.
62. RN-10 SHOULD: Given VAT mode on, the estimate column shows amount plus tax at current rules and says "estimate".
63. RN-11 MUST: The page runs the three branch queries plus one invoice lookup, not a query per item.
64. RN-12 SHOULD: Given `include_projected` and a monthly service in a 90-day window, three rows, two marked projected.

**`service_movements`**

65. SM-1 SHOULD: Opening + new + reactivated + imported − churned = closing for every row, and closing equals the
    live active count for a period that ends today.
66. SM-2 SHOULD: Given a service cancelled then terminated, one churn.
67. SM-3 SHOULD: Given a pending service cancelled before activation, no churn.
68. SM-4 SHOULD: Given the same event delivered twice, one row.
69. SM-5 SHOULD: Given a suspended then unsuspended service, no movement.
70. SM-6 SHOULD: Given imported services, they are the baseline, not "new".

**`month_close`**

71. MC-1 SHOULD: Given a prepared run, the screen shows every section from one stored run in at most 12 queries.
72. MC-2 SHOULD: Given VAT mode on, the cash-to-register reconciliation's named lines plus "unexplained" equal the
    difference exactly; a seeded period with no differences shows "unexplained" 0.00.
73. MC-3 SHOULD: Given VAT mode off, the VAT sections say so instead of showing zeros.
74. MC-4 SHOULD: Preparing again adds a run and leaves the first readable and unchanged.
75. MC-5 SHOULD: Given two staff pressing "Prepare" together, one run is created.
76. MC-6 SHOULD: Each line links to the source report at the same period, and the pending-proformas total is shown
    apart from the receivables total.

**Devanagari**

77. DV-1 SHOULD: Given a client named in Devanagari, the PDF shows the client number or Latin name and the footnote,
    with no box glyphs; the CSV shows the full name.

## 15. Owner questions

Decided by the owner on 2026-10-29:

- **Q1 (decided 2026-10-29): close calendar.** Hosts close by BS month. The default period is the last closed BS
  month. The fiscal-year close at Ashad/Shrawan is noted (§3.1) and gets no special handling in v1.
- **Q3 (decided 2026-10-29): proformas in aging.** Unpaid proformas appear in aging in their own "Pending proformas"
  section with their own total, excluded from the receivables total (§3.6, §5.1).
- **Q4 (decided 2026-10-29): aging bands.** 1–30, 31–60, 61–90 and 91+ days from the due date, plus "Not yet due".
  Configurable later, not in v1.
- **Q6 (decided 2026-10-29): rates (D6).** Income stays native-currency only. No conversion, no converted column and
  no stored rate until the owner decides the rate rule.

Accepted by the CTO on 2026-10-31 (the assumed workflows below stand): Q2, Q5, Q7, Q8, Q10 and Q12. Q9 is decided
(the aging detail is a sensitive export with the password step-up). Q11 goes to the accountant list
(`docs/handoff/accountant-questions.md`); the stated default stays.

Assumed workflows:

- Q2 (gateway payouts): eSewa and Khalti pay out to the host's bank a day or two later, net of their fee. Staff
  reconcile by payment date, Mohar records the gateway fee on the transaction, and the host does not need
  settlement-date reconciliation in v1.
- Q5 (renewals window): The useful default is "next BS month"; staff contact clients by phone or SMS 7 to 15 days
  before due; domains and services are both on the list.
- Q7 (refund practice): Refunds go back by bank transfer or the wallet's own reversal and always carry a credit note
  in VAT mode (A4); the accountant checks the refund list against the credit-note register each month.
- Q8 (credit as liability): Prepaid credit is a customer advance in the books (not income, not a VAT sale; A3), and
  the accountant wants the closing balance per month.
- Q9 (confidentiality, decided 2026-10-31): The aging detail lists client names and amounts owed, so it is a sensitive
  export and needs the password step-up.
- Q10 (refund to credit and gateway income): A refund moved to the client's credit is not reported as money returned
  by the gateway. (CTO to check the code path; owner to confirm the reporting.)
- Q11 (income ex VAT): The owner wants cash income as received (VAT included) in this pack; the VAT register
  supplies the sales ex VAT. If the accountant needs an ex-VAT cash split, it is an extra column per payment.
- Q12 (period close): The host does not need the system to refuse changes to a closed month in v1; the prepared
  month-close run is a record, and later corrections appear in the next month.

## 16. Contract changes the spec implies

- **Kernel (CTO session, PR 0):** `App\Support\Reports\CompanyPeriod` (the BS month, BS fiscal year and AD range
  parsing and the default last-closed month) extracted from `RegisterPeriod`; `RegisterPeriod` keeps its API as a
  wrapper, so no existing report changes. A kernel changelog fragment is needed. Optional: `ReportFilter` builders for
  `currency` and `gateway` selects (options read through `CurrencyDirectory` and `GatewayDirectory`), as helper
  functions in `App\Support\Reports`, not new contracts.
- **Billing schema:** only the `credit_ledger(created_at)` index (and, if EXPLAIN shows a need, the open-invoice and
  income partial indexes of §5.1 and §5.2). **No `transactions` change in v1** (Q6): the `rate_to_default` column and
  its backfill are dropped until the owner decides the rate rule. Issue #45 stays open for that later step.
- **Reports schema:** the three tables of §4; indexes on `domains(status, next_due_date)` and
  `service_addons(status, next_due_date)`.
- **No change** to the `Report` contract, `ReportExporter`, `ExportFormat`, `ColumnType` or the event contracts. No
  new event is required in v1.
- **Docs:** `reports.md` §6 gets a note that the rows `overdue_invoices` and `credits` are replaced by this spec.

## 17. Out of scope

See §13.

## 18. Sources (all read 2026-10-29)

- WHMCS documentation, "Reports" (system section), the second half of the page: built-in report names and export
  options (CSV and a printer view). `docs.whmcs.com/8-13/system/reports/`. The first half was navigation only; the
  billing-section URL tried first returned 404.
- Mohar: `billing/docs/product/reports.md`, `vat-register.md`, `billing.md`; ADR 0020 and its Amendment (#515), ADR
  0024, ADR 0025 and its amendments (#428, #515); `docs/reviews/2026-10-29-ceo.md` and `2026-10-29-whmcs-parity.md`;
  code on `develop` at `88601f81`: `app/Modules/Billing/Reports/*`, `app/Support/Reports/*`, the billing, services
  and domains migrations, `app/Events/*`.
- Nepal VAT rules and the IRD sales-book facts are taken from `vat-register.md` §1 (F1 to F10, read 2026-10-22,
  secondary sources; the IRD site did not answer then). India GST is not used in this pack.
- Owner decisions Q1, Q3, Q4 and Q6: relayed by the coordinator, 2026-10-29.
- Not read: Blesta, FOSSBilling and HostBill report libraries were not fetched in this pass; claims about them are
  not made here.

## 19. PR plan (small PRs)

Each PR runs the full gate set, adds its conformance test, and carries a changelog fragment.

| PR  | Scope                                                                                                                   | Owner          | Depends on     |
| --- | ----------------------------------------------------------------------------------------------------------------------- | -------------- | -------------- |
| 0   | Kernel: `CompanyPeriod` extracted from `RegisterPeriod`; currency and gateway filter helpers; kernel-changelog fragment | CTO session    | none           |
| 1   | Reports module skeleton: library page, nav under Reports, category grouping, permission group, shows registered reports | Reports        | 0              |
| 2   | `outstanding_aging` (summary and detail, receivables and pending proformas), partial index if needed                    | Billing        | 0              |
| 2b  | `report_ar_daily` and `reports:snapshot-ar`; past `as_of` in aging                                                      | Billing        | 2              |
| 3   | `income_monthly` and `income_by_gateway` (native currency only)                                                         | Billing        | 0              |
| 4   | `refunds_register`                                                                                                      | Billing        | 0              |
| 5   | `credit_balances`, `credit_movements`, `credit_ledger(created_at)` index                                                | Billing        | 0              |
| 6   | `renewals_due` with the two domain and add-on indexes                                                                   | Reports        | 1              |
| 7   | Movement recorder, `report_service_movements`, backfill, `service_movements` and clients layout                         | Reports        | 1              |
| 8   | `month_close` (tables, job, report, PDF) and reconciliation lines                                                       | Reports        | 2b, 3, 4, 5, 6 |
| 9   | PDF Devanagari fallback in the writer (client number or Latin name plus footnote)                                       | Kernel/Reports | none           |
| 10  | Spike: shaping-capable PDF renderer behind `ExportWriter`; needs owner approval and ADR 0028                            | Architecture   | ADR 0028       |

PRs 2, 3, 4 and 5 can run in parallel after PR 0; 6 and 7 in parallel after PR 1. The first usable month-end for a
host is PRs 0, 2, 3 and 4 (aging, income, refunds, with the existing VAT registers). A later PR adds currency
conversion once the owner decides Q6.
