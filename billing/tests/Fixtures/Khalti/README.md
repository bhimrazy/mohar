# Khalti fixtures

Response bodies captured from Khalti's KPG-2 sandbox (`dev.khalti.com`) on 2026-10-31. No key was used: Khalti
publishes no shared sandbox key (a merchant signup at test-admin.khalti.com is needed), so only the refusals of a
request without a valid key could be captured. There are no secrets in these bodies.

| File                 | Khalti request                                                                                        | Observed answer |
| -------------------- | ----------------------------------------------------------------------------------------------------- | --------------- |
| `auth_not_provided`  | `epayment/lookup/` with no `Authorization` header, or one without the `Key ` scheme                   | HTTP 401        |
| `auth_invalid_token` | `epayment/lookup/`, `epayment/initiate/` and `merchant-transaction/{id}/refund/` with `Key <made-up>` | HTTP 401        |
