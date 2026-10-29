### Kernel API version, signature snapshot and `config_fields` removal (#513)

- New `App\Support\Kernel` (`@api`) with `API_VERSION = '0.1.0'`. Only the release PR bumps it, by the rule in ADR 0010
  Amendment (#513).
- New `docs/architecture/kernel-api.txt`, written by `php artisan kernel:api-snapshot`. It holds the public
  signatures of `app/Contracts`, `app/Data`, `app/Events`, `app/Enums` and the `@api` classes in `app/Support`.
  CI fails when the file is stale, and when it changes without a fragment here.
- These classes now carry `@api` and are in the snapshot: `Money`, `SettingsRegistry`, `SettingDefinition`,
  `PermissionRegistry`, `ThemeRegistry`, `SitemapRegistry`, `ClientMergeRegistry`, `ReferenceDataRegistry`,
  `Dashboard`, `Navigation`, `NavItem`, `GlobalSearch`, `ExchangeRateSources`, `PriceConverters`,
  `AbstractGatewayDriver`, `IdentifiesOccurrence`, `RetryableProvisioningException`, `RegistrarException`,
  `RegistrarTimeout`, `CapabilityNotSupported`, `OutboundAddressRefused` and `ExchangeRateUnavailable`. Their code is
  unchanged.
- **Breaking.** `App\Contracts\Services\ProvisioningDrivers::all()` no longer returns the `config_fields` key, which
  was deprecated in #65 (issue #68). Read `fields`, the `SettingField::toArray()` shape. Allowed under the pre-1.0
  window: nothing in the repository read it, and `RegistryProvisioningDrivers`, `FakeProvisioningDrivers` and the
  tests are updated.
- Recorded late, unchanged (they shipped in #28 and #72 without an entry; issue #83):
    - `App\Contracts\Auth\AuthMailer`: `sendPasswordReset(User, string $token)`, `sendEmailVerification(User)` and
      `sendPasswordSet(User, string $token)`. Core binds Laravel's notifications; Notifications rebinds it.
    - `App\Contracts\Notifications\SystemMailer::send(string $templateKey, User|string $recipient, array $context,
      string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome`.
    - `App\Data\Notifications\SensitiveValue(string $value, ?string $secret = null)`: a merge-field value that grants
      access by itself (a one-time link). Its string form is a mask, staff-editable template text cannot use it, and
      it never goes into a webhook payload.
    - `App\Contracts\Events\DomainEvent::occurrenceId(): string`, implemented by every event in `app/Events` through
      `IdentifiesOccurrence`.
    - Notifications events: `EmailQueued`, `EmailSent` (`$relatedType` and `$relatedId` trailing), `EmailFailed`,
      `EmailSuppressed` (`$reason`), `WebhookDelivered`, `WebhookDeliveryFailed` (`$attempt` and `$abandoned`) and
      `WebhookEndpointDisabled` (`$consecutiveFailures`). Their full signatures are in `kernel-api.txt`.
