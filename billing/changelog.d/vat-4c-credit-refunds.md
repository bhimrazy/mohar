### Added

- **Refund account credit.** Account credit page, "Refund credit" (permission `billing.credit.refund`, password re-entry): pay the balance out as a payout made outside (method and reference required) or through the payment gateway against an earlier payment of the client (also needs `billing.refunds.manage`). The credit leaves the balance before the provider is called, so it cannot be spent meanwhile; a declined refund puts it back, an unknown answer waits for the provider's callback or Check refund, and a refund the provider pays after a decline is booked once and shown on Disputes to review. It is not a sale: no credit note, no invoice changes.

### Changed

- **Nepal VAT page always available.** The Nepal VAT settings page shows in the menu for staff with `billing.tax.manage`; the interim feature flag is gone.
- **Refund to account credit on a numbered tax invoice** issues the credit note (Nepal VAT mode) and credits the account, instead of being refused.
- **Register stretches and switch-off.** A mode switched off before any stretch was stored keeps its first stretch when re-enabled; documents numbered later on the switch-off day are outside the stretch; a DECLINED answer after a confirmed refund no longer reopens a reviewed entry. The Needs attention list for invoices staff cannot cancel is bounded, and entries for invoices that were paid or cancelled meanwhile are marked seen.
- Upgrade notes: one migration adds a nullable `purpose` (string 30) to `transactions`, expand only. Grant `billing.credit.refund` (not in any system role by default) to the roles that should pay credit out (it is a sensitive permission, never granted by wildcards).
- **Demo data.** The demo seed switches Nepal VAT mode on from before the seeded history (730 days) and runs the VAT split, so the VAT register shows documents. Local demo only; never runs in production.
- **Needs attention.** A cancel refused inside an order cancel or delete that rolls back keeps its entry for staff; a late fee refused day after day is logged once.
- **Refund credit.** The payment list names the gateway, date and invoice instead of the provider id.
