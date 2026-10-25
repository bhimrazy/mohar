### `BillingDocuments` types `invoice` and `quote` (P6b)

- `App\Contracts\Billing\BillingDocuments` has two new constants, `INVOICE` (`'invoice'`) and `QUOTE` (`'quote'`), beside `TAX_NOTE`.
  `document()` keeps its signature. Additive; an implementer returns null for a type it has no document for.
