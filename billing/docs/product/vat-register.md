# VAT sales register and credit notes — Product Spec (v1)

Status: draft for engineering · Owner: Billing workstream · Refines `billing.md` §2 (invoice_adjustments), §3, §4.7 and
`reports.md` §6 (`tax_liability`) · Sources read 2026-10-22 (§17) · CEO review 2026-10-22, recommendation 4
Clean-room: behaviour derived from public tax guidance and public category docs (WHMCS, HostBill, Blesta forums); no
vendor text copied; naming and schemas are ours.

## 0. Why one new file

Credit notes and the sales register are one feature for the user: a VAT-registered Nepali host closes the month from
the register, and the register is only right if every change to an issued tax invoice is a numbered note. Splitting
them across `billing.md` (documents) and `reports.md` (read-only metrics) would put the two halves of one tie-out in
different specs. The register is owned by Billing, not Reports, because it reads Billing's own documents, needs the
Bikram Sambat (BS) code in `App\Modules\Billing\Support`, and is a compliance document rather than a metric.
`billing.md` and `reports.md` get one pointer line each (this PR).

## 1. Key facts that shape the design

Confidence: **high** = template or several independent sources agree; **medium** = one practitioner source;
**assumption** = our reading, not confirmed. Government sites (`ird.gov.np`, `lawcommission.gov.np`) did not answer
from our network on 2026-10-22, so no rule text was read first-hand (§17, "Not confirmed").

| #   | Fact                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Confidence                                     |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| F1  | The VAT Rules (Rule 23(1)) require a sales book (बिक्री खाता) in a prescribed schedule, alongside the purchase book, copies of tax invoices, and all credit and debit notes. Records are kept six years (Rule 23(7)).                                                                                                                                                                                                                                                                                                    | high                                           |
| F2  | The prescribed sales-book layout (IRD template, Rule 23(1)(ज)) has a header with the taxpayer's PAN, name, year and tax period, and these columns: date; invoice number; buyer name; buyer PAN; goods or service name; quantity; unit; total sales or export (Rs); locally exempt sales (Rs); taxable sales value (Rs) and tax (Rs); export value (Rs); export country; export declaration number; export declaration date.                                                                                             | high (template)                                |
| F3  | Credit and debit notes (Rule 20(1)) must be marked as such, carry a sequential number and issue date, name both parties with registration numbers, cite the related tax invoice's number and date, describe the goods or services and the reason, and state the amount and the tax credited or debited. Rule 20(2) asks for a monthly account of the notes.                                                                                                                                                             | medium (two practitioner sources agree)        |
| F4  | The VAT tax period is the BS month; the return (Schedule 10) is due within 25 days of the month's end; nil returns are required. Some small taxpayers may file four-monthly. The fiscal year runs 1 Shrawan to the end of Asar.                                                                                                                                                                                                                                                                                         | high                                           |
| F5  | A note adjusts output VAT in the period the note is issued, not the period of the original invoice.                                                                                                                                                                                                                                                                                                                                                                                                                     | medium                                         |
| F6  | The tax invoice must say "Tax Invoice" (कर बीजक) and carry the seller's PAN, buyer's name, address and PAN, number, date, lines, taxable value, VAT and total. Practitioner guides show the date in BS.                                                                                                                                                                                                                                                                                                                 | high (fields) / medium (BS on the invoice)     |
| F7  | Software billing rules (Electronic Billing Procedure 2074): data cannot be edited or deleted once entered, corrections are new entries, cancelled bills stay with a reason, returns go through credit notes, every record carries date, time and user, reprints are marked as copies with a print count, data exports to Excel or PDF.                                                                                                                                                                                  | medium (one source; matches CBMS field names)  |
| F8  | Real-time reporting to CBMS (Central Billing Monitoring System) is mandatory above an annual turnover of Rs 20 crore (IRD decision published about 17 April 2026). The FY 2083/84 budget announced Rs 10 crore; no IRD notice putting it into force was found as of early October 2026. An older notice set Rs 5 crore for hotels, restaurants and **internet service providers**. Below the threshold, billing by software needs the tax office's permission. Cloud-hosted billing data must sit on a server in Nepal. | medium (sources disagree on the current line)  |
| F9  | CBMS takes one bill per post (fiscal year as `2083.084`, BS date `YYYY.MM.DD`, buyer PAN, total, taxable, VAT, exempt, export) and one credit note per post (`ref_invoice_number`, note number and date, reason, amounts). It rejects a duplicate bill number but accepts a duplicate credit note.                                                                                                                                                                                                                      | medium (third-party client and a vendor guide) |
| F10 | India GST (later market): a credit note reduces output tax only if declared by 30 November after the financial year of the supply, or the annual return date if earlier (CGST s.34).                                                                                                                                                                                                                                                                                                                                    | high                                           |
| F11 | Category: WHMCS has no native credit notes (third-party add-ons fill the gap; the feature request has been open since 2015); its Sales Tax Liability report shows subtotal, tax and total for a period and leaves partial refunds out. HostBill has credit notes with their own sequence and per-item refunds.                                                                                                                                                                                                          | high                                           |

What this means for us: an issued tax invoice is a legal document that is never edited, and the only way to reduce
or increase it is a numbered note in the period it is issued. Today Mohar cancels invoices and cancels lines with
**unnumbered** credit adjustments (`CancelInvoice`, `CancelInvoiceItems`) and refunds with a `refund` adjustment.
That is right for the balance but invisible to the tax register. This spec turns those adjustments into numbered
documents when they change a tax invoice.

**Better than the category:** WHMCS leaves credit notes to add-ons and its tax report ignores partial refunds; Mohar
makes the credit note the only correction path and ties the register to the notes, so register totals always equal
"invoices minus credit notes plus debit notes" for the period, in BS months, with the buyer PAN in place.

## 2. Purpose and scope

In scope:

- Numbered **credit notes** and **debit notes** against one issued tax invoice each, full or partial, by line or by
  amount, with PDF, email and client-area view.
- The **settlement** of a credit note: reduce the open balance, credit the account, refund to the original method, or
  record a refund made outside Mohar.
- Automatic notes when a numbered tax invoice is cancelled (staff or automation) or has lines cancelled, and debit
  notes for late fees added to a tax invoice.
- The **VAT sales register** (monthly by BS month, annual by BS fiscal year, any AD range) and the **credit and debit
  note register**, on screen and as CSV and PDF exports, with totals that feed the monthly return.
- "Nepal VAT mode" (§3) that switches these on for a Nepal seller registered for VAT.

Out of scope: §16.

## 3. Nepal VAT mode and which document is the tax invoice

- `billing.nepal_vat.enabled` (Admin → Settings → Billing → Tax; on by default when the seller country is NP and a
  seller VAT number is set; the setting page says what it changes). When on: §4–§9 apply; numbered notes are
  mandatory for every change to a numbered invoice; the register is shown. When off, credit notes stay available
  (`billing.credit_notes.enabled`, default on) and the register is shown as the generic `tax_liability` report. **MUST**
- **Tax invoice moment.** A hosting invoice is usually raised 14 days before the due date and many are never paid. If
  the legal number is assigned at publish, every unpaid renewal is a sale in the register and needs a credit note when
  it is cancelled. Mohar already has proforma mode (`billing.sequential_paid_numbering`): unpaid invoices carry a
  proforma number and become tax invoices, with the legal number and issue date, when paid. Owner decision D1
  (2026-10-22): an invoice becomes a tax invoice on payment, so turning Nepal VAT mode on turns proforma mode on
  (`sequential_paid_numbering` and `set_issue_date_on_payment` true). Staff can switch proforma mode off, and the
  setting page says that every unpaid invoice then becomes a sale needing a credit note when cancelled. **MUST**
- Rule used everywhere below: a **tax invoice** is an invoice with a legal `number` (not a proforma number, not a draft).
  Only tax invoices appear in the register and only they take notes. A proforma invoice is cancelled without a note.
  **MUST**
- Add-funds invoices are deposits, not supplies: never in the register, never credited by a note (their refunds stay
  as in `billing.md` §4.8). VAT is accounted when credit pays a tax invoice. **MUST** (assumption A3)

## 4. Entities and key fields

### 4.1 `tax_notes` (one table for credit and debit notes)

`id, kind (credit|debit), number (unique, never null), sequence_period, invoice_id (required), client_id, currency,
issue_date (company tz date), issued_at (timestamp), invoice_number (snapshot), invoice_issue_date (snapshot),
reason_code (enum, below), reason_text (client-visible, required, ≤ 500), taxable_amount, exempt_amount, tax1_name,
tax1_rate, tax1_amount, tax2_name, tax2_rate, tax2_amount, total (all int minor, positive; kind gives direction),
npr_rate (decimal 18,8, copied from the invoice, §4.4), settlement (json, §5.3), seller_snapshot (json), buyer_snapshot
(json: name, company, address, tax_id), bs_issue_date (char 10, `YYYY.MM.DD`), bs_fiscal_year (char 8, `2083.084`),
bs_period (char 7, `2083.07`), created_by, print_count (int, default 0), cbms_status (nullable, reserved, §11),
cbms_reference (nullable), timestamps`

- `reason_code`: credit — `invoice_cancelled`, `lines_cancelled`, `service_not_delivered`, `price_correction`,
  `duplicate_invoice`, `discount_after_sale`, `goodwill`, `other`; debit — `late_fee`, `price_correction`,
  `reverses_credit_note`, `other`. Codes drive filters and the CBMS `reason_for_return`; the text is what the client
  reads. **MUST**
- One note references one invoice. A correction that spans invoices is several notes. **MUST**
- BS fields are computed once at issue from `issue_date` with `BikramSambat::fromAd` and stored, so a later table
  correction of the BS calendar never moves a filed note between periods. **MUST**
- Snapshots: buyer and seller are copied from the invoice's snapshots, not from the live client, so the note names
  the same buyer PAN as the invoice it corrects. **MUST**

### 4.2 `tax_note_lines`

`id, tax_note_id, invoice_item_id (nullable for an amount-only note), description, quantity (decimal), unit_amount,
amount (net, int minor), taxable (bool), tax_amount, sort_order`

### 4.3 Links to the existing ledger

- Every note writes exactly one `invoice_adjustments` row (`kind` = note kind, new reasons `credit_note` and
  `debit_note`, `tax_note_id` FK, `number` = the note number). The balance formula in `billing.md` §2 is unchanged.
  **MUST**
- The existing reasons `cancellation`, `item_cancellation`, `refund` and `late_fee` are no longer written for a tax
  invoice in Nepal VAT mode; they remain for proforma invoices and for installs with the mode off. `credit_applied`,
  `credit_removed`, `overpayment` and `write_off` never become notes: they move money or settle a balance and do not
  change the value of the supply. **MUST**
- `invoices` gains `credited_total` and `debited_total` (int minor, cached, sums of notes) and a derived label
  "Credited" / "Partly credited" beside the status. No new invoice status. **MUST**

### 4.4 Invoice additions

- `npr_rate`: for an invoice not in NPR, the rate to NPR snapshotted when the legal number is assigned (from the
  install's rate table, `ExchangeRateSource`). The register converts with it; notes reuse it. NPR invoices store 1.
  Which rate a Nepali host must use is D6. **MUST**
- `print_count` on invoices and notes (§9). **SHOULD**

### 4.5 Numbering

- New sequences in `DocumentNumbers`: `credit_note` and `debit_note`, each gap-free, locked `FOR UPDATE`, consumed in
  the same transaction that writes the note. There are no draft notes: staff see a preview, confirm, and the note is
  numbered on commit. **MUST**
- Formats `billing.credit_note_format: "CN-{NUMBER}"`, `billing.debit_note_format: "DN-{NUMBER}"`, own padding, reset
  `never` by default. Never shared with the invoice sequence (a shared sequence leaves gaps in invoice numbers, the
  known flaw of the WHMCS add-on approach). **MUST**
- Tokens `{BS_YEAR}`, `{BS_FY}` (`2083-84`) and reset `bs_fiscal_year` for invoices and notes alike. **SHOULD**, built
  only after the owner's answer on fiscal-year numbering (D2).

## 5. Flows

### 5.1 Issue a credit note (staff)

1. From a tax invoice: "Issue credit note". Choose **by line** (each open line with quantity or amount up to what is
   left to credit on it) or **by amount** (one gross amount). **MUST**
2. Tax comes from the invoice's own snapshotted rates and inclusive/exclusive mode, never from current tax rules. By
   line: per-line tax prorated from the line's tax. By amount: split across taxable and exempt value, and across tax
   levels, in the invoice's proportions. Rounding half-up per line; the note that brings an invoice's credited total to
   its full value takes the exact remainder, so a fully credited invoice nets to zero tax to the paisa. **MUST**
3. Caps: per line, credited ≤ line gross − earlier credits on that line; per invoice, Σ credit notes ≤ total + Σ debit
   notes. Over-cap requests are refused with the amount still creditable. **MUST**
4. Reason code and client-visible text are required. Preview shows the note, the new invoice balance and the
   settlement (§5.3). Confirm requires a recent password (step-up, ADR 0018). **MUST**
5. On commit, one transaction under the lock order payment → invoice → client (ADR 0021): number, note, lines,
   adjustment row, cached totals, settlement postings, activity entry; then `TaxNoteIssued` after commit (§10). **MUST**
6. The client gets the "Credit note issued" email with the PDF, unless staff untick "Send to client". **SHOULD**

### 5.2 Automatic notes

- **Cancel a tax invoice** (staff, bulk, order cancel, or automation step 4 auto-cancel): instead of the unnumbered
  `cancellation` adjustment, a credit note `invoice_cancelled` for the remaining uncredited value is issued, with the
  reason text from the cancel dialog or "Cancelled automatically: unpaid N days after the due date". Credit applied to
  the invoice is returned as today. **MUST**
- **Cancel lines** on a tax invoice: one credit note `lines_cancelled` with one line per cancelled line. **MUST**
- **Late fee** on a tax invoice: a debit note `late_fee` instead of the `late_fee` adjustment, taxable per
  `billing.tax.apply_to`. **MUST**
- **Reopen** a cancelled tax invoice is refused once a credit note exists (already the rule in `billing.md` §2);
  staff use "Reissue" (new invoice). **MUST**
- **Reverse a mistaken credit note**: a debit note `reverses_credit_note` for the same amount and tax, referencing the
  same invoice; the PDF names the credit note it reverses. Notes are never deleted or edited. **MUST**

### 5.3 Settlement of a credit note

The credit first reduces what the invoice still owes; only what exceeds the balance (money already received) needs a
destination. Staff choose it in the preview. Owner decision D3 (2026-10-22): account credit is pre-selected and the
refund options are one click away.

| Invoice state           | Credit up to the balance | Credit above the balance (money already paid)                                                                                                                                                                             |
| ----------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unpaid, no payments     | reduces the balance      | not possible (cap)                                                                                                                                                                                                        |
| Partly paid             | reduces the balance      | account credit, or refund                                                                                                                                                                                                 |
| Paid / payment received | —                        | account credit (`credit_ledger` reason `credit_note`), or refund to the original method (gateway refund, same rules as `billing.md` §4.7), or "refunded outside" (record-only, method and reference required), or a split |

- `settlement` json on the note records each part: `{balance: n, account_credit: n, refunds: [{transaction_id, mode}]}`.
  The parts sum to the note total. **MUST**
- A gateway refund that fails leaves the note issued and the amount as account credit, with a staff to-do "Credit
  note refund failed"; the note itself never rolls back once numbered. **MUST**
- Status: a credit note that brings an unpaid invoice's balance to zero with no payment is a cancellation (§5.2); with
  payments covering the rest, the invoice is `paid` as today. When refunds through notes equal `amount_paid`, the
  invoice is `refunded` as today. **MUST**

### 5.4 Refunds in Nepal VAT mode

- Refunding money that paid a tax invoice changes the sale, so the refund dialog opens the credit-note form
  pre-filled with "refund" settlement. A refund of money that went to account credit (overpayment, deposit) is a plain
  refund with no note. **MUST** (assumption A4)
- A chargeback does not issue a note: the sale stands until staff decide (issue a note if the money is lost for good,
  or leave it if the dispute is won). The "Disputes to review" to-do says so. **SHOULD** (assumption A5)
- "Mark paid" write-offs keep the sale in the register (no bad-debt relief assumed, A6).

## 6. VAT sales register

### 6.1 Rows and basis

- One row per tax invoice whose `issue_date` falls in the period, plus one row per credit note (negative amounts) and
  debit note (positive) whose `issue_date` falls in the period, ordered by date then number. Notes count in their own
  period, not the invoice's (F5). **MUST**
- Basis is the document date, never the payment date or cash. In proforma mode a tax invoice's date is its payment
  date because that is when it was numbered. **MUST**
- Excluded: drafts, proforma-only invoices, add-funds invoices, the zero-amount dispute rows. Cancelled tax invoices
  stay in their own period (the sale was made) and the cancelling credit note sits in its period. **MUST**

### 6.2 Columns (on screen and in exports)

Header block: seller PAN, seller legal name, BS fiscal year, tax period (BS month name and number, or the AD range).

| #   | Column                          | Source                                                                                   |
| --- | ------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | Date (BS)                       | `BikramSambat::fromAd(issue_date)` as `YYYY.MM.DD`                                       |
| 2   | Date (AD)                       | `issue_date`, ISO 8601                                                                   |
| 3   | Document type                   | Tax invoice, Credit note, Debit note                                                     |
| 4   | Document number                 | invoice `number` or note `number`                                                        |
| 5   | Reference invoice               | notes only: invoice number and its BS date                                               |
| 6   | Buyer name                      | snapshot company, else name                                                              |
| 7   | Buyer PAN                       | snapshot `tax_id`; blank when missing (owner decision 10-22: warn, never block)          |
| 8   | Goods or service                | first line description, "+ n more" when several; the full lines are in the detail export |
| 9   | Quantity, 10 Unit               | sum of line quantities and "service" when the lines share one unit; blank otherwise      |
| 11  | Total sales (Rs)                | taxable + exempt + export value, in NPR                                                  |
| 12  | Exempt sales (Rs)               | value of untaxed lines and of tax-exempt clients, NPR                                    |
| 13  | Taxable sales value (Rs)        | taxable net, NPR                                                                         |
| 14  | VAT (Rs)                        | tax1 + tax2, NPR                                                                         |
| 15  | Export value (Rs)               | value of documents classified as export (D6), NPR                                        |
| 16  | Export country                  | buyer country for exports                                                                |
| 17  | Export declaration no. and date | blank for services; editable later only if the owner says hosts record one (A8)          |
| 18  | Currency, amount, rate          | for non-NPR documents: original currency, total and `npr_rate`                           |

- Columns 1, 4, 6, 7, 8, 9–17 map one to one to the prescribed sales-book columns (F2); 2, 3, 5 and 18 are additions
  the template does not have, placed after them in the IRD-layout export (§7). **MUST**
- Money in rupees with two decimals; conversion per document, half-up, then summed (as `reports.md` §2). **MUST**

### 6.3 Periods and totals

- Monthly: a BS month picker defaulting to the last closed BS month. Annual: BS fiscal year (1 Shrawan – end Asar).
  Custom: any AD date range. Monthly is the default view in Nepal VAT mode. **MUST**
- Footer totals: total sales, exempt, taxable, VAT, export, each split into invoices, credit notes and debit notes, and
  the net. A "Return figures" box shows the net taxable sales, net VAT on sales, exempt sales and export sales the
  accountant types into the monthly return, with the note "Sales side only; purchases and input VAT are not in this
  product". **MUST**
- Invariant tested on every period: net VAT = Σ invoice VAT − Σ credit-note VAT + Σ debit-note VAT, exactly in paisa.
  **MUST**
- Annual view: one row per BS month with the same totals, plus the full detail in the export. **MUST**
- Warnings above the table (not blocking): business buyers without a PAN, documents outside the BS table range, and
  non-NPR documents without a rate. **SHOULD**

### 6.4 Credit and debit note register (Rule 20(2) monthly account)

A second tab with the same periods listing notes only: number, BS and AD date, type, reference invoice number and BS
date, buyer, PAN, reason code and text, taxable, exempt, VAT, total, settlement summary, created by. **MUST**

## 7. Export formats

- **CSV (detail)**: one row per document as §6.2, English headings, UTF-8 **with BOM** by default for this report
  (Excel on Windows otherwise garbles Nepali names; overrides `reports.csv_bom` for this key only), formula-injection
  guard and activity entry as `reports.md` §7. **MUST**
- **CSV (IRD layout)**: the prescribed columns in the prescribed order with the Nepali headings of the template,
  header block rows for PAN, name, year and period, then our extra columns. **MUST**
- **PDF**: A4 landscape through the existing PDF renderer, header block, BS period, page totals and grand totals,
  "Printed by <staff> at <time>", the brand from `BrandProvider`. For a self-certified annual book. **MUST**
- No XLSX. Owner decision D4 (2026-10-22): CSV (detail and IRD layout) plus PDF; XLSX waits until the IRD portal
  needs an upload (§16).
- Large exports (> `reports.sync_export_max_rows`) are queued as in `reports.md` §7. **SHOULD**
- Note PDF: titled "Credit Note" / "Debit Note", seller name, address and PAN/VAT number, buyer name, address and PAN,
  note number, BS and AD date, "Against tax invoice <number> dated <BS date> (<AD date>)", reason, lines, taxable,
  VAT, total, settlement line ("Credited to your account", "Refunded to eSewa", …). **MUST**

## 8. Admin and client capabilities

Admin:

- Invoice page: "Issue credit note" and "Issue debit note" (tax invoices only), a Notes panel listing the invoice's
  notes with links and the remaining creditable amount. **MUST**
- Billing → Credit notes: list with filters (kind, period BS/AD, client, reason, settlement), view, PDF, resend. **MUST**
- Billing → VAT register: §6 with the two tabs and exports. **MUST**
- Client page: the client's notes beside invoices. **SHOULD**

Client area:

- Invoice page lists its notes; Billing → Credit notes lists the client's notes with PDF download. **MUST**
- Clients never create notes. **MUST**

### 8.1 Permissions (registered in `PermissionRegistry`)

| Key                               | Grants                                                         | Sensitive (step-up) |
| --------------------------------- | -------------------------------------------------------------- | ------------------- |
| `billing.tax_notes.view`          | see notes in admin, download their PDFs                        | no                  |
| `billing.tax_notes.issue`         | issue credit and debit notes (settlement to credit or balance) | yes                 |
| `billing.refunds.manage` (exists) | additionally required when a note's settlement refunds money   | yes                 |
| `billing.vat_register.view`       | see the register and the note register                         | no                  |
| `billing.vat_register.export`     | export CSV and PDF                                             | no                  |

- Full Administrator has all; the seeded Billing role gets `view`, `issue`, `vat_register.*`; Support gets none.
  Automatic notes (§5.2) need only the permission of the action that triggers them (`billing.invoices.manage`). **MUST**

## 9. Audit and immutability

- A numbered note is never updated or deleted, except `print_count`, `cbms_status` and `cbms_reference`. The model
  refuses other changes (`ImmutableInvoiceException`), and a PostgreSQL trigger rejects UPDATE of any other column and
  any DELETE on `tax_notes` and `tax_note_lines`, so a console or a future bug cannot alter a filed document. **MUST**
  (model) / **SHOULD** (trigger; architect to confirm triggers fit the conventions)
- The same trigger guard covers the legal fields of numbered invoices (number, issue date, lines, amounts, tax,
  snapshots). **SHOULD**
- Activity log entries (`ActivityLogger`): `billing.tax_note.issued` (number, kind, invoice, total, settlement, staff
  or "automation"), `billing.tax_note.emailed`, `billing.tax_note.printed` (with the new print count),
  and `report.exported` with key `vat_register` or `tax_note_register`,
  period and row count. Viewing the register is not logged. **MUST**
- Print marking: the first PDF download of an invoice or note is the original; later ones carry "Copy of original —
  print n" in the header, in Nepal VAT mode (F7). Admin and client downloads both count. **SHOULD** (A7)
- Retention: notes, invoices and their PDFs are never pruned by any cleanup job; the KYC 90-day deletion never
  touches billing documents. Six years minimum (F1). **MUST**

## 10. Events (`app/Events/Billing`, kernel change made by the CTO session)

Emitted:

- `TaxNoteIssued(int $taxNoteId, int $invoiceId, int $clientId, string $kind, int $total, string $currency, bool
  $automatic)` after commit, once per note. **MUST**
- Existing events keep their meaning: `InvoiceCancelled` still fires on cancellation (after the note),
  `InvoiceModified` when a partial note changes the balance, `InvoiceRefunded` when a note refunds money, and
  `LateFeeApplied` for a late-fee debit note. **MUST**

Consumed by:

- Notifications: "Credit note issued" / "Debit note issued" email with the PDF; webhook events `credit_note.issued`
  and `debit_note.issued`; merge fields `tax_note.*` through `MergeFieldRegistry`. **MUST** (email) / **SHOULD**
  (webhook)
- Affiliates: none; commission reversal stays on `PaymentReversed` and refunds. A credit note without a refund does
  not reverse commission (owner can revisit).
- Services: none. A note never suspends or cancels a service; staff do that separately.

Billing consumes nothing new.

## 11. Integration (ADR 0008 contracts) and CBMS readiness

- `Invoicer` and `InvoiceQueries`: unchanged. Reports reads `tax_notes` and the register query directly from Billing
  (Billing owns the screen), so no new cross-module read contract is needed now.
- `ExchangeRateSource` (existing) supplies `npr_rate` at numbering.
- `BikramSambat` stays in `App\Modules\Billing\Support`; it gains `fiscalYear(DateTimeInterface): string` and
  `period(DateTimeInterface): string`. If another module needs BS dates it moves to `app/Support` (kernel) then.
- **CBMS** is not built (owner decision D5, 2026-10-22). The data model is ready for it: BS date, fiscal year in the CBMS form,
  buyer PAN, the amount split CBMS expects, the reference invoice and reason on notes, and `cbms_status` columns. A
  later driver would post each tax invoice and note through an outbox, retry by invoice number (CBMS rejects a
  duplicate bill), and never retry a note blindly (CBMS accepts duplicate notes, F9).

## 12. Edge cases

- **Invoice in USD to a client abroad**: register converts with the invoice's `npr_rate`; whether it is export
  (zero-rated) or taxable depends on the tax rule that applied, not on the currency (D6).
- **Credit note in a later fiscal year** than the invoice: allowed; it sits in its own BS month and fiscal year and
  cites the old invoice and its BS date.
- **BS date outside the table** (after 2095 BS): the document cannot be issued in Nepal VAT mode; staff see why. Until
  then not reachable in practice.
- **Partial payment then full cancellation**: the credit note covers the full uncredited value; the balance part
  zeroes the invoice, the paid part goes to the chosen settlement.
- **Concurrent notes** on one invoice: the invoice row lock serialises them; the cap is checked under the lock.
- **Credit applied then invoice cancelled**: the applied credit returns to the account (as today) and the note
  records `account_credit: n`, so the account is not credited twice.
- **Tax rule changed** after the invoice: the note uses the invoice's snapshotted rates.
- **Discount lines**: credited with their parent line, never on their own (a negative credit is not a credit note).
- **Rounding**: last-note-takes-remainder keeps a fully credited invoice at exactly zero net and zero tax.
- **Client changes PAN** after the invoice: the note keeps the invoice's buyer snapshot; the register shows the PAN
  as invoiced.
- **Imported invoices** (importer phase 2): imported tax invoices keep their WHMCS numbers and take notes like any
  other; they appear in the register only for periods after the cut-over date (a setting on the import run).
- **Mode switched on mid-year**: existing unnumbered adjustments are shown in the register as "Adjustment (before
  credit notes)" rows in their own period so totals still tie; no back-numbering.

## 13. Acceptance criteria

MUST:

1. Given Nepal VAT mode and a tax invoice of Rs 11,300 (10,000 + 13% VAT), when staff issue a full credit note, then
   it is numbered `CN-1`, references the invoice number and its BS date, and has taxable 10,000.00 and VAT 1,300.00.
2. Given two credit notes issued concurrently on different invoices, then they get consecutive distinct numbers with
   no gap; a failed (rolled back) note consumes no number.
3. Given a tax invoice with lines 5,000 and 6,300 gross, when staff credit only the second line, then the note has one
   line and the invoice's creditable remainder is 5,000.
4. Given a tax invoice of 11,300 already credited 11,000, when staff try to credit 400, then it is refused with
   "300.00 can still be credited".
5. Given three partial credit notes that together credit an invoice in full, then their VAT sums exactly to the
   invoice VAT.
6. Given a tax rule changed from 13% to 15% after the invoice, then a credit note on it uses 13%.
7. Given a paid tax invoice and settlement "account credit", when the note is issued, then the client's credit rises
   by the note total with ledger reason `credit_note` and the invoice stays paid and shows "Credited".
8. Given settlement "refund to original method" on an eSewa or Stripe payment, then a refund transaction linked to the
   note exists; if the gateway refund fails, the amount is account credit and a staff to-do exists, and the note is
   unchanged.
9. Given an unpaid tax invoice cancelled by staff, then a credit note `invoice_cancelled` is issued and no unnumbered
   `cancellation` adjustment is written.
10. Given automation auto-cancels an overdue tax invoice, then a credit note with created-by "automation" is issued and
    `TaxNoteIssued` has `automatic = true`.
11. Given Nepal VAT mode (proforma on, D1) and an unpaid proforma invoice, when it is cancelled, then no note is issued and nothing
    appears in the register.
12. Given a late fee added to a tax invoice, then a debit note `late_fee` is issued and the register shows it as a
    positive row.
13. Given a numbered note, when any code path updates its amount or deletes it, then the change is refused.
14. Given a cancelled tax invoice with a credit note, then "Reopen" is refused and "Reissue" is offered.
15. Given a refund of the invoice part of a payment in Nepal VAT mode, then the refund goes through a credit note; a
    refund of an overpayment held as credit issues no note.
16. Given an invoice dated in Kartik 2083 and its credit note dated in Mangsir 2083, then the Kartik register shows the
    invoice and the Mangsir register shows the note as a negative row.
17. Given a register period, then net VAT equals Σ invoice VAT − Σ credit-note VAT + Σ debit-note VAT exactly, and the
    "Return figures" box shows the same numbers.
18. Given the annual view for 2082/83, then it covers 1 Shrawan 2082 to the last day of Asar 2083 and has 12 month rows.
19. Given a business client without a PAN, then the row exports with a blank PAN and the page shows a warning; nothing
    is blocked.
20. Given a USD invoice with `npr_rate` 134.25, then its register amounts are the USD amounts × 134.25, rounded per
    document, and the original currency, amount and rate are in the extra columns.
21. Given an add-funds invoice, a draft, or a zero-amount dispute row, then none appears in the register.
22. Given the IRD-layout CSV, then its columns are in the prescribed order with the template's Nepali headings and the
    header rows carry seller PAN, name, fiscal year and period.
23. Given a buyer named `=HYPERLINK(...)`, then the CSV cell starts with `'=`; and every export writes `report.exported`
    with period and row count.
24. Given staff without `billing.tax_notes.issue`, then the issue buttons are absent and the endpoint returns 403; with
    it but without a recent password, the confirm step asks for one.
25. Given settlement "refund" and staff without `billing.refunds.manage`, then refund options are not offered.
26. Given a client, then they see and download only their own notes; another client's note URL returns 404.
27. Given a note issued, then an activity entry `billing.tax_note.issued` with number, invoice, total, settlement and
    actor exists.
28. Given the note PDF, then it shows "Credit Note", seller PAN/VAT number, buyer PAN, both dates, the referenced
    invoice and the reason.
29. Given a mistaken credit note, when staff reverse it, then a debit note `reverses_credit_note` with the same amount
    and tax exists and the invoice balance is back where it was.
30. Given a new install with seller country NP and a seller VAT number, then Nepal VAT mode and proforma mode are on;
    when a proforma invoice is paid, then it gets the next legal number, its issue date becomes the payment date and it
    appears in that BS month's register (D1).
31. Given a credit note on a paid invoice, when the preview opens, then account credit is the selected settlement and
    refund to the original method and "refunded outside" are offered beside it (D3).
32. Given the register page, then the exports offered are CSV (detail), CSV (IRD layout) and PDF, and nothing is sent
    to CBMS when a tax invoice or note is issued; `cbms_status` stays null (D4, D5).

SHOULD:

33. Given an invoice PDF downloaded twice in Nepal VAT mode, then the second copy says "Copy of original — print 2" and
    `print_count` is 2.
34. Given a direct SQL UPDATE of `tax_notes.total` on PostgreSQL, then the trigger rejects it.
35. Given a note issued with "Send to client" ticked, then the client gets the email with the PDF attached.
36. Given a register export above the sync limit, then it is queued and the staff member gets a 24-hour signed link.
37. Given `{BS_FY}` in the note format and reset `bs_fiscal_year` (only if D2 is decided that way), then the first note on 1 Shrawan 2083 is
    `CN-2083-84-1`.

## 14. Owner decisions

Decided by the owner on 2026-10-22 (#327):

| #   | Decision                                              | Decided                                                                                          |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| D1  | When does an invoice become the tax invoice?          | On payment. Unpaid invoices are proforma; Nepal VAT mode turns proforma mode on (§3).            |
| D3  | Default settlement of a credit note on a paid invoice | Account credit, with refund one click away (§5.3).                                               |
| D4  | Register export format                                | CSV (detail and IRD layout) plus PDF. XLSX waits until the IRD portal needs an upload (§7, §16). |
| D5  | CBMS                                                  | Not now. The data model stays CBMS-ready (§4.1, §11).                                            |

Open, waiting for the accountant (with A1–A10 in §15):

| #   | Decision                     | Options                                                                                                                                                                                      | Recommendation                                                                                      |
| --- | ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| D2  | Note numbering               | own `CN-`/`DN-` sequences never reset (now); or reset each BS fiscal year with `{BS_FY}`                                                                                                     | never reset now; switch together with invoices when the accountant answers the fiscal-year question |
| D6  | Invoices in other currencies | which rate to NPR (Nepal Rastra Bank rate on the invoice date, the install's rate table, or the rate the payment was received at); whether foreign clients' invoices are export (zero-rated) | the install's rate on the numbering date, NRB-sourced; export only through an explicit tax rule     |

Still to ask the accountant under D5: whether hosting counts as an "internet service provider" (Rs 5 crore line) and
whether software billing below the line needs the tax office's permission.

## 15. Owner to confirm (local practice the specs assume)

- A1: Nepali hosts give a proforma or "estimate" before payment and the VAT bill after payment; accountants accept
  that for hosting and domains.
- A2: The accountant files the monthly return by typing the sales totals from the register into the IRD portal; no
  file upload of the sales book is required for a host of this size.
- A3: Add-funds deposits are not VAT sales; VAT is charged on the invoice the credit later pays.
- A4: A refund of a hosting payment is always given with a credit note against the VAT bill, by bank transfer, eSewa or
  Khalti, and recorded by staff.
- A5: A chargeback or reversed wallet payment does not change the VAT bill until the host decides the money is lost.
- A6: Unpaid tax invoices written off keep their VAT (no bad-debt relief is claimed).
- A7: Tax offices expect reprints of a computer bill to be marked "copy of original" with a count, even below the CBMS
  line.
- A8: Hosts selling to foreign clients have no export declaration number for services; those columns stay blank.
- A9: Invoices with AD dates and an optional BS date beside them (owner decision 10-22) are acceptable to tax
  officers; the register itself always shows BS.
- A10: The rule that cloud-hosted billing data must be on a server in Nepal applies only to IRD-approved e-billing
  software, not to a host's own billing system below the CBMS line.

## 16. Out of scope

Purchase register, input VAT and the full VAT return; CBMS posting (D5; designed for, §11); XLSX exports (D4); IRD e-billing software
registration; abbreviated tax invoices (retail, under Rs 10,000); excise, health service tax and education fee
columns; Devanagari numerals and a Nepali UI; India GST credit notes and GSTR-1 export (later; the model fits, with the
30 November limit of F10 as a warning); credit notes across several invoices; credit notes for downgrades issued
automatically by Services (HostBill does this; later); notes on proforma invoices.

## 17. Sources (all read 2026-10-22)

| Source                                                                                                                                                                                                                                                                                                                           | Used for            | Notes                                                                                                                     |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| IRD sales register template (बिक्री खाता, Rule 23(1)(ज)), Excel, as redistributed by Baker Tilly Nepal: <https://bakertilly.com.np/tax-portal/notices-and-circulars/new-format-of-sales-register-and-purchase-register> (file `1645950238_Purchase_Register.xlsx`, which holds the sales register; the files are swapped by name) | F2, §6.2, §7        | Opened and read cell by cell; template itself, not a summary. Release date not stated (file name suggests February 2022). |
| LookupTax, Nepal VAT guidelines: <https://lookuptax.com/docs/country/nepal-vat-guidelines-indirect-tax>                                                                                                                                                                                                                          | F1, F3, F4, F6, F8  | Cites Rules 17, 18, 20, 23, 25, 26 and the April 2026 CBMS decision; secondary.                                           |
| Nepal Taxes, provisions relating to invoices (2023-05-17): <https://nepaltaxes.com/provisions-relating-to-invoices-guide-to-invoice-abbreviated-tax-invoice/>                                                                                                                                                                    | F3, F6              | Credit-note contents, three invoice copies; secondary.                                                                    |
| Union Nepal, VAT bill in Nepal: <https://www.unionnepal.com/vat-bill-in-nepal>                                                                                                                                                                                                                                                   | F6, register fields | BS date on bills; buyer PAN above Rs 1 lakh for individuals; undated; secondary.                                          |
| Common Law Nepal, VAT invoice rules and penalties: <https://commonlaw.com.np/publications/vat-invoice-rules-and-penalties-in-nepal>                                                                                                                                                                                              | F4, penalties       | No rule numbers; secondary.                                                                                               |
| Nexus Advisory, IRD billing software (checked 2026-10-08): <https://nexusadvisory.com.np/ird-approved-billing-software-nepal/>                                                                                                                                                                                                   | F7, F8              | Cites IRD notices with BS dates, including the Rs 5 crore ISP line; secondary.                                            |
| CompanySathi, e-billing mandate (2026-08-17): <https://companysathi.com/blog/electronic-billing-mandate-nepal-2026>                                                                                                                                                                                                              | F8                  | Rs 25 crore → Rs 20 crore.                                                                                                |
| Clickmandu (2026-05-29): <https://english.clickmandu.com/2026/05/9187/>                                                                                                                                                                                                                                                          | F8                  | Rs 10 crore budget announcement.                                                                                          |
| `Manjish/ird-cbms` client README: <https://github.com/Manjish/ird-cbms>                                                                                                                                                                                                                                                          | F9, §11             | Field names, BS formats, duplicate behaviour observed on the public test account.                                         |
| Tech One Global, Nepal CBMS Business Central guide: <https://tog-downloads.azurewebsites.net/2022/NP-User-Manual/Nepal-CBMS-User-Guide.pdf> (search summary only)                                                                                                                                                                | F9                  | `ref_invoice_number` and the `billreturn` endpoint.                                                                       |
| CGST Act s.34 commentary: <https://www.cggst.com/uploads/document/1753688693-section-34.pdf>, <https://bhattandjoshiassociates.com/gst-credit-note-declarations-navigating-the-legal-framework/>                                                                                                                                 | F10                 | India later.                                                                                                              |
| WHMCS docs, Reports (Sales Tax Liability, VAT MOSS): <https://docs.whmcs.com/Reports>; proforma and sequential paid numbering (mirror): <https://whmcsdocs.devops.cpanel.net/8-13/billing-and-invoicing/invoicing-tutorials/enable-proforma-invoicing>                                                                           | F11, §3             | Public docs only.                                                                                                         |
| SupportHost WHMCS credit-note add-on: <https://supporthost.com/whmcs-credit-note-addon/>                                                                                                                                                                                                                                         | F11, §4.5           | Shows the gap in WHMCS and the shared-sequence flaw.                                                                      |
| HostBill docs, Credit notes: <https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/1212574/Credit+notes>                                                                                                                                                                                                                        | F11                 | Own sequence, per-item, unpaid invoices, downgrades.                                                                      |
| Blesta forum, credit notes spec thread: <https://www.blesta.com/forums/topic/6976-credit-notes-help-us-spec-this-out-looking-for-your-feedback>                                                                                                                                                                                  | F11                 | Title and search summary only.                                                                                            |

**Not confirmed** (government sites unreachable from our network on 2026-10-22; `ird.gov.np` and
`lawcommission.gov.np` refused or timed out):

- The text of VAT Rules 17, 20, 23 and 25 and the Electronic Billing Procedure 2074; we rely on secondary summaries.
- The current CBMS threshold (Rs 20 crore in force; Rs 10 crore announced, not confirmed) and whether a hosting
  company is an "internet service provider" under the Rs 5 crore notice.
- Whether software billing below the CBMS line needs the tax office's permission for a host, and the server-in-Nepal
  rule's reach.
- Whether the IRD portal expects an uploaded sales book (format and columns) with the monthly return.
- The official CBMS API specification (we read a third-party client and a vendor guide).
- Rule 53(1A) CGST particulars (India, not needed now).
