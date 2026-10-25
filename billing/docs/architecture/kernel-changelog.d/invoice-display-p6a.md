### `InvoiceNumbers` and `OrderNumbers` contracts (invoice-display-p6a)

- New `App\Contracts\Billing\InvoiceNumbers::numbers(list<int> $invoiceIds): array<int, string>`, the printed number
  of each invoice (legal, else proforma, else a label for a draft, whose form may change). Implemented by Billing (`BillingDirectory`); Core binds
  `NullInvoiceNumbers` (returns `[]`) when Billing is absent. Used by the Orders order page and the Services service page.
- New `App\Contracts\Orders\OrderNumbers::numbers(list<int> $orderIds): array<int, string>`. Implemented by Orders
  (`OrderDirectory`); Core binds `NullOrderNumbers`. Used by the Services service page.
- Additive: new interfaces, no existing contract changes.
