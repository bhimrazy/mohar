### Added

- **Razorpay gateway for India.** Clients pay INR invoices through a Razorpay payment link, a plain redirect with no
  Razorpay script on our pages. An invoice is marked paid only after the app fetches the link and its payment from
  Razorpay and the amount, currency and reference match; the signed browser return and webhooks only trigger that
  lookup. Razorpay's fee (including its GST) is recorded on the payment. Staff refund in full or in part from the
  invoice. A refund Razorpay reports as pending stays pending, with its amount held, until Razorpay confirms it or "Check refund"
  finds it processed or failed. "Continue payment" reopens an open link and
  "Use another method" cancels it. Setup (Admin → Billing → Gateways → Razorpay): test mode, key id, key secret and a
  separate webhook secret; register the webhook URL shown on the form for `payment_link.paid`,
  `payment_link.partially_paid`, `payment_link.expired`, `payment_link.cancelled`, `refund.processed` and
  `refund.failed`, and keep the account on automatic capture. Test mode needs `rzp_test_…` keys and is refused in
  production. Built and tested against our Razorpay simulator only; run `php artisan payments:razorpay-smoke --pay`
  with a real test account before going live.
