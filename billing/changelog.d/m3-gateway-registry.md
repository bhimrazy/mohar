### Changed

- **Payment gateways register with the kernel's gateway driver registry (ADR 0030 M3).** Nothing changes for staff or
  customers: the same gateways are listed in the same order, and webhook and return URLs stay the same. A gateway
  package can now register without Billing loaded, and every bundled gateway passes a shared conformance kit. The
  Stripe, Razorpay, Cybersource and Nepal gateways share one set of HTTP timeouts and retries and one amount converter,
  and no longer read Core directly.
