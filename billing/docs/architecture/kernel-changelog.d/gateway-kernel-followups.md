### `ReturnHint::$signedCancel` and `PaymentAttemptRef::$signedCancel` (#500)

- `App\Data\Billing\ReturnHint` has a new last constructor parameter `bool $signedCancel = false`: the driver verified
  the provider's own signature over a cancel on this return. Additive: existing drivers need no change.
- `App\Data\Billing\PaymentAttemptRef` has a new last constructor parameter `bool $signedCancel = false`. Billing sets
  it on the lookup that follows a return whose hint has `signedCancel` and names this attempt (by reference, or by
  provider reference when the hint has none); a driver may then answer `not_found` with `sessionEnded`. Additive.
- `SettlePaymentAttempt` now refuses a `LookupResult::SUCCEEDED` whose `currency` is null or empty, or whose `amount`
  is null: the attempt goes to staff review. A driver must report both on every success.
