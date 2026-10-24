### `Invoicer::cancel(int $invoiceId, ?string $reason = null)` and `InvoiceNotCancellable` (#451)

- `App\Contracts\Billing\Invoicer::cancel` has a new trailing parameter `?string $reason = null`. Implementers add it; callers
  need no change to compile. With Nepal VAT mode on, cancelling a numbered tax invoice issues a credit note (`invoice_cancelled`)
  whose text is `$reason` ("Cancelled by Orders"); without a reason the text is "Invoice cancelled". The documented semantics
  change (a cancel can create a legal document) is the one ADR 0024 §7 announced.
- New `App\Support\Billing\InvoiceNotCancellable` (a `DomainException`): `cancel` throws it when the invoice stays payable, for
  example because its credit note was refused. Billing has put the invoice on its staff list ("Needs attention"). A caller must
  not mark its own record cancelled or rewind its pointers. Orders `CancelOrder` and `DeleteOrder` let it propagate (their
  transaction rolls back and staff see the message), `MarkOrderFraud` logs it and continues; Services `CancelOpenRenewals` and
  `ExpireUnpaidChanges` and Domains `RenewalInvoices` keep the record open. Callers: Orders `CancelOrder`, `DeleteOrder`,
  `MarkOrderFraud`; Services `CancelOpenRenewals`, `ExpireUnpaidChanges`; Domains `RenewalInvoices`. Each holds its own row
  locks before Billing's invoice lock.
