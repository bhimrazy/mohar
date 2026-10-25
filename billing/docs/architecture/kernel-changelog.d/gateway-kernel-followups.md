### `$signedCancel` on `ReturnHint` and `PaymentAttemptRef`; `SUCCEEDED` requires amount and currency (#500)

- `App\Data\Billing\ReturnHint` has a new last constructor parameter `bool $signedCancel = false`: the driver verified
  the provider's own signature over a cancel on this return. Like `$signedPaid`, it counts only with
  `$signatureValid`. Additive: existing drivers need no change.
- `App\Data\Billing\PaymentAttemptRef` has a new last constructor parameter `bool $signedCancel = false`. Billing keeps
  a signed cancel on the attempt the return names (by reference, or by a non-empty provider reference when the hint
  has none), under the attempt's lock, also when the return route makes no lookup; every later lookup of that attempt
  gets `signedCancel: true`, and a driver may then answer `not_found` with `sessionEnded`. Additive.
- Changed semantics: `SettlePaymentAttempt` refuses a `LookupResult::SUCCEEDED` whose `currency` is null or empty, or
  whose `amount` is null, and holds the attempt for staff review. Documented on `LookupResult` and
  `HostedPaymentDriver::lookup()`: a driver must report both on every success. Before, a null currency skipped the
  currency check.
