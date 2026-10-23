### `Invoicer::cancel(int $invoiceId, ?string $reason = null)` (#vat-3b)

- `App\Contracts\Billing\Invoicer::cancel` has a new trailing parameter `?string $reason = null`. Implementers add it; callers
  need no change. With Nepal VAT mode on, cancelling a numbered tax invoice issues a credit note (`invoice_cancelled`) whose text
  is `$reason` ("Cancelled by Orders"); without a reason the text is "Invoice cancelled". The documented semantics change (a
  cancel can create a legal document) is the one ADR 0024 §7 announced. Callers: Orders `CancelOrder`, `DeleteOrder`,
  `MarkOrderFraud`; Services `CancelOpenRenewals`, `ExpireUnpaidChanges`; Domains `RenewalInvoices`. Each holds its own row
  locks before Billing's invoice lock. A cancel Billing cannot write is reported and leaves the invoice open.
