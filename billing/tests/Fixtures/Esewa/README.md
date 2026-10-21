# eSewa fixtures

Response bodies captured from eSewa's ePay v2 UAT sandbox (`rc.esewa.com.np` status API and the
`rc-epay.esewa.com.np` form endpoint) on 2026-10-21, with the same keys, value types and number formats. Transaction uuids and `ref_id` values are
replaced with made-up ones, and the return URLs are left out of `booking_window`; tests put the attempt's own reference in
`transaction_uuid` with `esewaFixture('name', [...])`. There are no secrets in these bodies.

| File                         | eSewa request                                                                                          | Observed answer                            |
| ---------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------ |
| `status_not_found_cancelled` | status check after the customer confirmed "cancel payment"                                             | HTTP 200, `NOT_FOUND`, `ref_id: null`      |
| `status_pending`             | status check of a booked session nobody has paid yet                                                   | HTTP 200, `PENDING`, `ref_id: null`        |
| `status_complete`            | status check of the paid sample transaction from eSewa's docs                                          | HTTP 200, `COMPLETE`, `ref_id` set         |
| `form_duplicate_uuid`        | form post reusing the uuid of a booked session                                                         | HTTP 400                                   |
| `form_invalid_signature`     | form post with a wrong signature                                                                       | HTTP 400, `ES104`                          |
| `booking_window`             | eSewa's page load right after the form post (`form_posted_at_ms`) and that booking's `product_details` | `bookingExpiryTime` about 50 minutes later |
