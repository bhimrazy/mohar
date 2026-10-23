# VAT sales register and credit notes — Product Spec (v1)

Status: draft for engineering, reworked after the round-2 re-checks (CTO decisions of 2026-10-22). Built on ADR 0024
(fiscal documents), ADR 0013 Amendment 1 (company clock) and ADR 0025 (report registry), all Proposed in #329. **The
snapshot and backfill, lock order, refund state machine, mode start and build order are stated only in ADR 0024
(§2, §4, §5, §5a, §8, §10). This spec refers to them and gives their acceptance criteria; it does not restate them.**
· Owner: Billing workstream · Refines
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

- `billing.nepal_vat.enabled`, set in Admin → Settings → Billing → Tax by staff with `billing.settings.manage` and a
  recent password. It is an explicit opt-in: the seller country and VAT number only pre-fill the suggestion. **MUST**
- **Mode start, enable-time refusal and locked settings:** ADR 0024 §8. In short, `enabled_from` is the first day of
  the current or a later BS month, and the mode takes effect on that day. The settings page shows the next allowed
  date, lists any rows that block enabling, and links to the staff-review list (ADR 0024 §2). Criteria: AC 26, 52.
  **MUST**
- Enabling and every later change of the setting write an activity entry. **MUST**
- **Tax invoice moment (owner decision D1, 2026-10-22).** An invoice becomes a tax invoice on payment. Enabling the
  mode turns proforma mode on (`sequential_paid_numbering` and `set_issue_date_on_payment` true; Mohar already
  implements both in `InvoiceSettlement::settle`), and the settings lock of ADR 0024 §8 keeps it on. Unpaid invoices carry a proforma
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

Columns added to `invoices` in PR 1a. All but the last two are frozen with the number:

- `numbered_at`;
- `fiscal_taxable`, `fiscal_exempt`, `fiscal_tax1`, `fiscal_tax2`, `fiscal_total`;
- `report_currency`, `report_rate`;
- `supply_class`;
- `bs_issue_date`, `bs_period`, `bs_fiscal_year`;
- `credited_total`, `debited_total`: caches of the value adjustments after `numbered_at`, kept by
  `InvoiceSettlement::adjust` and recomputed by `billing:fiscal-snapshot` (ADR 0024 §2). They are not frozen.

What each holds, how it is computed at numbering, and how existing invoices are backfilled (including the
staff-review list) is ADR 0024 §2. The caps they feed are ADR 0024 §3. Criteria: AC 7–10. **MUST**

- `billing.tax.report_currency` names the reporting currency: NPR for a Nepal seller, INR later for India. Which rate
  a host must use is open (D6). Until D6 names an export rule, no document is `export`.
- `Invoice::FROZEN` gains `number`, `numbered_at`, `issue_date`, `client_snapshot`, `seller_snapshot` and the columns
  above, except the two caches. The one change allowed is the proforma → numbered transition inside `settle`. **MUST**

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

### 4.3a Refund reservations

`tax_note_reservations` and `tax_note_reservation_lines`: the columns, states and transitions are in ADR 0024 §5.
**MUST**

### 4.4 Rows a note writes, and the value-adjustment set

- **Value adjustments** (they change the value of the supply): `credit_note`, `debit_note`, and the old reasons
  `cancellation`, `item_cancellation`, `refund`, `late_fee`, `manual`. Caps, the cached totals and the remainder rule
  read this set, each row with its sign (an `item_cancellation` of a discount line, or the `cancellation` written by
  `ReopenInvoice`, is a debit). How old-style rows count is ADR 0024 §2 and §3. Old rows are never rewritten.
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
  fiscal sequence is 1 while the mode is on (ADR 0024 §8). The two formats must differ (validated). Notes never share the
  invoice sequence. **MUST**
- **Company clock:** every fiscal date comes from `App\Contracts\Core\CompanyClock` (setting `system.timezone`). The
  call sites, the scan and the boundary tests are listed in ADR 0013 Amendment 1. Criteria: AC 43, 48. **MUST**
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
6. Lock order: ADR 0024 §4. **MUST**
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

A credit note settled by a gateway refund follows the reservation state machine in ADR 0024 §5: reserve, provider
call, then finalise or release, for every actor and provider result. The failed or unknown cases issue no note.
Staff-facing wording:

- after `DECLINED`: "The gateway refused the refund. Retry, record a refund made outside, or credit the account
  instead.";
- while `ERROR` or `PENDING`: "Refund outcome not known yet." with only **Check refund**, never Retry.

Criteria: AC 28–30, 49–51, 53, 54. **MUST**

"Refunded outside" and account credit have no network step, and are issued in one transaction (§5.1 step 7).

### 5.4 Every existing path that changes a tax invoice

| Path on `develop`                                                                                                                                                                                                                                              | Writes today                                                                | In Nepal VAT mode, on a tax invoice                                                                                                                                                                                                                                                | Reason code / text                                                    | Actor               | Settlement                                          |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------- | --------------------------------------------------- |
| Staff cancel, single or bulk (`InvoiceActionController` → `CancelInvoice`)                                                                                                                                                                                     | credit `cancellation` = balance; applied credit returned (`credit_removed`) | credit note for the open balance. Applied credit is returned first, as today, and is **not** part of the note. Payments stay on the invoice; refunding them later is a further note (row "staff refund")                                                                           | `invoice_cancelled` / the cancel dialog's note, required in the mode  | staff               | balance                                             |
| Auto-cancel (`BillingAutomation` step 4)                                                                                                                                                                                                                       | credit `cancellation`                                                       | credit note; auto-cancel already picks only invoices with no payments and no applied credit                                                                                                                                                                                        | `invoice_cancelled` / "Automatically cancelled after N days overdue." | automation          | balance                                             |
| `Invoicer::cancel`: Orders `CancelOrder`, `DeleteOrder`, `MarkOrderFraud`; Services `CancelOpenRenewals`, `ExpireUnpaidChanges`; Domains `RenewalInvoices`. These hold their own row locks before Billing's invoice lock (named in the kernel-changelog entry) | as `CancelInvoice`, no reason                                               | credit note, as staff cancel                                                                                                                                                                                                                                                       | `invoice_cancelled` / "Cancelled by <module>" (ADR 0024 §7)           | automatic (module)  | balance                                             |
| Cancel lines (`CancelInvoiceItems`)                                                                                                                                                                                                                            | credit `item_cancellation` per line                                         | one credit note with one line per cancelled line. If the selection cancels the last open line, the action issues a single `invoice_cancelled` note for it and calls `CancelInvoice` in a mode that writes no further value adjustment: **one note, not two**                       | `lines_cancelled` or `invoice_cancelled` / staff text, required       | staff               | balance                                             |
| Late fee (`ApplyLateFee`)                                                                                                                                                                                                                                      | debit `late_fee`                                                            | debit note                                                                                                                                                                                                                                                                         | `late_fee` / "Late payment fee"                                       | automation          | adds to the balance                                 |
| Reopen (`ReopenInvoice`)                                                                                                                                                                                                                                       | debit `cancellation`                                                        | refused for every tax invoice. Staff raise a new invoice instead. ("Reissue" is not built; `billing.md` §10 lists it only.)                                                                                                                                                        | —                                                                     | —                   | —                                                   |
| Staff refund of a payment (`RefundTransaction`, gateway, record-only or to credit)                                                                                                                                                                             | on a paid invoice: credit `refund`                                          | split as the callback does, credit first: the part up to the account credit that payment placed (overpayment) is a plain refund with no note; the rest is the invoice part and goes through a credit note (§5.3)                                                                   | `refunded` / staff text, required                                     | staff               | refund (or account credit when mode is "to credit") |
| Provider-initiated refund (`GatewayCallbackHandler::refunded` → `RefundTransaction` record-only)                                                                                                                                                               | on a paid invoice: credit `refund`                                          | booked as a provider-initiated refund, by the rule in ADR 0024 §5 (credit first, automatic note, take-back of note credit when the cap is used up, review for the rest, deduped on the provider's refund id). A callback for one of our reservations follows the ADR 0024 §5 table | `refunded_at_provider` / "Refunded at <gateway> (<refund id>)"        | automatic (gateway) | refund (record-only)                                |
| Refund of a payment on an unpaid invoice                                                                                                                                                                                                                       | no adjustment; balance goes up                                              | no note: the sale is unchanged, the client owes again                                                                                                                                                                                                                              | —                                                                     | —                   | —                                                   |
| Chargeback (`RecordChargeback`) and dispute won                                                                                                                                                                                                                | no adjustment (`amount_paid` changes, status `collections`)                 | no note; staff decide later (A5)                                                                                                                                                                                                                                                   | —                                                                     | —                   | —                                                   |
| Mark paid (`MarkInvoicePaid`, `write_off`), apply or remove credit, overpayment                                                                                                                                                                                | settlement adjustments                                                      | no note; the sale keeps its value (A6)                                                                                                                                                                                                                                             | —                                                                     | —                   | —                                                   |
| `manual`, `chargeback` adjustment reasons                                                                                                                                                                                                                      | defined in `AdjustmentReason`, no writer on `develop`                       | must not be written on a tax invoice; any value change goes through the note issuer (architecture test)                                                                                                                                                                            | —                                                                     | —                   | —                                                   |

- Proforma invoices keep today's behaviour on every path (no note, nothing in the register). **MUST**
- Each row is a **MUST**; the architecture test (`tests/Arch`) checks that only the note issuer writes value
  adjustments on numbered invoices while the mode is on.
- Race tests: the list in ADR 0024 §4. **MUST**

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
- Through the gateway, against an earlier payment of the client chosen by staff whose refundable amount covers it:
  the `RefundAccountCredit` action, with its credit hold and permissions, as ADR 0024 §5a defines it. **SHOULD**
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
  after `enabled_from` (ADR 0024 §8). **MUST**
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

| Key                                          | Grants                                                                                                | Sensitive (step-up) |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------- |
| `billing.tax_notes.view`                     | see notes in admin, download their PDFs                                                               | no                  |
| `billing.tax_notes.issue`                    | issue credit and debit notes (settlement to balance or credit)                                        | yes                 |
| `billing.refunds.manage` (exists)            | additionally required when a note's settlement refunds money                                          | yes                 |
| `reports.vat_register.view`                  | see the register (registered by the report registry)                                                  | no                  |
| `reports.vat_register.export`                | export it in any format                                                                               | yes (ADR 0025 §4)   |
| `reports.tax_note_register.view` / `.export` | the same for the note register                                                                        | export: yes         |
| `billing.credit.refund`                      | pay account credit out (§5.6); the gateway variant also needs `billing.refunds.manage` (ADR 0024 §5a) | yes                 |

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

- `CreditNoteIssued(int $taxNoteId, string $number, int $invoiceId, int $clientId, int $amount, string $currency,
  bool $automatic)` **MUST**
- `DebitNoteIssued(...)` with the same fields. **MUST**
- Existing events keep their meaning: `InvoiceCancelled` still fires on cancellation (after the note),
  `InvoiceModified` when a partial note changes the balance, `InvoiceRefunded` when a note refunds money,
  `LateFeeApplied` for a late-fee debit note. **MUST**

Consumed by:

- Notifications: "Credit note issued" / "Debit note issued" email with the PDF; webhook events `credit_note.issued`
  and `debit_note.issued` (one class to one key, registered by Billing's provider with `WebhookEvents::register()`); merge fields `tax_note.*` through
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
  registry and one export pipeline", ADR 0013 Amendment 1. The rules listed in the status line are stated there only.
- **CBMS** is not built (owner decision D5, 2026-10-22). The model is ready for it: BS date, the fiscal year in the
  CBMS form, buyer PAN, the amount split CBMS expects, the reference invoice and reason on notes, `cbms_status`
  columns. A later driver would post through an outbox, retry a bill by its invoice number, and never retry a note
  blindly (F9).

## 12. Edge cases

- **Invoice numbered before the mode** (legacy): takes notes like any other. Its caps and backfill are ADR 0024 §2
  and §3. It is in the register only if dated on or after `enabled_from`, and no old-style row can fall in a register
  period (ADR 0024 §8).
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
- **Gateway refund while the client pays the rest**, and **a gateway timeout**: ADR 0024 §5.
- **Date outside the BS table** (after 2095 BS): no tax invoice or note can be numbered in the mode; staff see why.
- **Partial payment, then cancel** (legacy or proforma off): the note covers the open balance; the payments stay, and
  refunding them is a further note.
- **Credit applied, then cancel**: the applied credit is returned through `credit_removed` as today and is not part of
  the note; the note covers the balance after that return.
- **Concurrent notes, or a note racing a payment or refund**: ADR 0024 §4.
- **Tax rule changed** after numbering: the note uses the invoice's snapshot rates.
- **Discount lines**: credited with their parent line, never on their own.
- **Client changes PAN** after the invoice: the note keeps the invoice's buyer snapshot.
- **Imported invoices** (importer phase 2): imported tax invoices get `numbered_at` and the snapshot at import and take
  notes; they are in the register only if dated on or after `enabled_from`.

## 13. Acceptance criteria

Which PR each criterion first passes in is ADR 0024 §10.

MUST:

1. Given the mode and a tax invoice of Rs 11,300 (10,000 + 13% VAT), when staff issue a full credit note, then it
   is numbered `CN-1`, references the invoice number and its BS date, and has taxable 10,000.00 and VAT 1,300.00.
2. Given two notes issued concurrently on different invoices, then they get consecutive distinct numbers; a
   rolled-back note consumes no number.
3. Given lines of 5,000 and 6,300 gross, when staff credit only the second, then the note has one line and 5,000
   is still creditable.
4. Given an invoice numbered at publish before the mode, with a 1,000 `item_cancellation` written after
   numbering, then at most total − 1,000 can be credited.
5. Given a note by amount, then by line, then by amount that together credit an invoice in full, then each note
   has lines, and per line and per tax level they sum exactly to the invoice's snapshot.
6. Given a tax rule changed from 13% to 15% after numbering, then a credit note on the invoice uses 13%, and the
   note's `report_rate` equals the invoice's.
7. Given a proforma paid in full, then `numbered_at` is set in that transaction; given it later goes to
   `collections` and is paid again, then `paid_at` changes and `numbered_at`, the number and the snapshot do not.
8. Given a proforma with a 500 taxable late fee, and one line cancelled in the same `CancelInvoiceItems` call that
   settles it, then `fiscal_*` equal the totals plus the fee minus the cancelled line, each with its tax.
9. Given `billing:fiscal-snapshot` run on a database with legacy invoices, then:
    - a publish-numbered invoice gets `numbered_at` = its `created_at` and a snapshot equal to its own totals;
    - a payment-numbered invoice gets `numbered_at` = the `created_at` of its numbering payment row, even when that
      payment's `occurred_at` was backdated;
    - an invoice without one identifiable numbering payment, a line-less row and a misclassified row all appear on
      the staff-review list;
    - `credited_total` and `debited_total` equal the value adjustments after `numbered_at`, also after a value row
      written by the previous release, which the command corrects and lists.
10. Given a numbered note or invoice, when any model path changes a frozen field, deletes the note, or deletes its
    invoice, then the change is refused.
11. Given a preview, when the invoice changes before confirm (a payment, another note), then the confirm is refused
    with "The invoice changed; review the note again".
12. Given two processes, then the race tests of §5.4 for notes (two notes on one invoice, note against payment,
    note against refund) leave the caps and the balance invariant intact.
13. Given a paid invoice and settlement "account credit", then exactly these rows exist: a `credit_note` credit
    adjustment, a `credit_note_to_credit` debit adjustment and a `credit_ledger` +amount row, all with `tax_note_id`;
    the balance is 0 and the invoice is `paid` and "Credited".
14. Given a mistaken credit note to account credit, when staff reverse it, then a `reverses_credit_note` debit note
    with the same lines and tax exists, the credit is taken back and the balance is where it was.
15. Given staff without `billing.tax_notes.issue`, then the issue buttons are absent and the endpoint returns 403;
    with it but without a recent password, the confirm asks for one.
16. Given staff without `billing.refunds.manage`, then the refund settlements are not offered.
17. Given a note issued, then an activity entry `billing.tax_note.issued` with number, invoice, total, settlement
    and actor exists.
18. Given a credit note on a paid invoice, when the preview opens, then account credit is selected and the refund
    options are offered beside it (D3).
19. Given a client with 1,300 of account credit, when staff with `billing.credit.refund` and a recent password
    record a refund of 1,000 made outside, then a −1,000 `credit_refunded` ledger row and a `refund` transaction exist,
    no note is issued and no invoice changes; without the permission the action is absent.
20. Given a credit note issued, then `CreditNoteIssued` with its number fires once after commit and the
    `credit_note.issued` webhook is queued; a debit note fires `DebitNoteIssued` and queues `debit_note.issued`.
21. Given a tax invoice numbered at publish before the mode, cancelled by staff, by auto-cancel, and by
    `Invoicer::cancel` from each of the six callers in §5.4, then each issues one `invoice_cancelled` note with the
    reason text of §5.4 and no `cancellation` adjustment.
22. Given cancel lines that cancels the last open line, then exactly one note is issued.
23. Given a proforma invoice cancelled, then no note is issued and nothing appears in the register.
24. Given a late fee on a legacy unpaid tax invoice, then a `late_fee` debit note is issued and shown as a positive
    register row.
25. Given any tax invoice in the mode, then "Reopen" is refused.
26. Given staff enabling the mode, then:
    - `enabled_from` is accepted only as the first day of the current or a later BS month;
    - before that day the issuer refuses notes and the old paths run;
    - from 00:00 Nepal time that day, notes replace them;
    - enabling is refused while the staff-review list is unreviewed, or while a post-numbering old-style row is dated
      on or after `enabled_from` (a late fee committing concurrently is either before the check or blocked);
    - proforma mode turns on, numbering and issue-date settings become read-only with increment 1, and an activity
      entry is written;
    - switching off is refused once a note exists.
27. Given the mode on, when a proforma is paid, then it gets the next legal number, its issue date is the company
    date of `numbered_at` and it appears in that BS month's register (D1).
28. Given settlement by gateway refund:
    - when the provider answers `DECLINED`, then no note exists, no number is consumed, the refund row is failed and
      the reservation is `released`;
    - when it answers `SUCCESS`, then exactly the reserved allocation is issued with the succeeded refund row;
    - when the client paid the remaining balance during the provider call, then the excess is account credit and the
      balance is 0, not negative.
29. Given a by-line refund reservation on line B pending, when another staff member credits line B to account,
    then it is refused by the line cap.
30. Given a crash after the provider's success, when the provider's refund callback arrives (or staff press "Check
    refund"), then the pending reservation is finalised once, and a repeat does nothing.
31. Given a provider-initiated refund of a paid tax invoice, then an automatic credit note `refunded_at_provider`
    with the provider's refund id in its text is issued in the same transaction as the refund row.
32. Given a paid invoice credited in full to account, when the provider later refunds the payment, then no note is
    issued, the credit that note placed is taken back, and the rest goes to "Gateway payments to review" (ADR 0024
    §5, provider-initiated refunds).
33. Given a payment of 12,000 on an 11,300 invoice (700 overpayment in credit), when staff refund 1,000, then 700
    is a plain refund and 300 goes through a credit note.
34. Given two processes, then two refund reservations on two payments of one invoice respect the cap, and a
    provider refund callback racing a staff note to account credit completes without a deadlock.
35. Given a client, then they see and download only their own notes; another client's note URL returns 404.
36. Given the note PDF, then it shows "Credit Note", seller PAN/VAT number, buyer PAN, both dates, the referenced
    invoice and the reason.
37. Given an invoice dated in Kartik 2083 and its credit note in Mangsir 2083, then the Kartik register shows the
    invoice and the Mangsir register shows the note as a negative row.
38. Given a register period, then net VAT equals Σ invoice VAT − Σ credit-note VAT + Σ debit-note VAT exactly, and
    the "Return figures" box shows the same numbers.
39. Given the annual view for 2082/83, then it covers 1 Shrawan 2082 to the last day of Asar 2083 in 12 month rows.
40. Given a business client without a PAN, then the row has a blank PAN and the page warns; nothing is blocked.
41. Given a USD invoice with `report_rate` `134.250000000000`, then its register amounts are computed in `Decimal`,
    rounded per document, unchanged after the rate table or the tax rule is edited, and a full credit note on it nets
    to exactly zero in NPR.
42. Given an add-funds invoice, a draft, or a tax invoice dated before `enabled_from`, then none appears in the
    register, and periods before `enabled_from` are not offered.
43. Given the register opened at 18:15:00 UTC on the last day of a BS month, then the default period is that month
    (now closed); at 18:14:59 UTC it is the month before.
44. Given the IRD-layout CSV, then its columns are in the prescribed order with the template's Nepali headings,
    declaration number and date in separate columns, and header rows with seller PAN, name, fiscal year and period.
45. Given a buyer named `=HYPERLINK(...)`, then the CSV cell starts with `'=`, while a credit-note amount
    `-1300.00` is written unprefixed; every export writes one `report.exported` entry with layout, format, filters and
    row count.
46. Given staff without `reports.vat_register.export`, then the exports are absent; with it, an export asks for a
    recent password.
47. Given the register, then the exports offered are CSV (detail), CSV (IRD layout) and PDF, and issuing a document
    sends nothing to CBMS; `cbms_status` stays null (D4, D5).
48. Given a proforma paid at 18:14:59 UTC and another at 18:15:00 UTC on the last day of Asoj 2083,
    then the first is dated the last day of Asoj and the second 1 Kartik, in their BS periods; a credit note issued at
    18:20 UTC that day is dated 1 Kartik.

49. Given a gateway refund whose request times out (`ERROR`), then the reservation stays `pending`, staff are offered
    only "Check refund" (no Retry) and no second refund row exists. When the provider's callback then reports
    `SUCCESS`, exactly one note and one refund exist.
50. Given the callback has finalised a reservation, when the request's late `ERROR` or `DECLINED` arrives, then nothing
    changes: the note, the succeeded refund row and `refundable_remaining` stay as they were.
51. Given a `PENDING` answer, when staff press "Check refund" and the provider answers `DECLINED` for the same
    reference, then the reservation is `released`, `refundable_remaining` is restored, and Retry is offered with a new
    reservation and reference.
52. Given the mode starting on 1 Kartik 2083, when a late-fee transaction captures its instant at 23:59:59.9 Nepal time
    on the last day of Asoj and commits after midnight, then its row's `created_at` is that instant (dated Asoj), it is
    old-style, and the Kartik register holds no old-style row. A late fee at 00:00:00 on 1 Kartik is a debit note.
53. Given a reservation `released` after `DECLINED`, when the provider later reports `SUCCESS` for it, and the
    callback and Check refund both report it, then it is booked once as a provider-initiated refund (ADR 0024 §5).
    When staff had credited the sale to the account after the release, that credit is taken back.
54. Given the mode enabled with `enabled_from` = 1 Kartik 2083, when staff refund at 23:59 Nepal time on the
    last day of Asoj, the request returns `ERROR`, and the callback reports `SUCCESS` after midnight, then the
    reservation was made, one credit note dated 1 Kartik is issued, and no `refund` adjustment exists. Enabling is
    refused while a refund written by the old path is still pending.

SHOULD:

55. Given an invoice PDF downloaded twice in the mode, then the second copy says "Copy of original — print 2" and
    `print_count` is 2.
56. Given a note issued with "Send to client" ticked, then the client gets the email with the PDF attached.
57. Given staff with `billing.credit.refund` and `billing.refunds.manage` refund account credit through the gateway
    against an earlier payment, then a `credit_refunded` row is posted before the provider call (so `ApplyCredit`
    cannot spend the credit), the payment's refundable amount falls, and its invoice does not change. On `DECLINED`
    it is reversed by `credit_refund_released`; on `ERROR` it stays until Check refund resolves it; `SUCCESS` after a
    decline re-posts it once and opens a to-do (ADR 0024 §5a). Without
    `billing.refunds.manage`, the gateway variant is not offered.
58. Given `{BS_FY}` in the note format and reset `bs_fiscal_year` (only if D2 goes that way), then the first
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

ADR 0024 §10: the PRs, their content, and which acceptance criteria each one must pass.
