### `DescribesManualRefund` capability (#491)

- New `App\Contracts\Billing\DescribesManualRefund` (`manualRefundNote(): string`), detected with `instanceof` on a
  gateway driver without a refund API. Billing shows the note in the admin refund dialog and on the gateway form in
  place of its generic "refund in the merchant portal" text. Additive; connectIPS implements it.
