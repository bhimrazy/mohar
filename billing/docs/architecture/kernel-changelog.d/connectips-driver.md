### `DescribesManualRefund` and `ValidatesGatewaySettings` (#491)

- New provider contract `App\Contracts\Billing\DescribesManualRefund` (`manualRefundNote(): string`, plain text),
  detected with `instanceof` and read only when the driver does not declare `REFUND`. Billing shows the note in the
  admin refund dialog and on the gateway form in place of its generic "refund in the merchant portal" text.
- New provider contract `App\Contracts\Billing\ValidatesGatewaySettings` (`settingsErrors(array $settings): array`,
  setting key => message), called on the admin gateway save with the submitted non-secret values; each message is
  shown at its field.
- `CancelsHostedPayment` and `ResumesHostedPayment` docblocks now say they are provider contracts (no API change).
- Additive; connectIPS implements both new contracts.
