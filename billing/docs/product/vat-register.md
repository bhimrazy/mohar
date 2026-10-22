# VAT sales register and credit notes — Product Spec (v1)

Status: draft for engineering, fix round 2 (architecture review and review board of #327, CTO decisions of 2026-10-22); aligned with ADR 0024 (fiscal
documents), ADR 0013 Amendment 1 (company clock) and ADR 0025 (report registry), all Proposed in #329 · Owner: Billing workstream · Refines
`billing.md` §2 (invoice_adjustments), §3, §4.7 and `reports.md` §6 (`tax_liability`) · Sources read 2026-10-22 (§17)
· CEO review 2026-10-22, recommendation 4
Clean-room: behaviour derived from public tax guidance and public category docs (WHMCS, HostBill, Blesta forums); no
vendor text copied; naming and schemas are ours. Code references are to `develop` at `34928b98`.

## 0. Why one new file

Credit notes and the sales register are one feature for the user: a VAT-registered Nepali host closes the month from
the register, and the register is only right if every change to an issued tax invoice is a numbered note. Splitting
them across `billing.md` (documents) and `reports.md` (read-only metrics) would put the two halves of one tie-out in
different specs. The register is owned by Billing, not Reports, because it reads Billing's own documents, needs the
Bikram Sambat (BS) code in `App\Modules\Billing\Support`, and is a compliance document rather than a metric. It is
a report in the kernel registry (ADR 0025): Billing computes the rows, the kernel pipeline exports them.
`billing.md` and `reports.md` carry one pointer line each.

## 1. Key facts that shape the design

Confidence: **high** = the prescribed template itself, read first-hand; **medium** = practitioner sources that agree
with each other; **low** = one source, or sources that disagree. Government sites (`ird.gov.np`,
`lawcommission.gov.np`) did not answer from our network on 2026-10-22, so no rule text was read first-hand (§17, "Not
confirmed"). Rule numbers below are as the secondary sources give them.

| #   | Fact                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Confidence                                      |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| F1  | The VAT Rules (Rule 23(1)) require a sales book (बिक्री खाता) in a prescribed schedule, alongside the purchase book, copies of tax invoices, and all credit and debit notes. Records are kept six years (Rule 23(7)).                                                                                                                                                                                                                                                                                | medium (LookupTax; the template cites Rule 23)  |
| F2  | The prescribed sales-book layout (IRD template, Rule 23(1)(ज)) has a header with the taxpayer's PAN, name, year and tax period, and these columns: date; invoice number; buyer name; buyer PAN; goods or service name; quantity; unit; total sales or export (Rs); locally exempt sales (Rs); taxable sales value (Rs) and tax (Rs); export value (Rs); export country; export declaration number; export declaration date.                                                                         | high (template read cell by cell)               |
| F3  | Credit and debit notes (Rule 20(1)) must be marked as such, carry a sequential number and issue date, name both parties with registration numbers, cite the related tax invoice's number and date, describe the goods or services and the reason, and state the amount and the tax credited or debited. Rule 20(2) asks for a monthly account of the notes.                                                                                                                                         | medium (two practitioner sources agree)         |
| F4  | The VAT tax period is the BS month; the return (Schedule 10) is due within 25 days of the month's end; nil returns are required. Some small taxpayers may file four-monthly. The fiscal year runs 1 Shrawan to the end of Asar.                                                                                                                                                                                                                                                                     | medium (LookupTax and Common Law agree)         |
| F5  | A note adjusts output VAT in the period the note is issued, not the period of the original invoice.                                                                                                                                                                                                                                                                                                                                                                                                 | low (one source)                                |
| F6  | The tax invoice must say "Tax Invoice" (कर बीजक) and carry the seller's PAN, buyer's name, address and PAN, number, date, lines, taxable value, VAT and total. Practitioner guides show the date in BS.                                                                                                                                                                                                                                                                                             | medium (fields) / low (BS on the invoice)       |
| F7  | Software billing rules (Electronic Billing Procedure 2074): data cannot be edited or deleted once entered, corrections are new entries, cancelled bills stay with a reason, returns go through credit notes, every record carries date, time and user, reprints are marked as copies with a print count, data exports to Excel or PDF.                                                                                                                                                              | low (one source)                                |
| F8  | Real-time reporting to CBMS (Central Billing Monitoring System) is mandatory above an annual turnover of Rs 20 crore (IRD decision published about 17 April 2026). The FY 2083/84 budget announced Rs 10 crore; no IRD notice putting it into force was found. An older notice set Rs 5 crore for hotels, restaurants and internet service providers. Below the line, billing by software may need the tax office's permission, and cloud-hosted billing data may have to sit on a server in Nepal. | low (sources disagree on the current line)      |
| F9  | CBMS takes one bill per post (fiscal year as `2083.084`, BS date `YYYY.MM.DD`, buyer PAN, total, taxable, VAT, exempt, export) and one credit note per post (`ref_invoice_number`, note number and date, reason, amounts). A third-party client reports that it rejects a duplicate bill number but accepts a duplicate credit note.                                                                                                                                                                | low (third-party client and a vendor guide)     |
| F10 | India GST (later market): a credit note reduces output tax only if declared by 30 November after the financial year of the supply, or the annual return date if earlier (CGST s.34).                                                                                                                                                                                                                                                                                                                | medium (two commentaries agree)                 |
| F11 | Category: WHMCS ships no credit notes; a third-party add-on (SupportHost) fills the gap and says the WHMCS feature request has been open since 2015. WHMCS's Sales Tax Liability report shows subtotal, tax and total for a period and leaves partial refunds out. HostBill has credit notes with their own sequence and per-item refunds. A Blesta forum thread asked for credit notes for VAT (title and search summary only).                                                                    | medium (vendor docs and the add-on's own claim) |

What this means for us: an issued tax invoice is a legal document that is never edited, and the only way to reduce
or increase it is a numbered note dated in its own period. Today Mohar cancels invoices and lines with **unnumbered**
credit adjustments (`CancelInvoice`, `CancelInvoiceItems`), refunds paid invoices with a `refund` adjustment
(`RefundTransaction::applyToInvoice`) and adds late fees as `late_fee` adjustments (`ApplyLateFee`). That is right for
the balance but invisible to a tax register. §5.4 lists every such path and what it becomes.

**Better than the category:** WHMCS leaves credit notes to add-ons and its tax report ignores partial refunds; Mohar
makes the note the only correction path and ties the register to the notes, so register totals always equal
"invoices minus credit notes plus debit notes" for the period, in BS months, with the buyer PAN in place.

## 2. Purpose and scope

In scope:

- Numbered **credit notes** and **debit notes** against one tax invoice each, full or partial, by line or by amount,
  with PDF, email and client-area view.
- The **settlement** of a credit note: reduce the open balance, credit the account, refund through the gateway, or
  record a refund made outside Mohar.
- Notes written by the existing paths (cancel, cancel lines, late fee, refunds from staff and from the provider), §5.4.
- The **VAT sales register** (monthly by BS month, annual by BS fiscal year, any AD range) and the **credit and debit
  note register**, on screen and as CSV and PDF exports, with totals that feed the monthly return.
- **Nepal VAT mode** (§3), an explicit opt-in that switches these on.

Out of scope: §16. Kernel prerequisites (§18): PR 0a, the `CompanyClock` contract on Billing's fiscal call sites
(ADR 0013 Amendment 1); PR 0b, the two events; PR 0c, the report registry with `SafeCsv` (ADR 0025).

## 3. Nepal VAT mode and which document is the tax invoice

- `billing.nepal_vat.enabled` with its `enabled_from` company date (ADR 0024 §8), set in Admin → Settings → Billing →
  Tax by staff with `billing.settings.manage` and a recent password. It is an explicit opt-in: the seller country and
  VAT number only pre-fill the suggestion, so editing a VAT number never changes legal behaviour by itself. **MUST**
    - `enabled_from` must be the first day of a BS month on the company calendar, not before the start of the current
      BS month. Notes can be issued from the moment the mode is enabled. **MUST**
    - **Staff review first.** Old value adjustments that cannot be classified as taxable or exempt (line-less rows,
      and item-cancellation rows that stored the net as taxable on an untaxed line) are listed by the PR 1a backfill
      (§4.1). The mode cannot be enabled until staff have reviewed that list. **MUST**
    - **Old-style rows after the start date.** Enabling is refused while an old-style value adjustment
      (`cancellation`, `item_cancellation`, `refund`, `late_fee`) written **after its invoice's `numbered_at`** is dated
      (company date of `created_at`) on or after `enabled_from`; the page lists them and offers the next BS month.
      Rows written before numbering are already in the snapshot and are not checked. The check runs with the mode's
      setting row locked (`FOR UPDATE`); the old-style paths read the mode inside their own transaction with a shared
      lock on the same row, so none can commit an old-style row after the check has passed. So the register never
      contains an old-style row (decision on B6, §12). **MUST**
    - Enabling and every later change of the setting write an activity entry. Switching the mode off is refused once a
      note exists. **MUST**
    - **Settings locked while on.** Numbering settings (formats, padding, reset of invoices, credit notes and debit
      notes) and the issue-date settings (`sequential_paid_numbering`, `set_issue_date_on_payment`) are read-only while
      the mode is on, and the increment of every fiscal sequence is fixed at 1, so legal numbers are consecutive.
      **MUST**
- **Tax invoice moment (owner decision D1, 2026-10-22).** An invoice becomes a tax invoice on payment. Enabling the
  mode turns proforma mode on (`sequential_paid_numbering` and `set_issue_date_on_payment` true; Mohar already
  implements both in `InvoiceSettlement::settle`), and the lock above keeps it on. Unpaid invoices carry a proforma
  number. **MUST**
- Rule used everywhere below: a **tax invoice** is an invoice with a legal `number`. Drafts and proforma invoices are
  not. Only tax invoices appear in the register and only they take notes. **MUST**
- Consequence of D1: a tax invoice is normally paid, so cancel, cancel lines, late fees and reopen (which act only on
  unpaid or cancelled invoices) reach a tax invoice only when it was numbered at publish before the mode (legacy), or
  when a chargeback has put it in `collections`. They are still specified (§5.4), because both cases exist.
- Add-funds invoices are deposits, not supplies: never in the register, never credited by a note (their refunds stay as
  in `billing.md` §4.8). VAT is accounted when credit pays a tax invoice. **MUST** (assumption A3)

## 4. Entities and key fields

### 4.1 Tax-invoice value snapshot on `invoices` (written once, at numbering)

At the moment an invoice gets its legal number (publish with proforma mode off, or `settle` in proforma mode), in the
same transaction, Billing writes: **MUST**

- `numbered_at` (instant): set in the numbering transaction and never again. It is the numbering moment for every
  rule below; `paid_at` is never used for it (a `collections` invoice paid again later gets a new `paid_at`, while
  its number, `numbered_at` and snapshot stay). **MUST**
- `fiscal_taxable`, `fiscal_exempt`, `fiscal_tax1`, `fiscal_tax2`, `fiscal_total` (int minor; tax names and rates
  are already frozen on the invoice): the value of the supply as numbered (ADR 0024 §2). It is the invoice's own
  totals plus the **signed** sum (debits +, credits −) of the value adjustments (§4.4) created up to and including
  `numbered_at`, so rows written in the numbering transaction itself count (a line cancelled and the invoice settled
  in one `CancelInvoiceItems` call). Each row counts at its stored taxable and tax split. Item-cancellation rows are
  classed taxable or exempt by their line's `taxable` flag through `invoice_item_id`, which `CancelInvoiceItems`
  writes from PR 1a. Adjustment tax is split between tax1 and tax2 in the invoice's tax1:tax2 proportion, remainder to
  tax2 (Nepal uses one level). **MUST**
- `report_currency` (char 3) and `report_rate` (decimal 24,12): the install's reporting currency
  (`billing.tax.report_currency`, NPR for a Nepal seller, INR later for India) and the rate from the invoice currency
  to it. The rate is a **decimal string** computed from the stored currency rates with `Decimal::divide` (as
  `CurrencyConverter` does internally) and never passed through `float`; conversions use `Decimal` arithmetic,
  half-up. No network call inside the transaction. An invoice in the reporting currency stores `1`. Which rate a host
  must use is open (D6). **MUST**
- `supply_class` (`taxable` | `exempt` | `export`, ADR 0024 §2): taken from the tax rule that applied, at numbering,
  so a later tax-rule edit never moves the register's export column. A document mixing taxed and untaxed lines is
  `taxable` with its exempt part in `fiscal_exempt`. Which rule marks a supply as export is open (D6); until then no
  document is `export`. **MUST**
- `bs_issue_date` (`YYYY.MM.DD`), `bs_period` (`2083.07`), `bs_fiscal_year` (`2083.084`), from the company-calendar
  `issue_date` (`system.timezone`) with `BikramSambat::fromAd`. Stored, so a correction of the BS month table never
  moves a filed invoice between periods; invoices and notes are both filtered on the stored period. **MUST**
- `credited_total`, `debited_total` (int minor, cached): written only by `InvoiceSettlement::adjust`, from value
  adjustments created after `numbered_at`, so the cache and the cap (§5.1) read the same set. **MUST**
- **Backfill in the PR 1a migration** (chunked; new columns only, no existing row is rewritten; not at mode enable,
  because the columns are frozen from PR 1a): **MUST**
    - `numbered_at`: for payment-numbered invoices (with a `proforma_number`), the instant the balance first reached
      zero, derived from the succeeded payments and credit rows; for publish-numbered invoices, no value adjustment can
      precede numbering, so the instant only needs to precede every adjustment (the invoice's `created_at` or its
      first adjustment, whichever is earlier). Invoices whose instant cannot be derived go on the staff-review list.
    - the snapshot, by the same signed-sum rule;
    - `credited_total` and `debited_total`, from value adjustments created after `numbered_at`;
    - the staff-review list of unclassifiable rows (§3). Such rows count against the invoice-level cap only, never a
      line cap, and are left out of the per-line remainder rule.
- Immutability in the model: `Invoice::FROZEN` gains `number`, `numbered_at`, `issue_date`, `client_snapshot`,
  `seller_snapshot` and the fields above. The one allowed change is the proforma → numbered transition inside `settle`
  (number, `numbered_at`, issue date and snapshot written together). **MUST**

### 4.2 `tax_notes` (credit and debit notes)

`id, kind (credit|debit), number (unique, never null), invoice_id (FK, restrictOnDelete), client_id (FK,
restrictOnDelete), currency, issue_date (company-clock date), issued_at, invoice_number (snapshot), invoice_bs_issue_date
(snapshot), reason_code, reason_text (client-visible, required, ≤ 500), taxable_amount, exempt_amount, tax1_amount,
tax2_amount, total (int minor, positive; kind gives direction), report_currency, report_rate and supply_class (all three
copied from the invoice's snapshot, never read on the note date, so a fully credited invoice nets to zero in the
reporting currency), seller_snapshot, buyer_snapshot (copied from the invoice's snapshots), bs_issue_date, bs_period,
bs_fiscal_year, automatic (bool), created_by (null when automatic), print_count (int, default 0), cbms_status,
cbms_reference (nullable, reserved, §11), timestamps`

- `reason_code` (Billing enum): credit — `invoice_cancelled`, `lines_cancelled`, `refunded`, `refunded_at_provider`,
  `service_not_delivered`, `price_correction`, `duplicate_invoice`, `discount_after_sale`, `goodwill`, `other`;
  debit — `late_fee`, `price_correction`, `reverses_credit_note`, `other`. **MUST**
- One note references one invoice. **MUST**
- There is no `settlement` JSON. How a note was settled is read from the rows that carry `tax_note_id` (§4.4). **MUST**

### 4.3 `tax_note_lines`

`id, tax_note_id, invoice_item_id (nullable only for a late-fee debit note), description, quantity (unsigned int, as
invoice_items), unit_amount, amount (net), taxable (bool), tax1_amount, tax2_amount, sort_order`

- Every credit note is allocated to lines, including a note "by amount" (§5.1), so per-line caps and remainders are
  always defined. **MUST**

### 4.3a `tax_note_reservations` and `tax_note_reservation_lines` (refund settlements)

`tax_note_reservations: id, invoice_id, transaction_id (the pending refund), reason_code, reason_text, digest, created_by,
status (pending | finalised | released), tax_note_id (set at finalise), timestamps`
`tax_note_reservation_lines: reservation_id, invoice_item_id, quantity, unit_amount, amount, taxable, tax1_amount,
tax2_amount, sort_order`

- The reservation holds the full allocation of the note to be issued. Pending reservations count in both the line caps
  and the invoice cap. Finalise issues exactly this allocation (§5.3). **MUST**

### 4.4 Rows a note writes, and the value-adjustment set

- **Value adjustments** (they change the value of the supply): `credit_note`, `debit_note`, and the old reasons
  `cancellation`, `item_cancellation`, `refund`, `late_fee`, `manual`. Caps, the cached totals and the remainder rule
  read this set, each row with its sign (an `item_cancellation` of a discount line, or the `cancellation` written by
  `ReopenInvoice`, is a debit). Old-style `cancellation` and `refund` rows store taxable 0 and tax 0 and have no line:
  they are on the staff-review list and count against the invoice cap only (§4.1). Old rows are never rewritten.
  **MUST**
- **Settlement adjustments** (move money or credit, never value): `credit_applied`, `credit_removed`, `overpayment`,
  `write_off`, and the new `credit_note_to_credit`. Never notes. **MUST**
- Every note writes exactly one value adjustment (`kind` = note kind, reason `credit_note` / `debit_note`,
  `tax_note_id` set, `number` left null so it cannot collide with the unique `invoice_adjustments.number`). **MUST**
- A credit note settled to account credit additionally writes one settlement adjustment (debit,
  `credit_note_to_credit`, same amount, `tax_note_id`) and one `credit_ledger` row (+amount, reason `credit_note`,
  `tax_note_id`). A credit note settled by refund writes the refund `transactions` row with `tax_note_id`, in place of
  today's `refund` adjustment. Settlement exists only as these rows carrying `tax_note_id`; there is no settlement
  field on the note. **MUST**
- **Balance invariant:** an invoice's balance is ≥ 0 at every commit. The settlement parts of a credit note sum to its
  total: balance reduced + account credit + refunds. **MUST**
- Schema: `tax_note_id` (nullable FK, restrictOnDelete) on `invoice_adjustments`, `transactions` and
  `credit_ledger`; `invoice_item_id` (nullable) on `invoice_adjustments`.

### 4.5 Numbering

- `DocumentNumbers` gets sequences `credit_note` and `debit_note`, each gap-free and consumed in the transaction that
  writes the note. There are no draft notes: staff see a preview, confirm, and the note is numbered on commit. A
  rolled-back note consumes no number (the nested transaction is a savepoint and the sequence row stays locked until
  the outer commit). **MUST**
- Per-sequence settings: today `number_padding`, `number_reset` and `number_increment` are read globally for every
  sequence except quotes. Notes get their own: `billing.credit_note_format: "CN-{NUMBER}"`,
  `billing.debit_note_format: "DN-{NUMBER}"`, and `*_padding` and `*_reset` (default `never`). The increment of every
  fiscal sequence is 1 while the mode is on (§3). The two formats must differ (validated). Notes never share the
  invoice sequence. **MUST**
- **Company clock** (`App\Contracts\Core\CompanyClock`, setting `system.timezone`, ADR 0013 Amendment 1), not
  `CarbonImmutable::today()` in UTC. Otherwise a document issued between 00:00 and 05:45 Nepal time gets the previous
  day and, on the first of a BS month, the previous VAT period. Fiscal call sites that must use it: **MUST**
    - `PublishInvoice` (issue date) and `InvoiceSettlement::settle` (issue date and `numbered_at` on payment);
    - `DocumentNumbers::next()`, which takes the date from its caller (no default), for the `{YEAR}`/`{MONTH}`/`{DAY}`
      tokens and reset periods;
    - the note issuer and the refund finalise step (note issue date and BS fields);
    - the BS fields of invoices and notes (computed from the company date);
    - the mode switch: `enabled_from` validation and the old-row check (company date of `created_at`);
    - the register: the default "last closed BS month", period bounds (`dayBounds()` for an AD range) and the PDF's
      "Printed at".
      The arch scan of ADR 0013 is widened to catch `today()`, `now()->toDateString()` and `date('Y-m-d')` as well as
      `::today(`. Boundary tests at 18:14:59 and 18:15:00 UTC (23:59:59 and 00:00:00 Nepal time) cover every call site.
- Tokens `{BS_YEAR}`, `{BS_FY}` and reset `bs_fiscal_year`: only if D2 is decided that way. **SHOULD**

## 5. Flows

### 5.1 Issue a credit note (staff)

1. From a tax invoice: "Issue credit note". **By line**: each open line with quantity or amount up to what is left to
   credit on it. **By amount**: one gross amount, allocated to lines pro rata to each line's remaining creditable gross,
   half-up, the rounding difference to the line with the most remaining. **MUST**
2. Tax per note line comes from the invoice's snapshotted rates and inclusive/exclusive mode, never from current tax
   rules. **Remainder rule**, per line and per tax level: when a line's credited gross reaches its full value, that note
   line takes exactly the line's remaining net, tax1 and tax2 (after earlier notes, reservations and classified old
   rows on that line). A fully credited invoice then nets to zero value and zero tax to the paisa. **MUST**
3. Caps, checked under the invoice lock: per line, credited ≤ line gross − earlier value credits on that line; per
   invoice, Σ value credits after `numbered_at` ≤ `fiscal_total` + Σ value debits after `numbered_at`. Both caps count
   pending reservation lines (§4.3a); the invoice cap also counts the unclassified old rows. Over-cap requests are
   refused with the amount still creditable. **MUST**
4. Reason code and client-visible text are required. The preview shows the note, the new balance and the settlement.
   **MUST**
5. **Confirm then apply (ADR 0021 §1):** the confirm carries the invoice `version` and a digest of the previewed lines,
   amounts, tax and settlement. The issuer locks, recomputes allocation, caps and tax, and refuses with "The invoice
   changed; review the note again" if the version or the digest differs. Confirm requires a recent password (ADR 0018).
   **MUST**
6. Lock order: payment(s) in id order → invoice → client (credit ledger) → sequence row last (the sequence row is
   install-wide). Every money path that can meet a note uses it, including the provider-refund callback (§5.4). This
   extends ADR 0021 §3 (ADR 0024 §4). **MUST**
7. On commit, in one transaction: number, note, lines, the rows of §4.4, cached totals, activity entry; then the event
   after commit (§10). A settlement by gateway refund follows §5.3 instead. **MUST**
8. The client gets the "Credit note issued" email with the PDF, unless staff untick "Send to client". **SHOULD**

### 5.2 Settlement of a credit note

The credit first reduces what the invoice still owes; only what exceeds the balance (money already received) needs a
destination. Owner decision D3 (2026-10-22): account credit is pre-selected; the refund options are one click away.

| Invoice state        | Up to the balance   | Above the balance (money already received)                                                                                        |
| -------------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Unpaid, no payments  | reduces the balance | not possible (cap)                                                                                                                |
| Partly paid (legacy) | reduces the balance | account credit, or refund                                                                                                         |
| Paid                 | —                   | account credit (§4.4 rows), refund through the gateway (§5.3), or "refunded outside" (record-only, method and reference required) |

- Status: a note that brings an unpaid invoice to zero with no payment is a cancellation (§5.4). With payments covering
  the rest, the invoice is `paid`, as today. When refunds through notes bring `amount_paid` to 0 on a paid invoice, it
  is `refunded`, as today. A paid invoice settled to account credit stays `paid`, balance 0, labelled "Credited" or
  "Partly credited". **MUST**
- Once a credit note has used up an invoice's value, a later refund of its payment cannot go through a note (cap) and
  is refused, so a client cannot get both account credit and a refund for the same sale. Paying the account credit
  out is the new "Refund account credit" action (§5.6), which issues no note. **MUST**

### 5.3 Refund settlement: refund first, note on success

Decision on B1, as ADR 0024 §5: **a failed refund issues no note.** The three phases of `RefundTransaction` are kept
and extended:

1. **Reserve** (one transaction): lock the payment(s) in id order, then the invoice, then the client, then nothing
   else (no number is taken). Check the digest and both caps. Write the pending refund `transactions` row and the
   reservation with its full per-line allocation, reason and text (§4.3a). From here the reserved lines count in every
   cap. **MUST**
2. **Call the provider**, outside any transaction, with the pending row's id as the provider reference (as today).
   **MUST**
3. **Finalise** (one transaction, same lock order, then the sequence row): issue **exactly the reserved allocation**,
   never recomputing caps. Number the note, write its value adjustment, mark the refund succeeded with `tax_note_id`,
   reduce `amount_paid`, mark the reservation finalised, write the activity entry; event after commit. The step is
   idempotent: it does nothing if the reservation is already finalised. **MUST**
    - **Balance re-read under the lock.** The refund amount is fixed (the money has left), so if the invoice was paid
      meanwhile and the balance would go negative, the excess goes to account credit (`credit_note_to_credit` and a
      ledger row, both with `tax_note_id`). The balance stays ≥ 0. **MUST**
    - **Refusal.** The refund is marked failed and the reservation released; **no note exists**, and staff see "The
      gateway refused the refund. Retry, record a refund made outside, or credit the account instead." **MUST**
    - **Crash after the provider's success.** The provider's refund callback for a pending row that carries a
      reservation runs the finalise step instead of answering 409; staff also get "Check refund" on the pending row,
      which asks the provider and finalises. A retry of either finalises once. **MUST**

"Refunded outside" and account credit have no network step and are issued in one transaction (§5.1 step 7). §9's
immutability is untouched: nothing on a note changes after it is numbered.

### 5.4 Every existing path that changes a tax invoice

| Path on `develop`                                                                                                                                                                                                                                              | Writes today                                                                | In Nepal VAT mode, on a tax invoice                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | Reason code / text                                                    | Actor               | Settlement                                          |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------- | --------------------------------------------------- |
| Staff cancel, single or bulk (`InvoiceActionController` → `CancelInvoice`)                                                                                                                                                                                     | credit `cancellation` = balance; applied credit returned (`credit_removed`) | credit note for the open balance. Applied credit is returned first, as today, and is **not** part of the note. Payments stay on the invoice; refunding them later is a further note (row "staff refund")                                                                                                                                                                                                                                                                                                        | `invoice_cancelled` / the cancel dialog's note, required in the mode  | staff               | balance                                             |
| Auto-cancel (`BillingAutomation` step 4)                                                                                                                                                                                                                       | credit `cancellation`                                                       | credit note; auto-cancel already picks only invoices with no payments and no applied credit                                                                                                                                                                                                                                                                                                                                                                                                                     | `invoice_cancelled` / "Automatically cancelled after N days overdue." | automation          | balance                                             |
| `Invoicer::cancel`: Orders `CancelOrder`, `DeleteOrder`, `MarkOrderFraud`; Services `CancelOpenRenewals`, `ExpireUnpaidChanges`; Domains `RenewalInvoices`. These hold their own row locks before Billing's invoice lock (named in the kernel-changelog entry) | as `CancelInvoice`, no reason                                               | credit note, as staff cancel                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | `invoice_cancelled` / "Cancelled by <module>" (ADR 0024 §7)           | automatic (module)  | balance                                             |
| Cancel lines (`CancelInvoiceItems`)                                                                                                                                                                                                                            | credit `item_cancellation` per line                                         | one credit note with one line per cancelled line. If the selection cancels the last open line, the action issues a single `invoice_cancelled` note for it and calls `CancelInvoice` in a mode that writes no further value adjustment: **one note, not two**                                                                                                                                                                                                                                                    | `lines_cancelled` or `invoice_cancelled` / staff text, required       | staff               | balance                                             |
| Late fee (`ApplyLateFee`)                                                                                                                                                                                                                                      | debit `late_fee`                                                            | debit note                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `late_fee` / "Late payment fee"                                       | automation          | adds to the balance                                 |
| Reopen (`ReopenInvoice`)                                                                                                                                                                                                                                       | debit `cancellation`                                                        | refused for every tax invoice. Staff raise a new invoice instead. ("Reissue" is not built; `billing.md` §10 lists it only.)                                                                                                                                                                                                                                                                                                                                                                                     | —                                                                     | —                   | —                                                   |
| Staff refund of a payment (`RefundTransaction`, gateway, record-only or to credit)                                                                                                                                                                             | on a paid invoice: credit `refund`                                          | split as the callback does, credit first: the part up to the account credit that payment placed (overpayment) is a plain refund with no note; the rest is the invoice part and goes through a credit note (§5.3)                                                                                                                                                                                                                                                                                                | `refunded` / staff text, required                                     | staff               | refund (or account credit when mode is "to credit") |
| Provider-initiated refund (`GatewayCallbackHandler::refunded` → `RefundTransaction` record-only)                                                                                                                                                               | on a paid invoice: credit `refund`                                          | the callback already locks payments, then invoices (`lockParents`), before `reverseCredit` locks the client, which is the §5.1 order; it takes credit back first, and the invoice part becomes an **automatic** credit note in the same transaction as the refund row. If the cap is used up (the sale was already credited to account), the callback also takes back the credit that note placed; anything left goes to "Gateway payments to review". A callback for a pending reservation finalises it (§5.3) | `refunded_at_provider` / "Refunded at <gateway> (<refund id>)"        | automatic (gateway) | refund (record-only)                                |
| Refund of a payment on an unpaid invoice                                                                                                                                                                                                                       | no adjustment; balance goes up                                              | no note: the sale is unchanged, the client owes again                                                                                                                                                                                                                                                                                                                                                                                                                                                           | —                                                                     | —                   | —                                                   |
| Chargeback (`RecordChargeback`) and dispute won                                                                                                                                                                                                                | no adjustment (`amount_paid` changes, status `collections`)                 | no note; staff decide later (A5)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | —                                                                     | —                   | —                                                   |
| Mark paid (`MarkInvoicePaid`, `write_off`), apply or remove credit, overpayment                                                                                                                                                                                | settlement adjustments                                                      | no note; the sale keeps its value (A6)                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | —                                                                     | —                   | —                                                   |
| `manual`, `chargeback` adjustment reasons                                                                                                                                                                                                                      | defined in `AdjustmentReason`, no writer on `develop`                       | must not be written on a tax invoice; any value change goes through the note issuer (architecture test)                                                                                                                                                                                                                                                                                                                                                                                                         | —                                                                     | —                   | —                                                   |

- Proforma invoices keep today's behaviour on every path (no note, nothing in the register). **MUST**
- Each row is a **MUST**; the architecture test (`tests/Arch`) checks that only the note issuer writes value
  adjustments on numbered invoices while the mode is on.
- Race tests (PostgreSQL, two processes, ADR 0021 §4), PR 1b and PR 4: two notes on one invoice; a note against a
  payment; a note against a refund; two refund reservations on two payments of one invoice; a provider refund callback
  against a staff note to account credit (no deadlock). **MUST**

### 5.5 Reverse a mistaken credit note

A debit note `reverses_credit_note` for the same lines, amounts and tax, against the same invoice; its PDF names the
credit note it reverses. Notes are never deleted or edited. Where the credit note credited the account, the reversal
takes that credit back (ledger row with `tax_note_id`, allowed negative as other reversals). **MUST**

### 5.6 Refund account credit (new action, not a sale)

There is no action on `develop` that pays account credit out (only `ApplyCredit` and `RemoveCredit`). Because D3 makes
account credit the default settlement, Billing adds one:

- Client page → Credit → "Refund credit": amount ≤ the client's balance in that currency; method and reference
  required. Permission `billing.credit.refund` (new, sensitive, step-up). Writes a `credit_ledger` row (−amount,
  reason `credit_refunded`) and a `refund` transaction (amount out, no invoice), with an activity entry. Lock order:
  client only (no payment or invoice is touched). **MUST** (recorded refund made outside)
- Through the gateway against a chosen earlier payment of the client whose refundable amount covers it, using the
  three phases without a reservation and without touching that payment's invoice. **SHOULD**
- No note: paying out credit is not a supply (assumption A4). The mode does not change this action.

## 6. VAT sales register

### 6.1 Rows and basis

- One row per tax invoice whose stored `bs_period` (or `issue_date`, for an AD range) falls in the period, plus one
  row per credit note (negative) and debit note (positive) in the period, ordered by date then number. Notes count in
  their own period, not the invoice's (F5, assumption). **MUST**
- Amounts are the invoice's numbering snapshot (§4.1) and the notes' own amounts, never the live invoice totals. **MUST**
- Basis is the document date, never cash. In proforma mode a tax invoice's date is the company date of its
  `numbered_at`, its first payment in full. **MUST**
- Only documents dated on or after `nepal_vat.enabled_from` appear; earlier periods are not offered. Drafts, proforma
  invoices and add-funds invoices never appear. A cancelled tax invoice stays in its own period and its credit note
  sits in the note's period. **MUST**

### 6.2 Columns (on screen and in exports)

Header block: seller PAN, seller legal name, BS fiscal year, tax period (BS month name and number, or the AD range).

| #   | Column                          | Source                                                                                   |
| --- | ------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | Date (BS)                       | stored `bs_issue_date`                                                                   |
| 2   | Date (AD)                       | `issue_date`, ISO 8601                                                                   |
| 3   | Document type                   | Tax invoice, Credit note, Debit note                                                     |
| 4   | Document number                 | invoice `number` or note `number`                                                        |
| 5   | Reference invoice               | notes only: invoice number and its BS date                                               |
| 6   | Buyer name                      | snapshot company, else name                                                              |
| 7   | Buyer PAN                       | snapshot `tax_id`; blank when missing (assumption A11)                                   |
| 8   | Goods or service                | first line description, "+ n more" when several; the full lines are in the detail export |
| 9   | Quantity                        | sum of line quantities when the lines share one unit; blank otherwise                    |
| 10  | Unit                            | "service" when the lines share it; blank otherwise                                       |
| 11  | Total sales (Rs)                | taxable + exempt + export value, in the reporting currency                               |
| 12  | Exempt sales (Rs)               | `fiscal_exempt` / note `exempt_amount`                                                   |
| 13  | Taxable sales value (Rs)        | `fiscal_taxable` / note `taxable_amount`                                                 |
| 14  | VAT (Rs)                        | tax1 + tax2                                                                              |
| 15  | Export value (Rs)               | value of documents with `supply_class = export`                                          |
| 16  | Export country                  | buyer country for exports                                                                |
| 17  | Export declaration no. and date | blank for services (A8)                                                                  |
| 18  | Currency, amount, rate          | for documents not in the reporting currency: original currency, total and `report_rate`  |

- Mapping to the prescribed columns (F2): 1, 4, 6, 7, 8, 9, 10, 11, 12, 13 and 14, 15, 16 are one template column
  each; 17 covers two template columns (declaration number and date), which the IRD-layout export writes separately.
  2, 3, 5 and 18 are our additions, placed after the template's columns in the IRD-layout export (§7). **MUST**
- Money in rupees with two decimals; conversion per document with its stored `report_rate`, half-up, then summed.
  **MUST**

### 6.3 Periods and totals

- Monthly: a BS month picker defaulting to the last closed BS month. Annual: BS fiscal year (1 Shrawan – end Asar).
  Custom: any AD date range. **MUST**
- Footer totals: total sales, exempt, taxable, VAT, export, each split into invoices, credit notes and debit notes,
  and the net. A "Return figures" box shows net taxable sales, net VAT on sales, exempt sales and export sales for the
  monthly return, with the note "Sales side only; purchases and input VAT are not in this product". **MUST**
- Invariant tested on every period: net VAT = Σ invoice VAT − Σ credit-note VAT + Σ debit-note VAT, exactly in paisa.
  It holds because every row is a snapshot or a note, and no old-style row written after numbering can fall on or
  after `enabled_from` (§3). **MUST**
- Annual view: one row per BS month with the same totals, plus the full detail in the export. **MUST**
- Warnings above the table (not blocking): business buyers without a PAN, and documents not in the reporting currency
  whose `report_rate` came from a manually entered rate. **SHOULD**

### 6.4 Credit and debit note register (monthly account of notes; Rule 20(2) as F3 reports it, assumption)

A second tab with the same periods, listing notes only: number, BS and AD date, type, reference invoice number and BS
date, buyer, PAN, reason code and text, taxable, exempt, VAT, total, settlement summary (read from the rows with
`tax_note_id`), created by or "automatic". **MUST**

## 7. Export formats

Owner decision D4 (2026-10-22): CSV (detail and IRD layout) plus PDF; no XLSX until the IRD portal needs an upload.
Exports go through the kernel pipeline of ADR 0025; Billing provides `VatRegisterReport` and `TaxNoteRegisterReport`
(the `Report` contract with `ProvidesTotals`, `ProvidesHeaderBlock` and `ProvidesLayouts`) and builds no export of its
own.

- **CSV, layout `detail`**: one row per document as §6.2, English headings, UTF-8 with BOM by default for these
  reports (Excel on Windows otherwise garbles Nepali names). Written by `SafeCsv`: the formula guard applies to text
  cells only, so a credit-note amount of `-1300.00` stays a number. **MUST**
- **CSV, layout `ird`**: the prescribed columns in the prescribed order with the template's Nepali headings, the header
  block as leading rows (PAN, name, year, period), then our extra columns. **MUST**
- **PDF**: the kernel report view (A4 landscape, header block, page and grand totals, "Printed by <staff> at <company
  time>", brand from `BrandProvider`). **MUST**
- Exports are synchronous in this build; the queued export waits for the Reports module (PR 0c). One `report.exported`
  activity entry per export, written by the pipeline (ADR 0025 §5). **MUST**
- Note PDF (Billing's own document view, beside the invoice PDF): titled "Credit Note" / "Debit Note", seller name,
  address and PAN/VAT number, buyer name, address and PAN, note number, BS and AD date, "Against tax invoice <number>
  dated <BS date> (<AD date>)", reason, lines, taxable, VAT, total, settlement line ("Credited to your account",
  "Refunded to eSewa", …). **MUST**

## 8. Admin and client capabilities

Admin:

- Invoice page: "Issue credit note" and "Issue debit note" (tax invoices only, mode on), a Notes panel listing the
  invoice's notes and the remaining creditable amount. **MUST**
- Billing → Credit notes: list with filters (kind, period BS/AD, client, reason, settlement, automatic), view, PDF,
  resend. **MUST**
- Billing → VAT register: §6 with the two tabs and the exports. **MUST**
- Client page: the client's notes beside invoices. **SHOULD**

Client area:

- Invoice page lists its notes; Billing → Credit notes lists the client's notes with PDF download. Clients never
  create notes. **MUST**

### 8.1 Permissions (registered in `PermissionRegistry`)

| Key                                          | Grants                                                         | Sensitive (step-up) |
| -------------------------------------------- | -------------------------------------------------------------- | ------------------- |
| `billing.tax_notes.view`                     | see notes in admin, download their PDFs                        | no                  |
| `billing.tax_notes.issue`                    | issue credit and debit notes (settlement to balance or credit) | yes                 |
| `billing.refunds.manage` (exists)            | additionally required when a note's settlement refunds money   | yes                 |
| `reports.vat_register.view`                  | see the register (registered by the report registry)           | no                  |
| `reports.vat_register.export`                | export it in any format                                        | yes (ADR 0025 §4)   |
| `reports.tax_note_register.view` / `.export` | the same for the note register                                 | export: yes         |
| `billing.credit.refund`                      | pay account credit out (§5.6)                                  | yes                 |

- Full Administrator has all; Billing's seeder grants the seeded Billing role `billing.tax_notes.*` and both reports'
  keys; Support gets none.
  Notes written by existing paths (§5.4) need only the permission of the action that triggers them. **MUST**

## 9. Audit and immutability

- A numbered note and its lines are never updated or deleted, except `print_count`, `cbms_status` and
  `cbms_reference`. The model refuses other changes (`ImmutableInvoiceException`); `restrictOnDelete` on the note's
  foreign keys stops a cascade from an invoice or client delete. **MUST**
- Database triggers are not used in this version: the codebase has none, SQLite CI cannot exercise them, and an
  invoice trigger would need to allow the proforma → numbered transition. ADR 0024 §9 defers them to their own ADR.
- Activity log entries (`ActivityLogger`): `billing.tax_note.issued` (number, kind, invoice, total, settlement, staff
  or "automatic"), `billing.tax_note.emailed`, `billing.tax_note.printed` (with the new print count),
  `billing.nepal_vat.enabled` / `.changed`; exports are logged by the pipeline as `report.exported`. Viewing the
  register is not logged. **MUST**
- Print marking: the first PDF download of an invoice or note is the original; later ones carry "Copy of original —
  print n" in the header, in the mode (F7, assumption A7). Admin and client downloads both count. **SHOULD**
- Retention: notes, invoices and their PDFs are never pruned by any cleanup job; the KYC 90-day deletion never touches
  billing documents. Six years minimum (assumption, F1; IRD text not read); nothing is pruned in any case. **MUST**

## 10. Events (`app/Events/Billing`, kernel change made by the CTO session in PR 0b)

Emitted after commit, as defined in ADR 0024 §6 (`DomainEvent`, `ShouldDispatchAfterCommit`, the int + currency
pattern of `InvoiceRefunded`):

- `CreditNoteIssued(int $taxNoteId, string $number, int $invoiceId, int $clientId, int $total, string $currency,
  bool $automatic)` **MUST**
- `DebitNoteIssued(...)` with the same fields. **MUST**
- Existing events keep their meaning: `InvoiceCancelled` still fires on cancellation (after the note),
  `InvoiceModified` when a partial note changes the balance, `InvoiceRefunded` when a note refunds money,
  `LateFeeApplied` for a late-fee debit note. **MUST**

Consumed by:

- Notifications: "Credit note issued" / "Debit note issued" email with the PDF; webhook events `credit_note.issued`
  and `debit_note.issued` (one class to one key in `WebhookEventTypes::MAP`); merge fields `tax_note.*` through
  `MergeFieldRegistry`. **MUST**
- Affiliates and Services: none. Commission reversal stays on `PaymentReversed`; a note never suspends or cancels a
  service.

**Behaviour change under ADR 0010 (ADR 0024 §7):** `Invoicer::cancel()` can now create a legal document (a credit
note) when the mode is on. PR 3 updates its docblock and lists the change in `docs/architecture/kernel-changelog.md`,
naming the six callers of §5.4 and their lock order (their own rows, then Billing's invoice); the signature is unchanged (a trailing `?string $reason = null` can come later).

## 11. Integration (ADR 0008 contracts), ADRs and CBMS readiness

- `Invoicer` (behaviour change above) and `InvoiceQueries`: no signature change. Reports reads `tax_notes` and the
  register query from Billing, so no new cross-module read contract is needed now.
- `report_rate` comes from the stored currency rates as a decimal string (`Decimal::divide`), not from the `float`
  that `CurrencyConverter::rate()` returns today; the remote `ExchangeRateSource` is never called at numbering.
- `BikramSambat` stays in `App\Modules\Billing\Support` and gains `fiscalYear()` and `period()`; it moves to the kernel
  only when a second module needs it.
- **Prerequisites:** PR 0a, the `App\Contracts\Core\CompanyClock` contract with Billing's fiscal call sites (§4.5,
  ADR 0013 Amendment 1); PR 0b, the two events (ADR 0024 §6); PR 0c, the report registry, `SafeCsv` and synchronous
  CSV and PDF export (ADR 0025), with the queued export deferred.
- **ADRs this spec builds on** (Proposed in #329): ADR 0024 "Fiscal documents and correction notes", ADR 0025 "Report
  registry and one export pipeline", ADR 0013 Amendment 1. Where the spec and an ADR differ, the ADR wins.
- **CBMS** is not built (owner decision D5, 2026-10-22). The model is ready for it: BS date, the fiscal year in the
  CBMS form, buyer PAN, the amount split CBMS expects, the reference invoice and reason on notes, `cbms_status`
  columns. A later driver would post through an outbox, retry a bill by its invoice number, and never retry a note
  blindly (F9).

## 12. Edge cases

- **Invoice numbered before the mode** (legacy): takes notes like any other. Its caps include the old-style value
  adjustments written after `numbered_at` (classified rows on their lines, unclassified rows at invoice level only). It
  is in the register only if dated on or after `enabled_from`. Decision on B6: old-style rows written after numbering
  are **excluded** from the tie-out by construction, because enabling is refused while any is dated on or after
  `enabled_from` (§3); their tax is never backfilled.
- **Chargebacked invoice paid again** (`collections` → `paid`): `settle` sets a new `paid_at`; `numbered_at`, the number
  and the snapshot do not change.
- **Proforma with a late fee, then paid**: the snapshot includes the fee and its tax; the register shows the value paid
  for, not the original total.
- **Invoice in USD to a client abroad**: the register converts with the stored `report_rate`; whether it is export
  depends on the stored `supply_class`, not on the currency (D6).
- **Credit note in a later fiscal year** than the invoice: allowed; it sits in its own BS month and fiscal year and
  cites the old invoice and its BS date.
- **Payment just after midnight Nepal time** (from 18:15:00 UTC): the company clock dates the tax invoice that day, in
  that BS month; at 18:14:59 UTC it is still the previous day.
- **Gateway refund while the client pays the rest** (legacy partly paid invoice): finalise re-reads the balance and
  moves the excess to account credit (§5.3).
- **Date outside the BS table** (after 2095 BS): no tax invoice or note can be numbered in the mode; staff see why.
- **Partial payment, then cancel** (legacy or proforma off): the note covers the open balance; the payments stay, and
  refunding them is a further note.
- **Credit applied, then cancel**: the applied credit is returned through `credit_removed` as today and is not part of
  the note; the note covers the balance after that return.
- **Concurrent notes, or a note racing a payment or refund**: serialised by the lock order of §5.1; the cap is checked
  under the lock and counts pending reservation lines. Race tests: §5.4.
- **Refund across several payments**: the payments are locked in id order before the invoice.
- **Tax rule changed** after numbering: the note uses the invoice's snapshot rates.
- **Discount lines**: credited with their parent line, never on their own.
- **Client changes PAN** after the invoice: the note keeps the invoice's buyer snapshot.
- **Imported invoices** (importer phase 2): imported tax invoices get `numbered_at` and the snapshot at import and take
  notes; they are in the register only if dated on or after `enabled_from`.

## 13. Acceptance criteria

The PR in brackets is where each criterion first passes (§18).

MUST:

1. [1b] Given the mode and a tax invoice of Rs 11,300 (10,000 + 13% VAT), when staff issue a full credit note, then it
   is numbered `CN-1`, references the invoice number and its BS date, and has taxable 10,000.00 and VAT 1,300.00.
2. [1b] Given two notes issued concurrently on different invoices, then they get consecutive distinct numbers; a
   rolled-back note consumes no number.
3. [1b] Given lines of 5,000 and 6,300 gross, when staff credit only the second, then the note has one line and 5,000
   is still creditable.
4. [1b] Given an invoice numbered at publish before the mode, with a 1,000 `item_cancellation` written after
   numbering, then at most total − 1,000 can be credited.
5. [1b] Given a note by amount, then by line, then by amount that together credit an invoice in full, then each note
   has lines, and per line and per tax level they sum exactly to the invoice's snapshot.
6. [1b] Given a tax rule changed from 13% to 15% after numbering, then a credit note on the invoice uses 13%, and the
   note's `report_rate` equals the invoice's.
7. [1a] Given a proforma paid in full, then `numbered_at` is set in that transaction; given it later goes to
   `collections` and is paid again, then `paid_at` changes and `numbered_at`, the number and the snapshot do not.
8. [1a] Given a proforma with a 500 taxable late fee, and one line cancelled in the same `CancelInvoiceItems` call that
   settles it, then `fiscal_*` equal the totals plus the fee minus the cancelled line, each with its tax.
9. [1a] Given the PR 1a migration on a database with legacy invoices, then snapshots are the signed sums up to
   `numbered_at`, `credited_total` and `debited_total` equal the post-numbering value adjustments, and line-less or
   misclassified old rows appear on the staff-review list.
10. [1a] Given a numbered note or invoice, when any model path changes a frozen field, deletes the note, or deletes its
    invoice, then the change is refused.
11. [1b] Given a preview, when the invoice changes before confirm (a payment, another note), then the confirm is refused
    with "The invoice changed; review the note again".
12. [1b] Given two processes, then the race tests of §5.4 for notes (two notes on one invoice, note against payment,
    note against refund) leave the caps and the balance invariant intact.
13. [2] Given a paid invoice and settlement "account credit", then exactly these rows exist: a `credit_note` credit
    adjustment, a `credit_note_to_credit` debit adjustment and a `credit_ledger` +amount row, all with `tax_note_id`;
    the balance is 0 and the invoice is `paid` and "Credited".
14. [2] Given a mistaken credit note to account credit, when staff reverse it, then a `reverses_credit_note` debit note
    with the same lines and tax exists, the credit is taken back and the balance is where it was.
15. [2] Given staff without `billing.tax_notes.issue`, then the issue buttons are absent and the endpoint returns 403;
    with it but without a recent password, the confirm asks for one.
16. [2] Given staff without `billing.refunds.manage`, then the refund settlements are not offered.
17. [2] Given a note issued, then an activity entry `billing.tax_note.issued` with number, invoice, total, settlement
    and actor exists.
18. [2] Given a credit note on a paid invoice, when the preview opens, then account credit is selected and the refund
    options are offered beside it (D3).
19. [2] Given a client with 1,300 of account credit, when staff with `billing.credit.refund` and a recent password
    record a refund of 1,000 made outside, then a −1,000 `credit_refunded` ledger row and a `refund` transaction exist,
    no note is issued and no invoice changes; without the permission the action is absent.
20. [2] Given a credit note issued, then `CreditNoteIssued` with its number fires once after commit and the
    `credit_note.issued` webhook is queued; a debit note fires `DebitNoteIssued` and queues `debit_note.issued`.
21. [3] Given a tax invoice numbered at publish before the mode, cancelled by staff, by auto-cancel, and by
    `Invoicer::cancel` from each of the six callers in §5.4, then each issues one `invoice_cancelled` note with the
    reason text of §5.4 and no `cancellation` adjustment.
22. [3] Given cancel lines that cancels the last open line, then exactly one note is issued.
23. [3] Given a proforma invoice cancelled, then no note is issued and nothing appears in the register.
24. [3] Given a late fee on a legacy unpaid tax invoice, then a `late_fee` debit note is issued and shown as a positive
    register row.
25. [3] Given any tax invoice in the mode, then "Reopen" is refused.
26. [3] Given staff enabling the mode at 00:10 Nepal time on day 1 of a BS month, then that day is accepted as
    `enabled_from`; enabling is refused while the staff-review list is unreviewed or a post-numbering old-style row is
    dated on or after it (a late fee committing concurrently is either before the check or blocked); proforma mode
    turns on; numbering and issue-date settings become read-only with increment 1; an activity entry is written; and
    switching off is refused once a note exists.
27. [3] Given the mode on, when a proforma is paid, then it gets the next legal number, its issue date is the company
    date of `numbered_at` and it appears in that BS month's register (D1).
28. [4] Given settlement by gateway refund and the provider refuses, then no note exists, no number is consumed, the
    refund row is failed and the reservation released; given it succeeds, then exactly the reserved allocation is
    issued with the succeeded refund row; given the client paid the remaining balance during the provider call, then
    the excess is account credit and the balance is 0, not negative.
29. [4] Given a by-line refund reservation on line B pending, when another staff member credits line B to account,
    then it is refused by the line cap.
30. [4] Given a crash after the provider's success, when the provider's refund callback arrives (or staff press "Check
    refund"), then the pending reservation is finalised once, and a repeat does nothing.
31. [4] Given a provider-initiated refund of a paid tax invoice, then an automatic credit note `refunded_at_provider`
    with the provider's refund id in its text is issued in the same transaction as the refund row.
32. [4] Given a paid invoice credited in full to account, when the provider later refunds the payment, then no note is
    issued, the credit that note placed is taken back, and the rest goes to "Gateway payments to review".
33. [4] Given a payment of 12,000 on an 11,300 invoice (700 overpayment in credit), when staff refund 1,000, then 700
    is a plain refund and 300 goes through a credit note.
34. [4] Given two processes, then two refund reservations on two payments of one invoice respect the cap, and a
    provider refund callback racing a staff note to account credit completes without a deadlock.
35. [5] Given a client, then they see and download only their own notes; another client's note URL returns 404.
36. [5] Given the note PDF, then it shows "Credit Note", seller PAN/VAT number, buyer PAN, both dates, the referenced
    invoice and the reason.
37. [6] Given an invoice dated in Kartik 2083 and its credit note in Mangsir 2083, then the Kartik register shows the
    invoice and the Mangsir register shows the note as a negative row.
38. [6] Given a register period, then net VAT equals Σ invoice VAT − Σ credit-note VAT + Σ debit-note VAT exactly, and
    the "Return figures" box shows the same numbers.
39. [6] Given the annual view for 2082/83, then it covers 1 Shrawan 2082 to the last day of Asar 2083 in 12 month rows.
40. [6] Given a business client without a PAN, then the row has a blank PAN and the page warns; nothing is blocked.
41. [6] Given a USD invoice with `report_rate` `134.250000000000`, then its register amounts are computed in `Decimal`,
    rounded per document, unchanged after the rate table or the tax rule is edited, and a full credit note on it nets
    to exactly zero in NPR.
42. [6] Given an add-funds invoice, a draft, or a tax invoice dated before `enabled_from`, then none appears in the
    register, and periods before `enabled_from` are not offered.
43. [6] Given the register opened at 18:15:00 UTC on the last day of a BS month, then the default period is that month
    (now closed); at 18:14:59 UTC it is the month before.
44. [7] Given the IRD-layout CSV, then its columns are in the prescribed order with the template's Nepali headings,
    declaration number and date in separate columns, and header rows with seller PAN, name, fiscal year and period.
45. [0c] Given a buyer named `=HYPERLINK(...)`, then the CSV cell starts with `'=`, while a credit-note amount
    `-1300.00` is written unprefixed; every export writes one `report.exported` entry with layout, format, filters and
    row count.
46. [7] Given staff without `reports.vat_register.export`, then the exports are absent; with it, an export asks for a
    recent password.
47. [7] Given the register, then the exports offered are CSV (detail), CSV (IRD layout) and PDF, and issuing a document
    sends nothing to CBMS; `cbms_status` stays null (D4, D5).
48. [0a, notes in 1b] Given a proforma paid at 18:14:59 UTC and another at 18:15:00 UTC on the last day of Asoj 2083,
    then the first is dated the last day of Asoj and the second 1 Kartik, in their BS periods; a credit note issued at
    18:20 UTC that day is dated 1 Kartik.

SHOULD:

49. [5] Given an invoice PDF downloaded twice in the mode, then the second copy says "Copy of original — print 2" and
    `print_count` is 2.
50. [5] Given a note issued with "Send to client" ticked, then the client gets the email with the PDF attached.
51. [4] Given staff refund account credit through the gateway against an earlier payment, then the payment's
    refundable amount falls and its invoice does not change.
52. [later] Given `{BS_FY}` in the note format and reset `bs_fiscal_year` (only if D2 goes that way), then the first
    note on 1 Shrawan 2083 is `CN-2083-84-1`.

Not in this build: a PostgreSQL trigger on notes (ADR 0024 §9) and queued exports (deferred in PR 0c until the
Reports module).

## 14. Owner decisions

Decided by the owner on 2026-10-22 (#327):

| #   | Decision                                              | Decided                                                                                     |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| D1  | When does an invoice become the tax invoice?          | On payment. Unpaid invoices are proforma; enabling the mode turns proforma mode on (§3).    |
| D3  | Default settlement of a credit note on a paid invoice | Account credit, with refund one click away (§5.2).                                          |
| D4  | Register export format                                | CSV (detail and IRD layout) plus PDF. XLSX waits until the IRD portal needs an upload (§7). |
| D5  | CBMS                                                  | Not now. The data model stays CBMS-ready (§4, §11).                                         |

Open, waiting for the accountant (with A1–A11 in §15):

| #   | Decision                     | Options                                                                                                                                                                                       | Recommendation                                                                                         |
| --- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| D2  | Note numbering               | own `CN-`/`DN-` sequences never reset (now); or reset each BS fiscal year with `{BS_FY}`                                                                                                      | never reset now; switch together with invoices when the accountant answers the fiscal-year question    |
| D6  | Invoices in other currencies | which rate to the reporting currency (Nepal Rastra Bank rate on the invoice date, the install's rate table, or the rate the payment was received at); which tax rule marks a supply as export | the install's stored rate on the numbering date, NRB-sourced; export only through an explicit tax rule |

Still to ask the accountant under D5: whether hosting counts as an "internet service provider" (Rs 5 crore line) and
whether software billing below the line needs the tax office's permission.

## 15. Owner to confirm (assumptions about local practice)

- A1: Nepali hosts give a proforma or "estimate" before payment and the VAT bill after payment; accountants accept
  that for hosting and domains.
- A2: The accountant files the monthly return by typing the sales totals from the register into the IRD portal; no
  file upload of the sales book is required for a host of this size.
- A3: Add-funds deposits are not VAT sales; VAT is charged on the invoice the credit later pays.
- A4: A refund of a hosting payment is always given with a credit note against the VAT bill, by bank transfer, eSewa
  or Khalti; a refund of an overpayment held as credit needs no note.
- A5: A chargeback or reversed wallet payment does not change the VAT bill until the host decides the money is lost.
- A6: Unpaid tax invoices written off keep their VAT (no bad-debt relief is claimed).
- A7: Tax offices expect reprints of a computer bill to be marked "copy of original" with a count, even below the CBMS
  line.
- A8: Hosts selling to foreign clients have no export declaration number for services; those columns stay blank.
- A9: Invoices with AD dates and an optional BS date beside them (owner decision 10-22) are acceptable to tax
  officers; the register itself always shows BS.
- A10: The rule that cloud-hosted billing data must be on a server in Nepal applies only to IRD-approved e-billing
  software, not to a host's own billing system below the CBMS line.
- A11: A tax invoice or register row may leave the buyer PAN blank when the client has none (the recorded 10-22
  decision covers importer PANs only, not invoices or the register).

## 16. Out of scope

Purchase register, input VAT and the full VAT return; CBMS posting (D5; designed for, §11); XLSX exports (D4); queued
exports (deferred in PR 0c); database triggers (ADR 0024 §9); "Reissue"; IRD e-billing
software registration; abbreviated tax invoices (retail, under Rs 10,000); excise, health service tax and education fee
columns; Devanagari numerals and a Nepali UI; credit notes with the mode off; India GST credit notes and GSTR-1 export
(later; the model fits, with the 30 November limit of F10 as a warning); credit notes across several invoices;
automatic downgrade credit notes from Services (HostBill does this; later); notes on proforma invoices.

## 17. Sources (all read 2026-10-22)

- IRD sales register template (बिक्री खाता, Rule 23(1)(ज)), Excel, as redistributed by Baker Tilly Nepal:
  <https://bakertilly.com.np/tax-portal/notices-and-circulars/new-format-of-sales-register-and-purchase-register>.
  The file named `1645950238_Purchase_Register.xlsx` holds the sales register (the two downloads are swapped by
  name). Read cell by cell; the template itself. Release date not stated (the file name suggests February 2022).
  Used for F2, §6.2, §7.
- LookupTax, Nepal VAT guidelines: <https://lookuptax.com/docs/country/nepal-vat-guidelines-indirect-tax>. Cites
  Rules 17, 18, 20, 23, 25, 26 and the April 2026 CBMS decision; secondary. F1, F3, F4, F6, F8.
- Nepal Taxes, provisions relating to invoices (2023-05-17):
  <https://nepaltaxes.com/provisions-relating-to-invoices-guide-to-invoice-abbreviated-tax-invoice/>. Credit-note
  contents, three invoice copies; secondary. F3, F6.
- Union Nepal, VAT bill in Nepal: <https://www.unionnepal.com/vat-bill-in-nepal>. BS date on bills, register fields;
  undated; secondary. F6.
- Common Law Nepal, VAT invoice rules and penalties:
  <https://commonlaw.com.np/publications/vat-invoice-rules-and-penalties-in-nepal>. No rule numbers; secondary. F4.
- Nexus Advisory, IRD billing software (checked 2026-10-08):
  <https://nexusadvisory.com.np/ird-approved-billing-software-nepal/>. Cites IRD notices, including the Rs 5 crore ISP
  line and the 2074 procedure; secondary. F7, F8.
- CompanySathi, e-billing mandate (2026-08-17): <https://companysathi.com/blog/electronic-billing-mandate-nepal-2026>.
  Rs 25 crore → Rs 20 crore. F8.
- Clickmandu (2026-05-29): <https://english.clickmandu.com/2026/05/9187/>. Rs 10 crore budget announcement. F8.
- `Manjish/ird-cbms` client README: <https://github.com/Manjish/ird-cbms>. Field names, BS formats, duplicate
  behaviour seen on the public test account. F9, §11.
- Tech One Global, Nepal CBMS Business Central guide:
  <https://tog-downloads.azurewebsites.net/2022/NP-User-Manual/Nepal-CBMS-User-Guide.pdf> (search summary only).
  `ref_invoice_number` and the bill-return endpoint. F9.
- CGST s.34 commentary: <https://www.cggst.com/uploads/document/1753688693-section-34.pdf>,
  <https://bhattandjoshiassociates.com/gst-credit-note-declarations-navigating-the-legal-framework/>. F10.
- WHMCS docs, Reports (Sales Tax Liability, VAT MOSS): <https://docs.whmcs.com/Reports>; proforma and sequential paid
  numbering (mirror):
  <https://whmcsdocs.devops.cpanel.net/8-13/billing-and-invoicing/invoicing-tutorials/enable-proforma-invoicing>.
  Public docs only. F11, §3.
- SupportHost WHMCS credit-note add-on: <https://supporthost.com/whmcs-credit-note-addon/>. The add-on's own account of
  the WHMCS gap and of the shared-sequence option. F11, §4.5.
- HostBill docs, Credit notes: <https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/1212574/Credit+notes>. Own
  sequence, per-item, unpaid invoices, downgrades. F11.
- Blesta forum, credit notes spec thread:
  <https://www.blesta.com/forums/topic/6976-credit-notes-help-us-spec-this-out-looking-for-your-feedback>. Title and
  search summary only. F11.

**Not confirmed** (`ird.gov.np` and `lawcommission.gov.np` refused or timed out from our network on 2026-10-22):

- The text of VAT Rules 17, 20, 23 and 25 and of the Electronic Billing Procedure 2074; we rely on secondary summaries.
- The current CBMS threshold (Rs 20 crore in force; Rs 10 crore announced, not confirmed) and whether a hosting
  company is an "internet service provider" under the Rs 5 crore notice.
- Whether software billing below the CBMS line needs the tax office's permission for a host, and how far the
  server-in-Nepal rule reaches.
- Whether the IRD portal expects an uploaded sales book (format and columns) with the monthly return.
- The official CBMS API specification (we read a third-party client and a vendor guide).
- CGST Rule 53(1A) particulars (India, not needed now).

## 18. Build order

Each PR ≤ 2,000 lines; the mode stays hidden until PR 4 merges. Acceptance criteria by number (§13).

| PR               | Content                                                                                                                                                                                                                                                                                           | AC                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------- |
| 0a (CTO, kernel) | `CompanyClock` contract and Core implementation; Billing's fiscal call sites on it (§4.5); widened arch scan; boundary tests at 18:14:59 and 18:15:00 UTC. Other modules move later                                                                                                               | 48 (invoices)           |
| 0b (CTO, kernel) | `CreditNoteIssued` / `DebitNoteIssued` and their `WebhookEventTypes::MAP` entries (ADR 0024 §6)                                                                                                                                                                                                   | —                       |
| 1a               | Schema (`numbered_at`, value snapshot, cached totals, `report_currency`/`report_rate`, `supply_class`, BS fields, `tax_notes`, lines, reservations, `tax_note_id` and `invoice_item_id` columns); the backfill migration and staff-review list; `Invoice::FROZEN`; per-sequence `DocumentNumbers` | 7–10                    |
| 1b               | The note issuer: allocation, caps with reservations, remainder, locks, digest; PostgreSQL race tests for notes                                                                                                                                                                                    | 1–6, 11, 12, 48 (notes) |
| 2                | Manual issue UI; settlement to balance, account credit and recorded refund; reversing debit note; step-up and permissions; notes panel and list; "Refund account credit" (recorded); webhook delivery                                                                                             | 13–20                   |
| 3                | Existing paths: cancel, cancel lines, late fee, reopen, `Invoicer::cancel` and its six callers (kernel-changelog entry), automation; enabling the mode (review list, setting lock, settings read-only)                                                                                            | 21–27                   |
| 4                | Refunds through notes: reservation, provider call, idempotent finalise, crash recovery through the callback and "Check refund", provider-initiated refunds, overpayment split, gateway cash-out of credit; race tests (combined review, money)                                                    | 28–34, 51               |
| 5                | Note PDF, email, merge fields, client area, `print_count`                                                                                                                                                                                                                                         | 35, 36, 49, 50          |
| 0c (CTO, kernel) | Report registry, DTOs, `SafeCsv` (with `ErrorsCsv` switched to it), synchronous CSV and PDF export; queued export and `report_exports` deferred to the Reports module (ADR 0025). Lands just before PR 6                                                                                          | 45                      |
| 6                | `VatRegisterReport` and `TaxNoteRegisterReport` on the registry: rows, totals, header block, screens (BS month, BS fiscal year, AD range; note tab; warnings)                                                                                                                                     | 37–43                   |
| 7                | The `detail` and `ird` layouts and the PDF through the pipeline; export permissions                                                                                                                                                                                                               | 44, 46, 47              |
| later            | Trigger after its own ADR (ADR 0024 §9); queued export with the Reports module; `{BS_FY}` after D2                                                                                                                                                                                                | 52                      |
