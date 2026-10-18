# ADR 0002 — Money as integer minor units with explicit currency

- Status: Accepted · 2026-10-17

## Decision

All monetary values are stored and computed as integers of 1/100 currency unit, always paired with an ISO 4217
currency code on the owning record. Arithmetic uses `App\Support\Money` (immutable, half-up rounding,
currency-mismatch exceptions, lossless `allocate`). Floats are banned in money paths; formatting is a UI concern.

## Consequences

Exact sums for invoices/taxes/proration; simple portable `bigInteger` columns. (Display formatting: see the 2026-10-18 addendum.) Zero-decimal and three-decimal
currencies are out of scope for v1 (documented limitation).

## Primary markets (2026-10-17)

The primary pricing currencies are **USD (default), INR and NPR** — all two-decimal currencies (cents, paise,
paisa), so minor units are exact. Display uses a per-currency locale: USD `en-US`, INR `en-IN` (lakh/crore
grouping, ₹), NPR `ne-NP`/`en-IN` grouping (रू). Taxes: India GST (CGST+SGST intra-state, IGST inter-state —
two tax levels by state) and Nepal VAT 13% are first-class tax-rule presets in Billing.

## Addendum (2026-10-18, Currencies, PR #43)

- Formatting money for people is a kernel concern, not a UI-only one. `App\Support\Currencies\MoneyFormatter`
  renders every amount people read (pages via the shared `currencyFormats` prop and `formatMoney`, dashboards,
  merge fields, mail, PDFs) from each currency's configured display: symbol prefix or suffix and number format,
  including Indian lakh grouping. `Money::format()` ("24.99 USD") is for logs, audit entries and CLI only.
- The per-locale display above ("NPR `ne-NP`/`en-IN` grouping (रू)") is superseded: NPR shows `Rs 1,23,456.00`.
- Exchange rates are stored as `decimal(24,12)` and computed with exact decimal arithmetic (`Decimal`), never floats.
- Details: `docs/product/platform-admin.md` §6.
