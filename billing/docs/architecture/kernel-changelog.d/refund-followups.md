### `CallbackEvent::REFUND_FAILED` (#498)

- `App\Data\Billing\CallbackEvent` has a new type constant `REFUND_FAILED`: the provider reports a refund as failed or
  cancelled. Fields as for `REFUND` (`$txnId` the provider refund id, `$reference` our refund row when the refund was
  sent from the admin). Billing books and reverses nothing from it; a refund row it already booked as made goes to
  staff review once. Additive: drivers that do not send it need no change.
