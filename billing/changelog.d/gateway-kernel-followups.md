### Fixed

- **A gateway success that does not say what was paid is never booked (#500).** A status check that reports a payment
  without a currency or without an amount now sends the attempt to staff review ("Provider reported success without
  a currency" / "without an amount") instead of comparing only what was given. The drivers in this release all report
  both, so no booking path changes for them.
- **A Cybersource cancel ends the attempt without per-process memory (#500).** The signed CANCEL on the customer's
  return is kept on the attempt and passed to its status checks, so the invoice can be paid again at once, as before.
  A cancel that comes back within seconds of a check (when the return makes no new check) is no longer lost: the next
  check ends the attempt. A CANCEL that reaches only the merchant notice URL no longer ends the attempt early; it ends
  at the usual grace period.
- **A Razorpay or Cybersource refund tagged with our reference but for another amount or currency is never matched to
  our refund (#500).** Check refund leaves it pending, with the provider's refund id kept only in the gateway log.
