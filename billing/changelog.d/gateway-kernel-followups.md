### Fixed

- **A gateway success that does not say what was paid is never booked (#500).** A status check that reports a payment
  without a currency or without an amount now sends the attempt to staff review ("Provider reported success without
  a currency" / "without an amount") instead of comparing only what was given. The drivers in this release all report
  both, so no booking path changes for them.
- **A Cybersource cancel ends the attempt without per-process memory (#500).** The signed CANCEL on the customer's
  return is passed to that return's status check, so the invoice can be paid again at once, as before. A CANCEL that
  reaches only the merchant notice URL no longer ends the attempt early; it ends at the usual grace period.
- **A Razorpay refund tagged with our reference but for another amount or currency is never matched to our refund
  (#500).** Check refund leaves it pending, with the Razorpay refund id kept only in the gateway log.
