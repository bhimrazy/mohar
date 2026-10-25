### `DescribesManualRefund` and `ValidatesSettings` (#491)

- New provider contract `App\Contracts\Billing\DescribesManualRefund` (`manualRefundNote(): string`, plain text),
  detected with `instanceof` and read only when the driver does not declare `REFUND`. Billing shows the note in the
  admin refund dialog and on the gateway form in place of its generic "refund in the merchant portal" text.
- New provider contract `App\Contracts\Integrations\ValidatesSettings` (`validateSettings(array $settings): array`,
  setting key => message), family-neutral like `ValidatesTldAttributes`: pure (no network, database or settings
  access), messages name the field and never repeat the value, secret values never passed. The admin gateway save
  calls it with the submitted non-secret values and shows each message at its field; other hosts adopt it as needed.
- `CancelsHostedPayment` and `ResumesHostedPayment` docblocks now say they are provider contracts (no API change).
- Additive; connectIPS implements both new contracts.
