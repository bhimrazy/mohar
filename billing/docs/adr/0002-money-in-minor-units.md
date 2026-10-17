# ADR 0002 — Money as integer minor units with explicit currency

- Status: Accepted · 2026-10-17

## Decision
All monetary values are stored and computed as integers of 1/100 currency unit, always paired with an ISO 4217
currency code on the owning record. Arithmetic uses `App\Support\Money` (immutable, half-up rounding,
currency-mismatch exceptions, lossless `allocate`). Floats are banned in money paths; formatting is a UI concern.

## Consequences
Exact sums for invoices/taxes/proration; simple portable `bigInteger` columns. Zero-decimal and three-decimal
currencies are out of scope for v1 (documented limitation).
