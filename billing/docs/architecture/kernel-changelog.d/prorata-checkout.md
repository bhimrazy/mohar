### `App\Support\Billing\Prorata` removed; first-period dates on the service drafts (#607)

- **Breaking.** `App\Support\Billing\Prorata` is removed. The first-period calculation now lives in Orders
  (`App\Modules\Orders\Pricing\Prorata`), its only caller, and is not kernel API. Nothing in `app/` called the kernel
  class; an extension that did must compute its own first period. It was never listed in `kernel-api.txt`.
- `App\Data\Services\ServiceDraft` and `AddonDraft` have a new last constructor parameter `?int $billingAnchorDay =
  null`: the day of the month later periods renew on (null = the registration day). Additive.
- `ServiceDraft::$nextDueDate` and `AddonDraft::$nextDueDate` are documented as the end (exclusive) of the first
  period the order invoice covers, which is how `ServiceRegistry` implementations already read them. Orders passed the
  order date until now; it passes the first period's end.
- `PriceBook::addonsFor()` rows may carry `prorata_with_parent: bool` (absent = false). Additive.
