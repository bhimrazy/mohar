# ADR 0024 — Fiscal documents and correction notes

- Status: Proposed · 2026-10-22 · Owner: Principal architect. Revised after the review-board rounds on #327 and
  #329, with the CTO decisions of 2026-10-22.
- **Single source of truth.** This ADR is the only statement of these rules:
    - the snapshot and backfill (§2);
    - the lock order (§4);
    - the refund state machine (§5, §5a);
    - the start of the mode (§8);
    - the build order (§10).

    `vat-register.md` refers to these sections and keeps only acceptance criteria for them.

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

**Backfill (PR 1a command, not at mode enable and not in a migration).** `php artisan billing:fiscal-snapshot` fills the
snapshot for invoices already numbered. A migration cannot be the last pass: the previous release keeps serving, and its
workers keep draining for up to an hour, so it numbers invoices and writes value rows after any migration has run
(`architecture.md` rule 1 also keeps backfills out of migrations). The command is capped (`--max`, default 5,000
invoices; chunks of 200 that lock only their own rows), safe to repeat, scheduled every five minutes
(`withoutOverlapping`, `onOneServer`) and run by `deploy/bin/deploy` after the switch. It is tested on PostgreSQL.

- **Reconcile.** Each run also recomputes `credited_total` and `debited_total` from the value adjustments after
  `numbered_at`, for invoices with an adjustment above a watermark. Ids are handed out at insert but rows become visible at commit, so
  the watermark moves only over rows older than one hour (the drain bound), up to the first younger row; each run
  scans everything above it again, and the recompute is absolute, so a repeat changes nothing. Invoices are
  recomputed 200 at a time, each chunk in its own transaction. A row held uncommitted for more than an hour is not
  covered. The previous release's
  `adjust()` writes value rows without the caches. Every invoice it corrects goes on the review list as
  `cached_totals_reconciled`.
- **Residual risks.** Timestamps have one-second precision, so a previous-release row stamped in the second of
  numbering cannot be told from a row folded into the snapshot: the check accepts any cache between "after" and "at or
  after" `numbered_at`. A clock stepped back by more than a second just after numbering has the opposite effect (a
  correct cache is lowered and its entry reopened). Both are rare; the review list and `mismatches()` are the
  backstop, and no column records the last adjustment id at numbering.
- **Reporting rate of old invoices.** A foreign-currency invoice takes the exchange rate of the payment that numbered it
  when that payment was converted into the reporting currency; otherwise today's stored rate, listed as
  `report_rate_estimated`.

- `numbered_at` is backfilled from the first instant at which the number can be shown to exist:
    - **Numbered at publish** (`proforma_number` is null). A draft carries no adjustments, so every value adjustment
      on such an invoice was written after numbering, and the snapshot is the invoice's own totals. `numbered_at` is
      the invoice's `created_at`. That is never later than publishing, while a staff-chosen `issue_date`
      (`PublishInvoice.php:69`) can be. Every adjustment on these invoices counts as after numbering, so none is
      folded into the snapshot.
    - **Numbered on payment** (`proforma_number` is set). `numbered_at` is the `created_at` of the numbering payment
      row: the one succeeded payment whose application brought the balance to zero. It is never that row's
      `occurred_at`, which staff can backdate (`ApplyPayment.php:109`). When no single numbering payment can be
      identified (several payments, credit applied in between, a later collections cycle), the invoice goes on the
      review list below.
- The value is computed by the rule above. `credited_total` and `debited_total` are filled in the same pass, from the
  value adjustments after `numbered_at`.
- Rows the command cannot classify as taxable or exempt go on a **staff-review list**, and Nepal VAT mode cannot be
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
- A **debit note** adds at most the invoice's original value (`fiscal_total`). The bound is not in the spec; it stops a
  mistyped amount and keeps the tax arithmetic in range.
- Rounding is half up per line and per tax level. The note that brings a line's remainder to zero takes the exact
  remaining tax at each level. A fully credited invoice therefore nets to zero tax in minor units.

### 4. Concurrency and lock order

- Issuing a note follows ADR 0021 §1. The confirm carries the invoice `version` and a digest of the previewed
  allocation. The issuer recomputes both under the lock and refuses if either has changed.
- **Lock order:** payments in id order, then the invoice, then the client, then the invoice's pending reservations, then the sequence row. A
  `billing_sequences` row is shared by every document of its sequence across the install, so it is taken last, just
  before the insert. A sibling module that calls `Invoicer::cancel` (§7) holds its own row lock in front of this
  chain. The order is added to ADR 0021 §3.
- Reservation rows (`tax_note_reservations`, §5) are locked after the client:
  payments → invoice → client → reservation → sequence. The client comes before the sequence because a note's insert
  takes a key-share lock on it, and the refund paths hold the client for update and then ask for the next number (a
  deadlock the other way round; race test R4).
- The provider refund callback already follows this order. `lockParents` (`GatewayCallbackHandler.php:453-460`)
  locks the payments, then their invoices, both in id order, before `reverseCredit` locks the client (`:654`). PR 4
  adds the reservation lock in its place and keeps the order. The deadlock race test below guards against a
  regression.
- Race tests (ADR 0021 §4, two processes, PostgreSQL):
    - two notes on one invoice (the cap);
    - a note against a payment;
    - a note against a refund;
    - two refund reservations on two payments of one invoice;
    - a provider callback `SUCCESS` against the request's late `ERROR` for the same reservation (one note, one refund,
      `refundable_remaining` unchanged afterwards);
    - a provider refund callback against a staff note settled to account credit (deadlock);
    - two notes on different invoices (no gap in the numbers);
    - enabling the mode against an old-path adjustment (§8).

### 5. Refunds and notes: the reservation state machine

A refund that changes the supply is reserved first, sent to the provider, and becomes a note only when the provider
has paid.

**What this section needs from the #334 fix.** The bug is live on develop: `RefundTransaction.php:103-109` treats
every non-success as a failure. The fix PR for #334 is not open yet, and its engineer is implementing these four
points. If that PR changes a name used here, this section follows it.

1. A status lookup on the same reference maps "no such refund at the provider" to `DECLINED`. Otherwise a request
   that never reached the provider would stay pending for ever and hold the cap. Any answer it cannot interpret maps
   to `ERROR`.
2. A driver without a lookup gets a **staff resolution** on the pending row: "confirmed refunded" counts as `SUCCESS`
   and "confirmed not refunded" counts as `DECLINED`. It needs step-up (ADR 0018) and writes an activity entry with
   the staff member and the provider reference they checked.
3. Every booking of a provider refund stores the provider's refund id, and every actor dedupes on it, extending
   today's `existing(gateway, gatewayTxnId)`.
4. Only `DECLINED` releases; `ERROR` and `PENDING` stay pending (the table below).

**Tables.**

- `tax_note_reservations`: id, invoice_id, `transaction_id` (the pending refund row, whose id is the provider
  reference), reason_code, reason_text, digest, created_by, `status` (`pending` | `finalised` | `released`),
  `tax_note_id` (set at finalise), timestamps.
- `tax_note_reservation_lines`: reservation_id, invoice_item_id, quantity, unit_amount, amount, taxable,
  tax1_amount, tax2_amount, sort_order.

A pending reservation's lines count in both the line caps and the invoice cap (§3).

**Reserve.** From the moment the mode is **enabled**, which is before `enabled_from` too, every gateway refund of a
payment on a numbered invoice reserves. Lock payments → invoice → client (§4). Check the digest and both caps. Write
the pending refund row and the reservation with its full allocation. No number is taken.

**Actors.**

- **request:** the staff action's own provider call.
- **callback:** the provider's refund webhook for that reference.
- **Check refund:** a staff action on a pending row. It asks the provider about the **same reference** (the lookup in
  point 1), or offers the staff resolution in point 2.

Every actor applies a result in one transaction under the §4 lock order, with the reservation locked, and acts only
on the state it reads under that lock.

**Results.** `ChargeResult` statuses: `SUCCESS`, `DECLINED`, `ERROR` (including timeouts) and `PENDING`.

| State under lock | Result                               | Any actor does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pending`        | `SUCCESS`                            | **Finalise** with the transaction's own instant `$at` (§8). If `localDate($at) ≥ enabled_from`: issue exactly the reserved allocation as a note (sequence row last) and mark the refund row succeeded with `tax_note_id`. If it is before: write the old-style `refund` adjustment for the reserved amount, dated `$at` (before `enabled_from`, so outside every register period). Either way, reduce `amount_paid`, set status `finalised`, and re-read the balance: the refund amount is fixed, so an excess that would make the balance negative (a payment that arrived meanwhile) goes to account credit through `credit_note_to_credit`. Never refuses; the §8 rule that the issuer refuses notes before `enabled_from` does not apply here, because finalise chooses the row by its own instant. |
| `pending`        | `DECLINED`                           | **Release.** Mark the refund row failed, return `refundable_remaining` to the payment, set status `released`. No note and no number. Only this result releases.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `pending`        | `ERROR`, `PENDING`                   | **Stay pending.** Nothing changes. Staff see "Refund outcome not known yet" and only **Check refund**, never Retry. No new refund row or reference is created.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `finalised`      | any                                  | **No-op** (idempotent). A late `ERROR`, `PENDING` or `DECLINED` from the request, after the callback has finalised, changes nothing. A `DECLINED` after `SUCCESS` also opens a staff to-do, "Provider reports conflicting outcome".                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `released`       | `DECLINED`, `ERROR`, `PENDING`       | **No-op.**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `released`       | `SUCCESS` (the provider paid anyway) | The reservation stays released, because its cap may have been reused. The refund is booked once, as a **provider-initiated refund** (below), deduped on the provider's refund id.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |

Ordering cases, all covered by the table:

- **Callback before the request returns.** The callback's `SUCCESS` finalises, and the request's later answer is a
  no-op.
- **Crash after the provider has paid.** The reservation stays `pending` until the callback or Check refund brings
  `SUCCESS`. The callback finalises instead of answering 409 (today `GatewayCallbackHandler.php:264-266` answers 409).
- **Retry** exists only from `released`. It creates a new reservation with a new reference.

**Provider-initiated refunds.** These are `GatewayCallbackHandler::refunded` with no reservation of ours, and the
"released, then the provider paid anyway" case above. Each is booked in one transaction under the §4 order and stores
the provider's refund id; a second report of the same id is a no-op. The steps, in order:

1. **Credit first.** The callback's existing rule decides which part only returns overpayment credit.
2. **Invoice part.** It reserves and finalises at once, by the finalise row above: an automatic note when it falls on
   or after `enabled_from`, with actor "gateway" and reason `refunded_at_provider`.
3. **Cap used up.** If the note would exceed the cap (usually because staff credited the sale to the account after a
   release), take back the account credit that notes placed on that invoice, **up to the uncovered amount** (the part
   of the invoice part the cap could not take): `credit_ledger` −amount with `tax_note_id`, negative balance
   allowed, as other reversals.
4. **Anything left** goes to "Gateway payments to review".

Staff refunds use the same credit-first rule.

### 5a. Refund account credit through the gateway

Paying out account credit is not a supply, so it issues no note and changes no invoice. The gateway variant still
moves money through a provider, so it gets its own action, `RefundAccountCredit`. It does not reuse
`RefundTransaction`, which always applies a refund to the payment's invoice (`RefundTransaction.php:127-129`).

- **Permissions:** `billing.credit.refund` and `billing.refunds.manage`, with step-up (ADR 0018). The recorded
  variant (a refund made outside) needs only `billing.credit.refund`.
- **Reserve** (lock the chosen payment, then the client; no invoice):
    - check the client's balance and the payment's `refundable_remaining`;
    - write the payout row, a `credit_ledger` row of −amount with reason `credit_refunded`. The recorded variant
      uses the same reason; while the refund row is pending this row acts as the hold;
    - write a pending refund row with `invoice_id` null, the payment as its parent, and
      **`purpose = credit_refund`**, a new nullable `transactions.purpose` column (string, 30), written only by
      `RefundAccountCredit`. The `credit_refunded` ledger row carries this refund row's id in
      `credit_ledger.transaction_id`;
    - reduce `refundable_remaining`.

    Because the row is posted before the provider call, `ApplyCredit` cannot spend the credit meanwhile.

- **Results** follow the §5 table, with the refund row's status as the reservation state:
    - `SUCCESS` on a pending row: the row is marked succeeded; the `credit_refunded` row stands as the payout;
    - `DECLINED` on a pending row: the row is marked failed, a +amount `credit_refund_released` row reverses the
      payout, and `refundable_remaining` is restored;
    - `ERROR` or `PENDING`: the row stays pending, and only Check refund is offered;
    - `SUCCESS` on a **failed** row (the provider declined, then paid):
        - post `credit_refunded` −amount again (negative balance allowed);
        - take `refundable_remaining` back, clamped at 0 (another refund may have used it meanwhile). The to-do
          records the shortfall when it was clamped;
        - mark the row succeeded;
        - open a staff to-do, "Credit refund paid after a decline".

        This is booked once, deduped on the provider's refund id;

    - any other result on a settled row is a no-op.
- **Routing.** A refund row is a cash-out only when `transactions.purpose = credit_refund`. Every actor sends such a
  row here, never down the invoice path. An empty `invoice_id` is never the test: develop already writes payments
  without an invoice (the batch surplus, `PaymentBatches.php:186-197`), and `RefundTransaction` copies `invoice_id`
  from the payment onto its refunds (`:80`). Today a callback for an own reference whose row is failed falls through to the
  parents path (`GatewayCallbackHandler.php:268` onward); PR 4 adds this branch before that path.

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
        public int $amount,      // minor units, positive (the note's gross total)
        public string $currency,
        public bool $automatic,  // issued by automation, a gateway callback or a sibling module
    ) {}
}
// DebitNoteIssued: same payload.
```

- **Dispatch:** the events implement `ShouldDispatchAfterCommit` (ADR 0011, as `PaymentReinstated` already does) and
  are dispatched with `event()`. They are **never** passed to `AfterCommit::dispatch`, which would defer them a
  second time.
- Webhook types `credit_note.issued` and `debit_note.issued` are registered by Billing's provider with `WebhookEvents::register()`. They land together with the `tax_note` merge-field resolver (PR 5), so the first
  payload subscribers receive is the final snapshot, never the events' bare fields.
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

- `billing.nepal_vat.enabled` is an explicit opt-in, never derived from the seller's country or VAT number. The change
  is written to the activity log.
- **`enabled_from` is the first day of a BS month** on the company calendar: the current BS month or a later one,
  never an earlier one and never a mid-month day. A VAT period is therefore always wholly old-style or wholly in the
  mode.
- **The mode takes effect on `enabled_from`.** From 00:00 company time that day, notes replace the old paths; before
  it, the old paths run and staff cannot issue notes. No note is ever dated before `enabled_from`, so every note sits
  in a register period.
- **Refunds that cross the start.** Gateway refunds reserve from enabling (§5), and finalise picks a note or the
  old-style row by its own instant, so a refund pending across midnight into `enabled_from` becomes a note.
- **One instant per transaction.** Every Billing transaction that can write a value adjustment or a note captures
  `$at = CompanyClock::now()` once, after taking its locks. It decides "in effect" as
  `localDate($at) ≥ enabled_from` with the setting on, and writes `$at` as the `created_at` of every row it creates.
  It never lets Eloquent take a second `now()`. A transaction that decides "not in effect" therefore always writes
  rows dated before `enabled_from`, even across midnight.
- **The enable-time refusal.** Enabling locks the mode's settings row `FOR UPDATE`. Under that lock it refuses while
  any old-style value adjustment (`cancellation`, `item_cancellation`, `refund`, `late_fee`) on a numbered invoice was
  written after that invoice's `numbered_at` and is dated, by the company date of its `created_at`, on or after
  `enabled_from`. Pre-numbering rows are already in the snapshot. Such a row can exist only when `enabled_from` is
  the current month's first day; staff then pick the next BS month.
- Old paths read the mode with `FOR SHARE` on the same row, after their invoice lock. A transaction that read "mode
  off" therefore commits before the enabling check runs, and the register never holds an old-style row. This is the
  CTO decision on B6, also in `vat-register.md` §3 and §12. Enabling is also refused while the staff-review list from
  §2 has not been seen, while any numbered invoice still has no snapshot (`invoices_fiscal_pending_index` is not
  empty: `FiscalBackfill::hasPending()`), while any cached credited/debited total differs from its value rows
  (`FiscalBackfill::mismatches()`), and while any refund row written by the old path (without a reservation) on a numbered
  invoice is still pending.
- **From enabling (before `enabled_from` too):**
    - the increment of every fiscal sequence is 1;
    - `number_format`, `number_reset`, `number_padding`, `number_increment`, the note formats,
      `sequential_paid_numbering` and `set_issue_date_on_payment` are locked, and the settings page says why.
- Switching the mode off is refused once a note exists.

### 9. Database triggers

There are none in v1. The model guard and the tests enforce immutability. A PostgreSQL trigger cannot be exercised
on SQLite CI, and it would have to allow proforma numbering, so it needs its own ADR.

### 10. Build order

This table is the build order; `vat-register.md` §18 points here. Each PR is at most 2,000 lines, and the mode stays
hidden until PR 4 merges. The AC numbers are those of `vat-register.md` §13 as revised in the same round as this
section.

| PR       | Content                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | AC                           |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- |
| 0a (CTO) | `CompanyClock` contract, Core implementation, Billing fiscal call sites, boundary tests at 18:14:59 and 18:15:00 UTC, widened arch scan (ADR 0013 Amendment 1)                                                                                                                                                                                                                                                                                                                                                                                                                                                                | 48 (invoices)                |
| 0b (CTO) | `CreditNoteIssued` and `DebitNoteIssued`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | —                            |
| 1a       | Schema incl. `numbered_at`, snapshot, `tax_note_reservations` and `tax_note_reservation_lines`; the backfill with the review list (§2); `FROZEN`; per-sequence `DocumentNumbers`; `rateDecimal()`                                                                                                                                                                                                                                                                                                                                                                                                                             | 7–10                         |
| 1b       | Note issuer (allocation, caps with reservations, remainder, digest), lock order, race tests for notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 1–6, 11, 12, 48 (notes)      |
| 2        | Manual issue UI, settlement, step-up, permissions, notes panel, reversing note, recorded "Refund account credit"                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | 13–19, 20 (event)            |
| 3        | Old paths, the six `Invoicer::cancel` callers, automation, enabling the mode (§8: BS-month start, one instant per transaction, locked refusal, locked settings); the legacy `CancelInvoiceItems` path must count note lines on a line (or be refused once the line has a note), because it writes a full-line `item_cancellation` capped only by the balance; the late-fee debit note must also fire `LateFeeApplied` (the issuer fires `DebitNoteIssued` and `InvoiceModified` only); the architecture test that only the note issuer writes value adjustments on numbered invoices while the mode is on (vat-register §5.4) | 21–27, 52                    |
| 4        | Refund state machine (§5) incl. refunds crossing `enabled_from`, provider-initiated refunds, overpayment split, `RefundAccountCredit` (§5a), race tests (combined review, money)                                                                                                                                                                                                                                                                                                                                                                                                                                              | 28–34, 49–51, 53, 54, 57     |
| 5        | Note PDF, email, merge fields with the `tax_note` resolver, webhook delivery (the `MAP` entries), client area, `print_count`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | 35, 36, 55, 56, 20 (webhook) |
| 0c (CTO) | Report registry, DTOs, `SafeCsv` with `ErrorsCsv` moved, synchronous CSV and PDF (ADR 0025). Lands just before PR 6                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 45                           |
| 6        | `VatRegisterReport`, `TaxNoteRegisterReport`, screens                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 37–43                        |
| 7        | `detail` and `ird` layouts, PDF, export permissions                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 44, 46, 47                   |
| later    | Trigger (§9); `{BS_FY}` after D2; queued export with the Reports module (ADR 0025)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | 58                           |

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
  silently classify old rows. Rejected in favour of the PR 1a command with a review list.
- **Backfilling in a migration.** The previous release numbers invoices after it runs, and a long fill blocks the deploy.
  Rejected (review of #369).
- **Using `paid_at` as the numbering moment.** It is rewritten when a `Collections` invoice is paid again. Rejected;
  `numbered_at` is stored instead.
- **One `TaxNoteIssued` event with a kind enum.** One class would then map to two webhook types. Rejected.
- **A database trigger now.** It cannot be tested on SQLite and conflicts with proforma numbering. Deferred.
