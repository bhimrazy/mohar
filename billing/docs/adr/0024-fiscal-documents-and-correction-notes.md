# ADR 0024 — Fiscal documents and correction notes

- Status: Proposed · 2026-10-22 · Owner: Principal architect.
- Related: `billing/docs/product/vat-register.md` (PR #327, being revised) and its architecture review; ADR 0002
  (money), ADR 0010 (contract evolution), ADR 0011 (events), ADR 0013 (company clock, amended for documents),
  ADR 0021 (confirm-then-apply, lock order), ADR 0025 (report registry).

## Context

- An invoice with a legal `number` is a tax document. Today Billing changes its value through `invoice_adjustments`
  rows that have no number: `cancellation`, `item_cancellation`, `refund` and `late_fee`. Those rows are correct for
  the balance but invisible to a VAT register.
- `Invoice::FROZEN` (`Invoice.php:84`) freezes amounts and tax. It does not freeze `issue_date`, `client_snapshot`
  or `seller_snapshot`.
- In proforma mode (`sequential_paid_numbering`) an invoice is numbered when it is paid
  (`InvoiceSettlement::settle`). Any late fee or line cancellation made before payment is part of the amount paid,
  but not of `total`.
- Refunds run in three phases (`RefundTransaction`): reserve, call the provider with no locks held, finalise. The
  first draft of the spec numbered the note before the provider call. That leaves a negative balance between the
  phases and forces an edit to the settlement after a failure.
- Two documents need to correct invoices: the Nepal VAT spec now, India GST credit notes later. Without one rule,
  each would invent its own.

## Decision

### 1. Fiscal documents

- A **fiscal document** is an invoice with a legal `number`, or a credit or debit note (`tax_notes`). Drafts,
  proforma-only invoices and add-funds invoices are not fiscal documents.
- A fiscal document is **numbered in the transaction that creates it**. The number comes from a gap-free
  `DocumentNumbers` sequence, and there are no draft notes. Each sequence has its own format, padding, reset and
  increment. Today only quotes have their own settings (`DocumentNumbers.php:33-39`).
- After numbering, a fiscal document is **immutable**. The model refuses any change except the columns on an explicit
  allow-list: on notes, `print_count` and the reserved `cbms_*` status. The invoice freeze grows to cover
  `issue_date`, `client_snapshot`, `seller_snapshot`, the value snapshot below, and the BS fields. A proforma is
  allowed exactly one transition, the one that assigns its number.
- **Correction only by note.** Once an invoice is numbered, its value changes only through a credit or debit note
  against that one invoice. A mistaken note is reversed by an opposite note, never by an edit or a delete. Adjustments
  that move money without changing the supply stay as they are: `credit_applied`, `credit_removed`, `overpayment`
  and `write_off`. The spec tabulates every action and reason.
- Notes are stored in `tax_notes` and `tax_note_lines`. Each note writes exactly one value adjustment
  (`invoice_adjustments.tax_note_id`). Any money a settlement moves is written as separate rows (B2 in the review):
    - **account credit:** a debit adjustment, reason `credit_note_to_credit`, plus a credit-ledger post;
    - **refund:** the refund transaction.

    All of these rows carry `tax_note_id`. The balance is never negative at commit.

- The foreign keys from `tax_notes` to the invoice and the client are `restrictOnDelete`. A fiscal document is never
  pruned.

### 2. Snapshot at numbering

When an invoice is numbered, it stores the values the register and the note caps read. These values are never
recomputed from the current tax rules, rates or calendar:

- **tax-invoice value:** taxable, exempt, tax1 and tax2 (name, rate, amount), and total. These include any value
  adjustments made before numbering (proforma late fees and cancelled lines);
- **reporting rate:** `report_currency` and `report_rate`, read from the stored rate (`CurrencyConverter::rate`, scale
  24,12), never from an `ExchangeRateSource` call;
- **classification:** taxable, exempt or export, as the tax rule applied (D6);
- **BS fields:** `bs_issue_date`, `bs_period` and `bs_fiscal_year`, from the company date (ADR 0013, Amendment 1).

Notes store the same fields. A later correction to the BS table therefore moves no filed document between periods.

### 3. Caps, allocation and rounding

- Every note is allocated to invoice lines in `tax_note_lines`. A note "by amount" is spread pro rata across the open
  lines. The cap for a line is its gross amount less the credits already allocated to it.
- The cap for an invoice is the tax-invoice value + Σ debit value adjustments − Σ credit value adjustments. The sums
  count notes and value adjustments written before this ADR alike. `credited_total` and `debited_total` are caches
  of those sums. `InvoiceSettlement::adjust` is their only writer. A value adjustment written before this ADR
  carries no tax split (`CancelInvoice.php:55`, `RefundTransaction.php:187`). For caps and for the remainder rule
  only, its split is prorated from the invoice's value snapshot. The register never reads these rows (§8).
- Rounding is half-up per line and per tax level. The note that takes a line's remainder to zero takes the exact
  remaining tax at each level, so a fully credited invoice nets to zero tax in minor units.

### 4. Concurrency

- Issue follows ADR 0021 §1. The confirm carries the invoice `version` and a digest of the previewed allocation. The
  issuer recomputes both under the lock and refuses on a mismatch.
- Lock order: payments (in id order), then invoice, then client, then the sequence row. A `billing_sequences` row is
  shared by every document of its sequence install-wide, so it is taken last, just before the insert. This order is added to ADR 0021 §3.
- The build ships the two-process PostgreSQL race tests that ADR 0021 §4 requires:
    - two notes on one invoice (the cap);
    - a note against a payment;
    - a note against a refund;
    - two notes on different invoices (no gap in the numbers).

### 5. Order of a refund and its note

1. **Reserve** the refund under the payment lock (as today). No note yet.
2. **Call the provider** with no locks held. If it refuses, nothing is issued. Staff retry, or choose a settlement to
   account credit, or record the refund as made outside Mohar.
3. **Finalise in one transaction:** number the note, write its value adjustment, finalise the refund, and write the
   `settlement` once.

Refunds started at the provider (`GatewayCallbackHandler::refunded`) run step 3 with an automatic note: actor
"gateway", reason `refunded_at_provider`. The callback's rule "credit first, then the invoice" decides which part of
a refund changes the supply and which part only returns overpayment credit. Admin refunds use the same rule.

### 6. Events (ADR 0011)

There is one event per kind, because a webhook type maps to exactly one class:

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

- The webhook types are `credit_note.issued` and `debit_note.issued`, added to `WebhookEventTypes::MAP` until the
  registration method from ADR 0011 exists.
- Existing events keep their meaning:
    - `InvoiceCancelled` fires after the note that cancels the invoice;
    - `InvoiceModified` fires on a partial note;
    - `InvoiceRefunded` fires when a note's settlement refunds money (commission reversal stays there);
    - `LateFeeApplied` fires for a late-fee debit note.

### 7. `Invoicer::cancel` behaviour change (ADR 0010)

- When Nepal VAT mode is on, `Invoicer::cancel(int $invoiceId)` on a numbered invoice issues a credit note
  (`invoice_cancelled`, automatic, reason text "Cancelled by <module>"). Orders' stale-order and admin cancel paths
  therefore create a legal document.
- The signature is unchanged. The documented semantics change, which ADR 0010 counts as breaking. The pre-1.0 window
  allows it here:
    - the docblock says the call may issue a credit note;
    - the change is listed in `docs/architecture/kernel-changelog.md`;
    - the architect gate applies.
- A trailing `?string $reason = null` is a compatible addition, made when a caller needs to pass a reason.

### 8. Mode switch

- `billing.nepal_vat.enabled` is an explicit opt-in. It is not derived from the seller's country and VAT number. It
  records `enabled_from`, a company date, and the change is written to the activity log.
- **Enabling is refused** while any value adjustment of the old kind (`cancellation`, `item_cancellation`, `refund`,
  `late_fee`) on a numbered invoice is dated on or after `enabled_from`, using the company date of its `created_at`.
  Staff pick a later `enabled_from`, or settle those rows first. The register therefore never holds an old-style
  adjustment, and it has no "before credit notes" rows and does no proration when it reads (CTO decision on B6,
  `vat-register.md` §3 and §12 as revised in #327).
- Invoices numbered before `enabled_from` take notes like any other. When the mode is enabled, their value snapshot
  (§2) is backfilled from `total` plus their existing value adjustments. Those adjustments count toward caps and the
  remainder rule through the proration in §3.
- Switching the mode off is refused once a note exists.

### 9. Database triggers

There are none in v1. The model guard and tests enforce immutability. A PostgreSQL trigger cannot be exercised on
SQLite CI, and it would have to allow proforma numbering. It needs its own ADR.

## Open questions (accountant, before the build that uses them)

- **D2:** Do note and invoice sequences reset each BS fiscal year (`{BS_FY}`, reset `bs_fiscal_year`)? Until this is
  answered, notes use `reset never`. The sequence period key is ready for `2083.084`.
- **D6:** Which rate converts to NPR (the stored rate on the numbering date is the default here), and when is a
  foreign client's invoice an export? Classification is stored, so a later answer does not rewrite filed documents.
- Bad-debt relief (spec A6): write-offs stay out of notes until the accountant says otherwise.

## Consequences

- One correction model for Nepal now and India GST later. The register sums snapshots and never re-derives them.
- Cancel, cancel lines, late fee, reopen, refund (from staff and from the provider) and `Invoicer::cancel` all change
  behaviour in Nepal VAT mode. The mode stays hidden until every one of them issues notes.
- `DocumentNumbers` gets per-sequence settings. The `invoice_adjustments.number` column is no longer written for
  notes, so two note formats can never collide on its unique index.
- Billing code that writes dates depends on ADR 0013 Amendment 1, which lands first.

## Alternatives considered

- **Corrections as plain adjustments with a number** (no `tax_notes`): smaller schema, but no lines, buyer snapshot
  or BS fields per document. Rejected.
- **Numbering the note before the refund call, with a mutable settlement:** keeps the spec's "never roll back" wording,
  but opens a window with a negative balance and breaks immutability. Rejected.
- **One `TaxNoteIssued` event with a kind enum:** fewer classes, but one class would map to two webhook types and
  two templates. Rejected.
- **Computing register values live from invoices:** no snapshot columns, but a rule, rate or calendar correction
  would rewrite filed periods. Rejected.
- **A database trigger now:** stronger at the database level, but untestable on SQLite and in conflict with proforma
  numbering. Deferred.
