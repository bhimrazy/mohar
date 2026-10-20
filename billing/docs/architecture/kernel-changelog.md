# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Client import, PR B1a (client-import spec §15, ADR 0019)

- Added the event `App\Events\Migration\MigrationRunCompleted(int $runId, string $sourceSystem, bool $failed, array
$counts)`, with `$counts` keyed by entity (`created`, `updated`, `unchanged`, `skipped`, `failed`), dispatched once
  when an import run finishes applying.
- Core registers the `client` and `user` importers in the kernel `ImportRegistry`; both honour dry runs.

### Client import, PR A0 (client-import spec §16 C1, C2; ADR 0019 proposed)

- Added the provider contract `App\Contracts\Migration\RecordImporter` (with `key()`; a dry run's `upsert()` writes
  nothing; `rules()` must not query the database), `ImportIdResolver::targetId(string $entity, string $sourceId)`,
  the DTOs `App\Data\Migration\{ImportRecord, ImportContext, ImportOutcome, ImportIssue}` and the enums
  `App\Enums\{ImportAction (@open), ImportSeverity}`, as `migration.md` §12 with two trailing defaulted fields,
  `ImportOutcome::$changes` and `ImportContext::$actorId`. One vocabulary: `$entity` is the registry key and
  `$sourceSystem` the kind of source (`csv`, `whmcs_api`).
- Added the final registry `App\Support\Migration\ImportRegistry` (ADR 0012), bound in `AppServiceProvider`:
  `register(key, class-string|Closure, replace: false, dependsOn: [])` with keys matching `[a-z0-9_]{1,50}`,
  duplicates refused unless replaced (logged), `has()`, `get()` (checks the importer's `key()`), `keys()`, and
  `ordered(array $keys = [])`, which checks only the requested chains.
- Added `App\Support\Queues::IMPORTS` (`imports`), `IMPORT_JOB_TIMEOUT_SECONDS` (120), `DEFAULT_POOL_TIMEOUT_SECONDS`
  (150) and `DEFAULT_POOL_MEMORY_MB` (256). The default pool works `default,imports`; no new connection (ADR 0014
  amendment).
- Arch rule: every `App\Modules\*\Import` namespace that exists uses no `event()` or `dispatch()` helper, event
  dispatcher, `Event`, `Bus`, `Mail` or `Notification` facade, `SystemMailer`, `AuthMailer`, `App\Events` namespace
  other than `Migration`, or driver contract or registry; a probe fixture proves the rule fails.
- The run events (C4) are not in this PR; each lands with the code that dispatches it (ADR 0019).

### Integration simulators, S1 (ADR 0016 §1, §3)

- `Endpoints`: a key that does not match the key pattern is never repeated in logs, `app:install` or the dashboard;
  such keys are counted ("(2 malformed keys)"), since one may be a pasted URL with credentials.
- `Endpoints::configuredKeys()`: the keys set in `INTEGRATION_ENDPOINTS` whatever the environment (for the install
  check and the dashboard health check; drivers may not call it, Arch rule). Compatible.
- No other kernel change: the framework is the dev-only package `mohar/simulators` (`tools/simulators`, namespace
  `Mohar\Simulators\`), which `App\` never references.

### Integration simulators, S0 (ADR 0016 §3, ADR 0015 amendment 1)

- Added `App\Support\Integrations\Endpoints` (singleton, bound in `AppServiceProvider`) and the DTO
  `App\Data\Integrations\Endpoint` (`baseUrl`, `options`, `url(path)`; deliberately no "overridden" flag, so a
  driver has nothing to branch on). Drivers of fixed-host providers
  make one call, `Endpoints::for(key, default)`, which returns the URL together with its request options, so an
  overridden URL never comes without its pinning. Overrides come only from the environment (`INTEGRATION_ENDPOINTS`, a
  JSON object, and `INTEGRATION_CA_BUNDLE`) and are honoured only when the app runs as `local` or `testing`; every
  other environment, including an empty or unknown one, fails closed and logs them once as critical, keys only. Only
  https base URLs without credentials, query or fragment are used; malformed ones are logged once. An override is
  shaped like the default (trailing `/` kept or dropped to match), and `url(path)` joins with one `/`, so the request
  path is the same with and without it. Without one the base URL is the default and the options are `[]`. With one,
  TLS verification stays on (scenario CA) and the destination is resolved and pinned through `OutboundAddressGuard`.
  No driver uses it yet (S3, S5).
- `OutboundAddressGuard` reaches loopback only in `local` and `testing`, whatever `integrations.outbound.allow_loopback`
  says elsewhere, and gains `pinOptions(ResolvedAddress)`, the options `pin()` applies (compatible).
- Arch rules: nothing in `app/`, `bootstrap/`, `config/` or `routes/` names `Mohar\Simulators` (any case, group
  imports included), and no driver code mentions "simulat" or asks `Endpoints` whether an override is active
  (`activeKeys`, `developmentEnvironment`, `configuredKeys`). Driver code is every file under a module's `Drivers/`
  plus the module classes those files import (such as `PaymentsNepal\Support\ProviderHttp`); the three demo drivers
  (`Services/.../LogDriver`, `Domains/.../LogRegistrar`, `Billing/.../SandboxCardDriver`) are exempt by full path.

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
- `DriverMetadata` gains `?int $commandTimeoutSeconds` and `?UsernamePolicy $usernamePolicy` (cPanel K9 and K6;
  compatible, trailing). The host caps the timeout at `services.module.max_job_timeout_seconds`.

### Module inputs, server settings and one field shape (ADR 0009 amendment 1, PR B2)

- `ServerConnection::$settings` is a `#[\SensitiveParameter]` (compatible).
- Added `App\Support\Integrations\KeepsSecretsOutOfOldInput`: form requests that carry integration secrets flash
  old input without them on a validation error, driven by `SettingField` secret flags (used by the server and gateway
  forms).
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
  which keeps its shape and is **deprecated** (removed before kernel API 1.0.0, issue #68; read `fields`).
  Implementers MUST return both keys until then. Catalog reads `fields`. The gateway
  `fields` prop and the server form's `driverFields` use the same shape, rendered by one React component
  (`components/app/setting-field.tsx`).

### cPanel driver (#69)

- `DriverMetadata` gains trailing `refusedPorts: list<int>` (compatible): ports a server of the driver may not use.
  The host's server form refuses them, and the cPanel driver checks the same list before every call.
- `ProvisioningContext` gains trailing `?CarbonImmutable $deadline` (compatible): when the host stops waiting for the
  call. The host sets it once per job, from the command's timeout less 5 s, and keeps it across renamed attempts; a
  driver without one applies its own budget.
- `ProvisioningContext` gains trailing `?CarbonImmutable $unknownAttemptAt` (compatible): when an earlier attempt of the
  command, sent with the same username and domain, ended with an unknown outcome. The host records each create's
  target on its module command row before calling the driver. A driver may adopt only what that attempt created.
- `DriverResult::renameUsername(username, message)` (compatible): a retryable create that carries `data.username`,
  the free name to use when the assigned one is taken on the server. The host stores it and runs the create again at
  once (at most 5 times), so the name is recorded before it is ever sent.
- `Redactor` also masks `session=` credentials in URL query strings (cPanel login links), wherever they appear,
  percent-encoded ones included.
- `CommandLookup` gains trailing `array $data` (compatible, #73): for Executed, what the command would have reported
  (`DriverResult::$data` keys), so a command settled by a server check stores the same as a completed one.
- `DriverMetadata` gains trailing `?array $connectionFields` (compatible, #73): the host's shared server fields the
  driver reads (`username`, `password`, `api_token`, `access_hash`, `secure`, `port`), each with the driver settings it
  depends on. The server form shows only those; null keeps all of them.
- Test support: `ProvisioningDriverContract::verify()` gains a trailing `serverSettings` override (compatible; not ADR
  0010 public surface) for server settings whose placeholder would be invalid, such as a certificate pin.
