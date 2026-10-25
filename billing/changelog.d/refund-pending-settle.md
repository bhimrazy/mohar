### Fixed

- **A gateway refund whose first answer was unknown no longer stays pending forever.** When the provider's answer to a
  refund carried its refund id without confirming the refund, the later refund webhook was answered as a duplicate and
  the refund waited for Check refund or staff. The webhook now settles that pending refund, with the same payment and
  amount checks as any pending refund. A webhook for a refund that is already settled is still a duplicate.
- **Stripe refunds that are `pending` or `requires_action` are no longer booked as refunded.** Only a `succeeded`
  refund is booked; `failed` and `canceled` release the amount. Any other state keeps the refund pending with its amount
  held until `refund.updated` or Check refund decides. Upgrade note: a Stripe refund made after this release can now
  show as "being confirmed with the provider" until Stripe finishes it, where it used to show as refunded at once.
