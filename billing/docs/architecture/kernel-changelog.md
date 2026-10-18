# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes. `optionsSource`
  (for example `remote:packages`) lets a select take its options from the remote system (cPanel spec K2).
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`, the one redaction rule for the gateway log, the module log and the
  registrar command log (Services' redactor, the Domains registrar key lists and Billing's `GatewayLogger` rules,
  merged). Keys are matched by word (`api_key`, `ApiKey`, `X-Api-Key` alike; `author` and `country_code` stay
  visible); objects are read as arrays before matching; Luhn-valid card numbers and cPanel `cpsess…` tokens are
  masked in any string; a payload can name extra secret keys (`sensitiveKeys: ['url']` for SSO answers, `['code']`
  for EPP code answers); `excerpt()` makes raw driver output safe (redacted, 4 KB). The mask is `[redacted]`
  everywhere (Services logs used `***`), and `[redacted-pan]` for card numbers.
- Added `App\Support\Integrations\OutboundAddressGuard` with `App\Data\Integrations\ResolvedAddress` and
  `OutboundAddressRefused` (ADR 0015, proposed). `resolve(host, port)` applies the install-wide policy in
  `config/integrations.php`, refuses non-public addresses (IPv6 allow-listed, embedded IPv4 judged), and `pin()` binds
  the request to the checked IP, bypasses proxies and follows no redirects.
- **Breaking:** moved `App\Data\Billing\ConnectionCheck` to `App\Data\Integrations\ConnectionCheck`.
- **Breaking:** moved `App\Contracts\Billing\ProvidesLogo` to `App\Contracts\Integrations\ProvidesLogo`, and renamed
  `App\Data\Billing\GatewayLogo` to `App\Data\Integrations\IntegrationLogo`. Khalti and eSewa are updated.

### Provisioning contracts (ADR 0009 §2–§5, PR B)

- Added `App\Contracts\Services\Provisioning\ProvisioningDriver` (extends `IntegrationDriver`) and the capability
  interfaces `ChangesPackage`, `ChangesPassword`, `ReportsUsage`, `TestsServerConnection`, `ProvidesServiceSso`
  (with an optional target), `ProvidesServerSso`, `ListsAccounts`, `RenewsAccounts`, `ProvidesFieldOptions` and
  `ReconcilesCommands`.
- Added the snapshot DTOs in `App\Data\Services\Provisioning`: `ProvisioningContext`, `ServiceSnapshot`,
  `ServerConnection` (with `apiToken`, `settings` and `secretSettingKeys`), `ClientSummary`, `DriverMetadata`,
  `DriverResult` (with `outcomeUnknown`, `unknown()`, `outcome()`), `PreviousPackage`, `UsageRow`, `SsoLink`,
  `RemoteAccount`, `CommandLookup` and `UsernamePolicy`. Also `App\Data\Services\RemoteOptions`.
- Added the enums `App\Enums\DriverOutcome` and `App\Enums\ReconcileStatus`.
- Added `App\Support\Services\ProvisioningDriverRegistry` (final, bound in `AppServiceProvider`) and
  `App\Support\Services\RetryableProvisioningException`.
- `App\Contracts\Services\ProvisioningDrivers` gains `remoteOptions()`. Its fields may carry `options_source`.
- `App\Contracts\Billing\GatewayDriver` now extends `IntegrationDriver` (same methods; compatible).
- `DriverRegistry::capabilities()` is public, so tests can check that every capability is covered.
- **Breaking (module SPI removed):** `App\Modules\Services\Drivers\Provisioning\{ProvisioningDriver, ServiceContext,
DriverMetadata, DriverResult, DriverRegistry, UsageRow}` and
  `App\Modules\Services\Exceptions\RetryableProvisioningException`. `LogDriver` and `NoneDriver` are updated, and an
  arch rule keeps the old names out.
