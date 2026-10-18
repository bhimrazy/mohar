# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes. `optionsSource = 'remote'`
  lets a select take its options from the driver (cPanel spec K2). The constructor refuses a secret with a default and
  remote options on a non-select.
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`, which holds every integration redaction rule:
    - `redact()` for the module and registrar logs merges Services' redactor with the Domains registrar key lists. Keys
      are matched by word, adjacent pair or run-together spelling (`api_key`, `ApiKey`, `eppcode`, `accesshash` alike),
      and every key develop masked is still masked. Two narrowings are deliberate (architect R2): `author` is no longer
      caught by `auth`, and `code` is secret only where the payload says so (`RegistrarGateway` passes it for `epp` and
      `transfer`). Objects are read as arrays, nesting is capped at 32 levels, and inside strings it masks known
      credential values, cPanel `cpsess…` tokens, JSON documents and secret `name=value` pairs (query strings, form
      bodies, `Authorization:` text). Registrar error messages go through it too.
    - `redactPayment()` for the gateway log and stored `raw` keeps develop's gateway rule exactly (every leaf under a
      payment-sensitive key masked, null included; card brand and last 4 stay visible; Luhn-valid card numbers masked as
      `[redacted-pan]`), plus `authorization`, `dv` and `dataValidation` from payments-nepal.md §4.
    - `excerpt()` makes raw driver output safe (redacted, 4 KB). The module-log mask is now `[redacted]` (it was `***`).
- Added `App\Support\Integrations\OutboundAddressGuard` with `App\Data\Integrations\ResolvedAddress` and
  `OutboundAddressRefused` (ADR 0015, proposed). `resolve(host, port)` applies the install-wide policy in
  `config/integrations.php`, refuses non-public addresses (IPv6 allow-listed, embedded IPv4 judged) and non-canonical
  numeric hosts, and `pin()` sends the request to the checked IP and port (`CURLOPT_CONNECT_TO`), bypasses proxies
  (`proxy: ''`) and follows no redirects.
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
- `ProvidesServiceSso` has `ssoTargets(): array<target, label>` next to `serviceSsoLink(ctx, ?target)` (cPanel K7).
- Added `App\Events\Services\ServiceImported` (`serviceId`, `clientId`, `serverId`, `driver`, `source`; cPanel K8).
- `App\Data\Integrations\ConnectionCheck` gains trailing `steps` (`list<ConnectionCheckStep>`, with
  `App\Enums\CheckStatus`) and `details` (compatible).
- `App\Data\Services\ProvisionResult` gains a trailing `pending` flag (compatible): the create runs on the queue, so
  callers show "queued", never "failed".
- `ProvisioningDriver` docblock: any exception a driver throws during a call, other than
  `RetryableProvisioningException`, is an unknown outcome.
- `DriverRegistry::capabilities()` (PR A surface) is now public, so tests can check that every capability is
  covered.
- `ProvisioningContext::$configOptions` is empty in PR B; PR B2 fills it by module key from
  `PriceBook::moduleInputs()`. Install-specific option ids are never sent to drivers.
- **Breaking (module SPI removed):** `App\Modules\Services\Drivers\Provisioning\{ProvisioningDriver, ServiceContext,
DriverMetadata, DriverResult, DriverRegistry, UsageRow}` and
  `App\Modules\Services\Exceptions\RetryableProvisioningException`. `LogDriver` and `NoneDriver` are updated, and an
  arch rule keeps the old names out.

### Module inputs, server settings and one field shape (ADR 0009 amendment 1, PR B2)

- Added `PriceBook::moduleInputs(productId, optionSelections, customFieldValues): App\Data\Catalog\ModuleInputs`.
  This is a service contract, so adding a method is compatible; the fakes are updated. It returns configurable options
  as module key => module value (or label, quantity, `1`/`0`), custom fields by module key, and the secret values. It
  includes hidden options, admin-only fields and retired products. `ProvisioningContext::$configOptions` and
  `$customFields` are now keyed by module key only; the old fallback to a slug of the field name is gone.
- Added `PriceBook::productsForDriver(driverKey): list<ProductSummary>` (cPanel K10): every non-retired product of a
  driver, hidden ones included, for import matching and package warnings.
- `ServerConnection::$settings` and `$secretSettingKeys` are filled from the new encrypted `servers.settings` column,
  which holds the driver's `serverFields`.
- `ProvisioningDrivers::all()` gains a `fields` key in the `SettingField::toArray()` shape next to `config_fields`,
  which keeps its shape and is **deprecated** (removed before 1.0; read `fields`). Catalog reads `fields`. The gateway
  `fields` prop and the server form's `driverFields` use the same shape, rendered by one React component
  (`components/app/setting-field.tsx`).
