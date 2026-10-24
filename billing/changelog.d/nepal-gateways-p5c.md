### Added

- **Refunds made in the Khalti or eSewa portal are found (#27).** A new daily automation task, "Check paid gateway
  payments for refunds", looks up again the payments of the last 7 days at gateways that send no refund notice (Khalti,
  eSewa). A refund there that no refund recorded on the payment accounts for flags the payment for staff: the new
  dashboard to-do "Refunds made at the provider to record" and the "Refunded at gateway" flag on Payment attempts (new
  Flags filter). Nothing is booked automatically: staff record the refund on the invoice, then mark the payment checked.
  A partial refund that later becomes full is flagged again. Each flag writes an activity entry
  (`billing.payment.portal_refund`). Upgrade notes: two new settings, `billing.gateways.refund_check_days` (7; 0 turns
  the check off, at most 30) and `billing.gateways.refund_checks_per_run` (300 a day, at most 1,000, least recently
  checked first); the first run looks up every Khalti and eSewa payment of the last 7 days once.

### Changed

- **A refund Khalti declines tells staff what to do for a bank-funded payment (#27).** The refund error now says to
  refund a payment made from a bank account in the Khalti merchant portal and record it, since Khalti's API needs the
  payer's full mobile number, which is not stored.
