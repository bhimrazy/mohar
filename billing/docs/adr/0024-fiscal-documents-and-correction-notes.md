# ADR 0024 — Fiscal documents and correction notes

- Status: Proposed · 2026-10-22 · Owner: Principal architect. Revised after the review-board round on #329, with
  CTO decisions 1–7 of 2026-10-22, which also bind `vat-register.md`.
- Related: `billing/docs/product/vat-register.md` (PR #327) and its reviews; ADR 0002 (money), ADR 0010 (contract
  evolution), ADR 0011 (events), ADR 0013 Amendment 1 (company clock), ADR 0021 (confirm-then-apply, lock order),
  ADR 0025 (report registry).

## Context

- An invoice with a legal `number` is a tax document. Today Billing changes its value through `invoice_adjustments`
  rows that carry no number: `cancellation`, `item_cancellation`, `refund` and `late_fee`. These rows are correct for
  the balance, but a VAT register cannot see them.
- `Invoice::FROZEN` (`Invoice.php:84`) freezes amounts and tax. It does not freeze `issue_date`, `client_snapshot`
  or `seller_snapshot`.
- In proforma mode (`sequential_paid_numbering`), an invoice is numbered when it is paid (`InvoiceSettlement::settle`).
  A late fee or line cancellation made before that payment is in the amount paid but not in `total`. No column
  records when an invoice was numbered. `paid_at` cannot stand in for that moment: it is set again when a
  `Collections` invoice is paid later (`InvoiceStatus.php:22`, `InvoiceSettlement.php:72`).
- Refunds run in three phases (`RefundTransaction`). Phase 1 locks only the payment (`:60-61`). The provider is then
  called with no locks held, and phase 3 finalises. A provider callback for a refund still pending gets 409 until
  that refund is finalised (`GatewayCallbackHandler.php:264-266`).
- `CurrencyConverter::rate()` returns `float` (`CurrencyConverter.php:14`), although it computes the rate with
  `Decimal` internally.

## Decision

### 1. Fiscal documents

- A **fiscal document** is either an invoice with a legal `number` or a credit or debit note (`tax_notes`). Drafts,
  invoices that only have a proforma number, and add-funds invoices are not fiscal documents.
- A fiscal document gets its number **in the transaction that creates it**, from a gap-free `DocumentNumbers`
  sequence. There are no draft notes. Each sequence has its own format, padding and reset. While Nepal VAT mode is on,
  the increment of every fiscal sequence is fixed at 1 (§8).
- The same transaction sets **`numbered_at`**, the instant of numbering. This is a new invoice column from PR 1a.
  Notes are numbered when they are created, so their `created_at` is that instant. Nothing ever infers the numbering
  moment from `paid_at`.
- A numbered document is **immutable**. The model refuses every change except the columns on an explicit allow-list:
  on notes, `print_count` and the reserved `cbms_*` status. The invoice freeze grows to cover `issue_date`,
  `numbered_at`, `client_snapshot`, `seller_snapshot`, the value snapshot and the BS fields. A proforma is allowed
  exactly one transition, the one that assigns its number.
- **Correction only by note.** Once an invoice is numbered, its value changes only through a credit or debit note
  against that one invoice. A mistaken note is reversed by an opposite note, never edited or deleted. Adjustments that
  move money without changing the supply keep their current form: `credit_applied`, `credit_removed`,
  `overpayment` and `write_off`.
- A note writes exactly one value adjustment (`invoice_adjustments.tax_note_id`). **Settlement exists only as rows
  that carry `tax_note_id`**; there is no settlement field on the note:
    - account credit: a debit adjustment with reason `credit_note_to_credit`, plus a credit-ledger post;
    - refund: the refund transaction.

    The balance is never negative at commit.

- `tax_notes` references the invoice and the client with `restrictOnDelete`. Fiscal documents are never pruned.

### 2. Snapshot at numbering

When an invoice is numbered, it stores the values that the register, the caps and the remainder rule read. Nothing
recomputes them later from current tax rules, rates or the calendar.

- **Tax-invoice value:** taxable, exempt, tax1 and tax2 (name, rate and amount), and total. The value is `total` plus
  the **signed** sum of the value adjustments created up to and including `numbered_at`. Rows written in the
  numbering transaction count as before it (`CancelInvoiceItems` writes its row and then calls `settle()`,
  `:61`/`:70`). Debits add and credits subtract: `item_cancellation` is a debit for a discount line
  (`CancelInvoiceItems.php:51`), and `cancellation` is a debit when an invoice is reopened (`ReopenInvoice.php:38`).
- **Reporting rate:** `report_currency` and `report_rate`, a decimal string at scale 24,12. Floats are never used.
  `CurrencyConverter` gains `rateDecimal(string $from, string $to): string`, which returns the `Decimal::divide` value
  that `rate()` currently casts to float. Conversion uses `Decimal::multiply` followed by `Decimal::round(…, 0)`,
  which rounds half up. The rate never comes from an `ExchangeRateSource` call.
- **Classification:** taxable, exempt or export, as the tax rule applied (D6).
- **BS fields:** `bs_issue_date`, `bs_period` and `bs_fiscal_year`, taken from the company date (ADR 0013
  Amendment 1).

A note stores its own BS fields and **copies the invoice's `report_currency` and `report_rate`**. A fully credited
foreign-currency invoice therefore nets to zero in the reporting currency, and a later correction to the BS table
moves no filed document from one period to another.

**Backfill (PR 1a migration, not at mode enable).** The migration fills the snapshot for invoices already numbered.
It runs in chunks and is tested on PostgreSQL.

- `numbered_at` is backfilled from the first instant at which the number can be shown to exist:
    - **Numbered at publish** (`proforma_number` is null). A draft carries no adjustments, so every value adjustment
      on such an invoice was written after numbering, and the snapshot is the invoice's own totals. `numbered_at` is
      the invoice's `created_at`. That is never later than publishing, while a staff-chosen `issue_date`
      (`PublishInvoice.php:69`) can be. Every adjustment on these invoices counts as after numbering, so none is
      folded into the snapshot.
    - **Numbered on payment** (`proforma_number` is set). `numbered_at` is the `occurred_at` of the earliest
      succeeded payment that brought the balance to zero. When no single payment can be identified (several
      payments, credit applied in between, a later collections cycle), the invoice goes on the review list below.
- The value is computed by the rule above. `credited_total` and `debited_total` are filled in the same pass, from the
  value adjustments after `numbered_at`.
- Rows the migration cannot classify as taxable or exempt go on a **staff-review list**, and Nepal VAT mode cannot be
  enabled until staff have seen it. The list holds:
    - invoices whose numbering instant cannot be derived;
    - line-less rows;
    - `item_cancellation` rows that store their net as `taxable_amount` on an untaxed line.

    Listed rows count against the invoice cap only, never against a line cap (§3).

- Classification of old rows:
    - `late_fee` is taxable for its `taxable_amount`; the rest after tax is exempt.
    - `item_cancellation` is classified by matching its line, using the description and the gross amount. When tax is 0
      and the invoice has no taxable line, the row is exempt. Any other unmatched row goes to the review list.

### 3. Caps, allocation and rounding

- Every note is allocated to invoice lines in `tax_note_lines`. A note "by amount" is spread pro rata across the
  open lines. A line's cap is its gross amount minus the credits allocated to it so far.
- The invoice cap is the tax-invoice value + the signed sum of value adjustments **after `numbered_at`** (notes and
  old-style rows alike) − pending refund reservations (§5). `credited_total` and `debited_total` cache those sums,
  and `InvoiceSettlement::adjust` is their only writer.
- An old-style `item_cancellation` after `numbered_at` that §2 matches to its line counts against that line's cap
  and against the invoice cap.
- A `late_fee` is classified (taxable or exempt) but belongs to no line, so it counts against the invoice cap only.
  The same holds for a line-less row (`cancellation`, `refund`, or an unmatched row on the review list). `CancelInvoice.php:55` and `RefundTransaction.php:187` store no tax split for these rows. For the
  invoice-level tax remainder only, their tax is prorated from the snapshot.
- The register never reads old-style rows (§8).
- Rounding is half up per line and per tax level. The note that brings a line's remainder to zero takes the exact
  remaining tax at each level. A fully credited invoice therefore nets to zero tax in minor units.

### 4. Concurrency and lock order

- Issuing a note follows ADR 0021 §1. The confirm carries the invoice `version` and a digest of the previewed
  allocation. The issuer recomputes both under the lock and refuses if either has changed.
- **Lock order:** payments in id order, then the invoice, then the client, then the sequence row. A
  `billing_sequences` row is shared by every document of its sequence across the install, so it is taken last, just
  before the insert. A sibling module that calls `Invoicer::cancel` (§7) holds its own row lock in front of this
  chain. The order is added to ADR 0021 §3.
- The provider refund callback follows the same order. Today `reverseCredit` locks the client
  (`GatewayCallbackHandler.php:654`) before the invoice. PR 4 changes the callback to lock the invoice first.
- Race tests (ADR 0021 §4, two processes, PostgreSQL):
    - two notes on one invoice (the cap);
    - a note against a payment;
    - a note against a refund;
    - two refund reservations on two payments of one invoice;
    - a provider refund callback against a staff note settled to account credit (deadlock);
    - two notes on different invoices (no gap in the numbers);
    - enabling the mode against an old-path adjustment (§8).

### 5. Refunds and notes

1. **Reserve.** Lock the payments, then the invoice (§4). Check the reservation against the line and invoice caps,
   counting the reservations already pending. Store the full allocation with the pending refund row: each line, its
   tax per level, the reason code and the reason text. No note is created yet.
2. **Call the provider** with no locks held. If the provider refuses, release the reservation; nothing is issued.
3. **Finalise** in one transaction, under the same lock order:
    - issue exactly the reserved allocation as a note;
    - set the refund row's `tax_note_id`, reduce `amount_paid` and set the status;
    - re-read the balance. The refund amount is fixed, so any excess that would make the balance negative (for example
      a payment that arrived during step 2) goes to account credit through `credit_note_to_credit`.

    Finalising never refuses after the provider has paid, and it is idempotent, keyed by the reservation. A retry
    after a crash, or the provider's callback for its own refund, finalises the pending reservation instead of
    answering 409.

Refunds that start at the provider (`GatewayCallbackHandler::refunded`) reserve and finalise in one transaction,
with an automatic note: actor "gateway", reason `refunded_at_provider`. Staff refunds use the callback's rule
"credit first, then the invoice" to decide which part changes the supply.

### 6. Events (ADR 0011)

One event per kind, because a webhook type maps to exactly one class:

```php
final class CreditNoteIssued implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $taxNoteId,
        public string $number,
        public int $invoiceId,
        public int $clientId,
        public int $total,       // minor units, positive
        public string $currency,
        public bool $automatic,  // issued by automation, a gateway callback or a sibling module
    ) {}
}
// DebitNoteIssued: same payload.
```

- **Dispatch:** the events implement `ShouldDispatchAfterCommit` (ADR 0011, as `PaymentReinstated` already does) and
  are dispatched with `event()`. They are **never** passed to `AfterCommit::dispatch`, which would defer them a
  second time.
- Webhook types `credit_note.issued` and `debit_note.issued` go into `WebhookEventTypes::MAP` until the registration
  method from ADR 0011 exists.
- Existing events keep their meaning:
    - `InvoiceCancelled` fires after the cancelling note;
    - `InvoiceModified` fires on a partial note;
    - `InvoiceRefunded` fires when a refund is finalised;
    - `LateFeeApplied` fires for a late-fee debit note.

### 7. `Invoicer::cancel` behaviour change (ADR 0010)

- With Nepal VAT mode on, `Invoicer::cancel(int $invoiceId)` on a numbered invoice issues a credit note
  (`invoice_cancelled`, automatic, reason text "Cancelled by <module>"). Every caller can therefore create a legal
  document:
    - Orders `CancelOrder.php:43` (admin cancel and `CancelStalePendingOrders`), `DeleteOrder.php:45`,
      `MarkOrderFraud.php:34`;
    - Services `CancelOpenRenewals.php:51`, `ExpireUnpaidChanges.php:44`;
    - Domains `RenewalInvoices.php:79`.

    Each caller holds its own row lock before Billing's invoice lock (§4).

- The signature is unchanged; the documented semantics change, which ADR 0010 counts as breaking. The pre-1.0 window
  allows it on three conditions:
    - the docblock is updated;
    - `docs/architecture/kernel-changelog.md` lists the change and the callers above;
    - the change passes the architect gate.
- A trailing `?string $reason = null` is a compatible addition, made when a caller needs to pass a reason.

### 8. Mode switch and locked settings

- `billing.nepal_vat.enabled` is an explicit opt-in, never derived from the seller's country or VAT number. Staff set
  `enabled_from`, a company date of today or later, and the change is written to the activity log. Notes replace the
  old paths from `enabled_from`: the mode is in effect when the setting is on and `CompanyClock::today()` ≥
  `enabled_from`.
- **The enable-time refusal.** Enabling locks the mode's settings row `FOR UPDATE`. Under that lock it refuses while
  any old-style value adjustment (`cancellation`, `item_cancellation`, `refund`, `late_fee`) on a numbered invoice was
  written after that invoice's `numbered_at` and is dated, by the company date of its `created_at`, on or after
  `enabled_from`. Pre-numbering rows are already in the snapshot.
- Old paths read the mode with `FOR SHARE` on the same row, after their invoice lock. A transaction that read "mode
  off" therefore commits before the enabling check runs, and the register never holds an old-style row. This is the
  CTO decision on B6, also in `vat-register.md` §3 and §12. Enabling is also refused while the staff-review list from
  §2 has not been seen.
- **While the mode is on:**
    - the increment of every fiscal sequence is 1;
    - `number_format`, `number_reset`, `number_padding`, `number_increment`, the note formats,
      `sequential_paid_numbering` and `set_issue_date_on_payment` are locked, and the settings page says why.
- Switching the mode off is refused once a note exists.

### 9. Database triggers

There are none in v1. The model guard and the tests enforce immutability. A PostgreSQL trigger cannot be exercised
on SQLite CI, and it would have to allow proforma numbering, so it needs its own ADR.

### 10. Build order

Each PR is at most 2,000 lines. The mode stays hidden until PR 4 merges. Acceptance-criteria numbers refer to
`vat-register.md` §13 at `8d27be29`; the spec's §18 follows this table.

| PR       | Content                                                                                                                                                        | AC                    |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| 0a (CTO) | `CompanyClock` contract, Core implementation, Billing fiscal call sites, boundary tests at 18:14:59 and 18:15:00 UTC, widened arch scan (ADR 0013 Amendment 1) | 14                    |
| 0b (CTO) | `CreditNoteIssued` and `DebitNoteIssued`, the `MAP` entries                                                                                                    | —                     |
| 1a       | Schema, `numbered_at`, snapshot at numbering, PR 1a backfill with the review list, `FROZEN`, per-sequence `DocumentNumbers`, `rateDecimal()`                   | 13, 20, 21            |
| 1b       | Note issuer (allocation, caps, remainder, digest), lock order, race tests                                                                                      | 1–6, 40               |
| 2        | Manual issue UI, settlement, step-up, permissions, notes panel, reversing note                                                                                 | 7, 22, 32, 33, 35, 38 |
| 3        | Old paths, every `Invoicer::cancel` caller, automation, enabling the mode with the locked refusal and locked settings                                          | 15–19, 29, 37         |
| 4        | Refunds: reservation with allocation, idempotent finalise, provider callback lock order (combined review)                                                      | 8–12                  |
| 5        | Note PDF, email, merge fields, client area, `print_count`                                                                                                      | 34, 36, 41, 42        |
| 0c (CTO) | Report registry, DTOs, `SafeCsv` with `ErrorsCsv` moved, synchronous CSV and PDF (ADR 0025). Lands just before PR 6                                            | —                     |
| 6        | `VatRegisterReport`, `TaxNoteRegisterReport`, screens                                                                                                          | 23–28                 |
| 7        | `detail` and `ird` layouts, PDF, export permissions                                                                                                            | 30, 31, 39            |
| later    | Trigger (§9); `{BS_FY}` after D2; queued export with the Reports module (ADR 0025)                                                                             | 43                    |

AC 40 (the event fires and the webhook is queued on issue) moves from PR 0 to PR 1b, the first PR that issues a note.

## Open questions (accountant)

- **D2:** Do note and invoice sequences reset each BS fiscal year (`{BS_FY}`, reset `bs_fiscal_year`)? Until this is
  answered, the sequences use `reset never`; the sequence period key is ready for `2083.084`.
- **D6:** Which rate converts to NPR? The stored rate on the numbering date is the default here, and notes copy it.
  When is a foreign client's invoice an export? Classification is stored, so a later answer does not rewrite filed
  documents.
- Bad-debt relief (spec A6): write-offs stay out of notes until the accountant says otherwise.

## Consequences

- One correction model, for Nepal now and India GST later. The register sums snapshots and notes and never
  re-derives them.
- All of these paths change behaviour in Nepal VAT mode: cancel, cancel lines, late fee, reopen, refunds started by
  staff or by the provider, and the six `Invoicer::cancel` callers. The mode stays hidden until every one of them
  issues notes.
- `DocumentNumbers` gets per-sequence settings. `invoice_adjustments.number` is no longer written for notes, so two
  note formats can never collide on its unique index.
- Numbering and issue-date settings become fixed for an install that has turned the mode on.
- Billing code that writes dates depends on ADR 0013 Amendment 1, which lands first as PR 0a.

## Alternatives considered

- **Corrections as plain adjustments with a number** (no `tax_notes`). Smaller schema, but no lines, no buyer
  snapshot and no BS fields per document. Rejected.
- **Numbering the note before the refund call, with a settlement field that can change.** This opens a window with a
  negative balance and breaks immutability. Rejected.
- **Backfilling at mode enable.** This would write frozen columns after PR 1a, and an unattended enable would
  silently classify old rows. Rejected in favour of the PR 1a migration with a review list.
- **Using `paid_at` as the numbering moment.** It is rewritten when a `Collections` invoice is paid again. Rejected;
  `numbered_at` is stored instead.
- **One `TaxNoteIssued` event with a kind enum.** One class would then map to two webhook types. Rejected.
- **A database trigger now.** It cannot be tested on SQLite and conflicts with proforma numbering. Deferred.
