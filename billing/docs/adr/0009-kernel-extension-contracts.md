# ADR 0009 — Kernel extension contracts for provisioning and registrar drivers

- Status: Accepted · 2026-10-18 (CTO) · Owner: Principal architect
- Roadmap: wave 3, item 3.1 (`docs/parity/roadmap.md`). Unblocks 3.2 control panels, 3.3 registrars, 3.5 importers.
- Depends on: ADR 0010 (contract evolution), 0011 (events), 0012 (capability interfaces and registries).

## Context

Mohar has three driver families. Only one of them is open to outside packages, and each was built a different way:

|                       | Gateways (Billing)                                                                                  | Provisioning (Services)                                                 | Registrars (Domains)                                                           |
| --------------------- | --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Interface lives in    | `app/Contracts/Billing/GatewayDriver.php` (kernel)                                                  | `Modules/Services/Drivers/Provisioning/ProvisioningDriver.php` (module) | `Modules/Domains/Drivers/Registrars/Registrar.php` (module)                    |
| Registration          | `GatewayRegistry::register(GatewayDriver $instance)`; contract bound by Billing only                | `DriverRegistry::register(string $key, class-string)` (module class)    | `RegistrarManager::extend(string $key, class-string\|Closure)` (module class)  |
| Driver input          | DTOs (`GatewayConfig`, `PaymentIntent`, `PaymentAttemptRef`)                                        | Eloquent models (`Service`, `Server`) inside `ServiceContext`           | Eloquent `Domain` model; stateful `configure(array $settings, bool $testMode)` |
| Optional capabilities | string flags plus capability interfaces (`HostedPaymentDriver`, `ConnectionTester`, `ProvidesLogo`) | string flags on `DriverMetadata`                                        | string flags; unsupported methods throw `CapabilityNotSupported`               |
| Settings fields       | `type: 'secret'\|'secret_text'\|…`                                                                  | `type: 'password'`, plus `secret: bool`                                 | `type: 'password'`                                                             |
| Connection test       | `ConnectionTester::testConnection(GatewayConfig): ConnectionCheck`                                  | `testConnection(Server): DriverResult`                                  | `testConnection(): array{ok, message}`                                         |
| Failure model         | result DTOs with `retryable`                                                                        | `DriverResult` plus `RetryableProvisioningException`                    | `RegistrarResult` plus `RegistrarException(retryable)`, `RegistrarTimeout`     |

A package that wants to ship a cPanel or ResellerClub driver today must import module classes, which ADR 0001 forbids.
Drivers that receive Eloquent models are also coupled to the owning module's schema. They can lazy-load relations,
write to the database, and read secrets outside the redaction path.

The registrar workstream (3.3) adds contract changes C1 (remote ids) and C2 (`findSubmission` reconcile) from
`docs/product/registrars.md` §2. Making them during the move avoids a second breaking change to the registrar interface.

## Decision

### 1. Placement

- Provisioning goes in `App\Contracts\Services\Provisioning\*` and `App\Data\Services\Provisioning\*`.
- Registrars go in `App\Contracts\Domains\Registrar\*` and `App\Data\Domains\Registrar\*`.
- Kernel exceptions for these areas live in `App\Support\Services\` and `App\Support\Domains\`, following the
  precedent set by `App\Support\Currencies\ExchangeRateUnavailable` (#43).
- Pieces shared by every integration go in a new `Integrations` area:
    - `App\Data\Integrations\SettingField` and `App\Enums\FieldType`, with the cases `String`, `Text`, `Number`,
      `Boolean`, `Select`, `Url`, `Secret` and `SecretText`. One field schema replaces the three array shapes. A secret
      is a field type, not a separate flag.
    - `App\Data\Integrations\ConnectionCheck` (moved from `Data\Billing`).
    - `App\Contracts\Integrations\ProvidesLogo` and `App\Data\Integrations\IntegrationLogo` (moved from `Billing`). A
      control panel or registrar driver can then ship a logo the same way Khalti and eSewa do.
    - `App\Support\Integrations\Redactor`: Services' `Redactor` and Domains' `RegistrarGateway` secret lists, merged,
      plus the registrars.md C5 keys (`api-key`, `passwd`, `signature`). The host redacts; drivers never call it.

Per ADR 0010 this lands inside the pre-1.0 window: every in-repo implementer is updated in the same PR, with no
aliases.

### 2. Drivers are stateless and receive DTOs, never models

**Provisioning.** `ProvisioningContext` replaces `ServiceContext`. It carries:

- `ServiceSnapshot`: `id`, `clientId`, `productId`, `status`, `?domain`, `?username`, `?password`, `?dedicatedIp`,
  `assignedIps`, `driverData`, `?billingCycle`, `?nextDueDate` (a `Y-m-d` string).
- `?ServerConnection`: `id`, `name`, `hostname`, `?ip`, `?port`, `secure`, `?username`, `?password`, `?accessHash`,
  `settings`, and `secrets()`.
- `ClientSummary`: `id`, `name`, `email`, `?country`. This is the minimum a panel needs; it is not the client record.
- `productConfig`, `configOptions` (by module key), `customFields` (by module key), `secretValues`, and `secrets()`.

Services builds the snapshot. Drivers report changes back only through `DriverResult::$data` (documented keys:
`username`, `password`, `dedicated_ip`, `driver_data`). Secret-bearing constructor parameters are marked
`#[\SensitiveParameter]`.

**Registrars.** The driver no longer has a `configure()` method. Every call receives a
`RegistrarConfig(key, settings, testMode)` as its first argument, as gateways receive `GatewayConfig`, so a resolved
driver holds no account state. This makes singletons safe and leaves room for several accounts per registrar. `DomainRef`
replaces the `Domain` model: `id`, `clientId`, `name`, `sld`, `tld`, `status`, `?expiryDate`, `registrarKey`,
`remote` (C1), `nameservers`, `?locked`, `idProtection`. `RegistrationRequest` holds a `DomainRef`, and its `eppCode`
is marked sensitive.

### 3. Core interface plus capability interfaces (ADR 0012)

- **`ProvisioningDriver`** (required): `key()`, `name()`, `metadata(): DriverMetadata`, plus `create`, `suspend`,
  `unsuspend` and `terminate`, each taking a `ProvisioningContext`. `DriverMetadata` keeps `requiresServer`, the
  default ports, and `productConfigFields`/`serverFields`, now as `list<SettingField>`. Its `capabilities` list is
  derived by the registry, not declared.
- **Provisioning capabilities**: `ChangesPackage`, `ChangesPassword`, `ReportsUsage` (`usage(ServerConnection)`),
  `TestsServerConnection` (`testConnection(ServerConnection): ConnectionCheck`), `ProvidesServiceSso`,
  `ProvidesServerSso` (both return `SsoLink(url, expiresAt)`), `ListsAccounts` (`listAccounts(ServerConnection)` →
  `list<RemoteAccount>`, which the importer needs), and `RenewsAccounts`.
- **`RegistrarDriver`** (required): `key()`, `name()`, `settingsFields(): list<SettingField>`, `register`, `renew`,
  `info`.
- **Registrar capabilities**:
    - `ChecksAvailability` (availability)
    - `SuggestsNames`
    - `TransfersDomains` (`transfer` + `transferSync`)
    - `RestoresDomains`
    - `ManagesNameservers`
    - `ManagesLock`
    - `ManagesContacts` (get + set)
    - `ProvidesEppCode`
    - `ManagesIdProtection`
    - `SyncsDomains`
    - `ReconcilesSubmissions` (C2: `findSubmission(RegistrarConfig, DomainRef, string $command, CarbonImmutable
$submittedAt): SubmissionLookup`)
    - `TestsRegistrarConnection`

    The `.np` manual driver implements only the core interface.

- `RegistrarGateway` and `ExecuteModuleCommand` keep the capability check, but it becomes `instanceof`. The string
  lists the admin UI shows (`capabilitiesOf()`) are computed from the interfaces, so the props do not change.
  `CapabilityNotSupported` remains as a guard and is never part of normal control flow.

### 4. Outcomes and errors (one vocabulary, family-specific types)

Every driver method maps every response to exactly one outcome:

- `completed`
- `pending`: accepted, finishes asynchronously
- `rejected`: nothing executed, do not retry
- `retryable`: provably not executed
- `unknown`: may have executed; staff verify or the host reconciles. Never retried automatically.

This vocabulary is already the rule in `registrars.md` §0 and in `ProvisioningDriver`'s docblock.

- Provisioning keeps `DriverResult`, which gains a trailing field `public bool $outcomeUnknown = false`. With it,
  `ExecuteModuleCommand` opens a "verify" queue item instead of guessing. `RetryableProvisioningException` moves to the
  kernel.
- Registrars keep `RegistrarResult` (plus `remote`, C1), `RegistrarException(retryable)` and `RegistrarTimeout`, which
  move to the kernel. The HTTP rendering in `RegistrarException::render()` moves to an exception renderer registered
  by Domains, so that kernel exceptions do not depend on HTTP.

Rejected: one result type for all families. The async semantics differ (gateways have lookups, registrars have
pending submissions), and rewriting merged, reviewed money and provisioning paths buys nothing for driver authors.

### 5. Registration API

The registries are kernel-owned concrete classes, bound as singletons in `AppServiceProvider`. They therefore exist
whether or not Services or Domains is loaded:

```php
// in any package's ServiceProvider::boot(), with no dependency on the Services module being present
$this->app->make(ProvisioningDriverRegistry::class)->register('cpanel', CpanelDriver::class);
$this->app->make(RegistrarDriverRegistry::class)->register('resellerclub', ResellerClubDriver::class);
```

- `App\Support\Services\ProvisioningDriverRegistry` and `App\Support\Domains\RegistrarDriverRegistry` follow the
  ADR 0012 registry rules:
    - `register(string $key, class-string<T>|Closure(Container): T $driver, bool $replace = false)`
    - `has`, `get`, `keys`, `metadata`, `capabilitiesOf`
    - lazy, container-resolved
    - keys match `[a-z0-9_]{1,50}`; a duplicate key throws unless `replace` is set
    - on first resolve, the registry asserts `driver->key() === $key`
- Services' `DriverRegistry` and Domains' `RegistrarManager::extend()` delegate to the kernel registries in the same
  PR and are then removed. `RegistrarManager` keeps only account handling: it loads `registrar_accounts`, builds the
  `RegistrarConfig`, and answers `isActive`.
- `Contracts\Services\ProvisioningDrivers` (the Catalog read API) is unchanged and reads the kernel registry.
- Declined: `DomainRegistry::registerDriver()` (`developer-architecture.md` §13) and `register()` on
  `ProvisioningDrivers`. Both are implemented by their module, so a package registering while that module is absent
  would fail with a binding error. Both would also mix the order-time API with the extension SPI.
- `C4 RegistrarRefs` (`registrars.md` §2) becomes the kernel contract `App\Contracts\Domains\Registrar\RegistrarRefs`,
  implemented by Domains and injected into drivers, so that packaged drivers can use it.

### 6. Events (missing from the roadmap list), per ADR 0011

All of these are dispatched after commit, carry an occurrence id, and contain ids and scalars only:

| Event                             | Payload                                                                                                                  | Dispatched                                                                                                                                                                                                                 |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Services\ModuleCommandSucceeded` | `moduleCommandId`, `serviceId`, `clientId`, `driver`, `command`, `initiator`                                             | Once per command that succeeds (not per attempt).                                                                                                                                                                          |
| `Services\ModuleCommandFailed`    | `moduleCommandId`, `serviceId`, `clientId`, `driver`, `command`, `initiator`, `error` (redacted), `outcomeUnknown`       | Once per command, on final failure. Supersedes `ProvisioningFailed`, which is deprecated and dispatched alongside it until removed (ADR 0010).                                                                             |
| `Services\ServicePackageChanged`  | `serviceId`, `clientId`, `fromProductId`, `toProductId`, `remoteApplied`                                                 | When a product or option change is applied to the account: after a successful `change_package`, or at once with `remoteApplied: false` for drivers without `ChangesPackage`. `ServiceUpgraded` stays the commercial event. |
| `Orders\OrderPaid`                | `orderId`, `clientId`, `invoiceId`                                                                                       | The first time the order's invoice is paid. Orders' `InvoicePaid` listener sets a new `orders.paid_at` with `whereNull('paid_at')`, so the event fires exactly once.                                                       |
| `Billing\InvoicePublished`        | `invoiceId`, `clientId`, `notify`, `?reason`                                                                             | When an invoice becomes visible and payable to the client: issued as unpaid, or a draft published. Notifications sends "invoice created" mail from this event, never from a draft. `InvoiceCreated` keeps its meaning.     |
| `Orders\FraudCheckCompleted`      | `orderId`, `clientId`, `checker`, `decision` (`App\Enums\FraudDecision`, moved from Orders), `score`, `reasons` (no PII) | After every screening, including `skipped` and `error`.                                                                                                                                                                    |

The new events are added to the Notifications webhook allow-list where the spec lists them. `ModuleCommandFailed`
also feeds the provisioning health check.

### 7. Out of scope

- Fraud checker and inbound-mail drivers follow the same pattern in an addendum. Only the `FraudDecision` enum moves
  now.
- Veto and modify hooks and UI slots are wave 4.
- Shared failure model across families (see §4).

## Delivery plan

1. **PR A, kernel only.** The `Integrations` area, the two registries, and the moved Billing `ConnectionCheck` and
   `ProvidesLogo`. Gateway `settingsFields()` keeps returning arrays, read through `SettingField::fromArray()` until
   the gateway family is aligned. No behaviour change.
2. **PR B, provisioning.** Contracts and DTOs; `ExecuteModuleCommand` builds snapshots; `LogDriver`/`NoneDriver` updated;
   `outcomeUnknown`; a shared Pest contract dataset (`tests/Support/Contracts/ProvisioningDriverContract.php`). Depends
   on A.
3. **PR C, registrars.** Contracts and DTOs including C1, the C2 interface and C4; stateless `RegistrarConfig`;
   `LogRegistrar` updated; `RegistrarDriverContract` dataset (`registrars.md` §7). Depends on A and can run in parallel
   with B. The Registrars prerequisites PR (C3 = issue #12, C5) rebases on it.
4. **PR D, events.** The six events above, with listeners in their owning modules, the webhook mapping, and the deprecation of
   `ProvisioningFailed`. Depends on B.

Each PR gets the principal-architect gate.

## Verification

- New arch rules:
    - `App\Data` does not use `Illuminate\Database\Eloquent\Model`.
    - `App\Contracts\*\Provisioning` and `App\Contracts\*\Registrar` are interfaces.
    - Every `App\Events` class implements `App\Contracts\Events\DomainEvent` (ADR 0011).
- A fixture package outside `app/Modules` (`tests/Fixtures/Extensions/AcmePanel`, a plain `ServiceProvider`) registers
  a provisioning driver and a registrar driver. Tests prove that:
    - registration succeeds with `MODULES=Core`;
    - with Services and Domains loaded, the drivers appear in the Catalog driver list and the registrar admin;
    - the drivers pass the shared contract datasets.

## Consequences

- Control panel, registrar and importer work (3.2, 3.3, 3.5) builds on stable kernel types. Packages can ship
  drivers without touching core.
- Drivers can no longer read or write Services or Domains tables. Anything a driver needs must be in the snapshot,
  which is a deliberate and reviewable list.
- `LogDriver`, `NoneDriver` and `LogRegistrar` change signatures once. No external driver exists yet, so this is the
  cheapest moment.
- The gateway family still differs in two places (instance registration, array settings fields). ADR 0012 lists them
  for alignment.

## Amendment 1 — 2026-10-18, after the architect review of PR A and the PR B shape

- **Registries ship with their family PR.** PR A ships the shared base `App\Support\Integrations\DriverRegistry` and
  `App\Contracts\Integrations\IntegrationDriver`. Each concrete registry lands with its interfaces:
  `ProvisioningDriverRegistry` in PR B, `RegistrarDriverRegistry` in PR C. Each is `final` and bound in
  `AppServiceProvider`, never in a module. An arch rule forbids modules from extending the base.
- **Settling unknown outcomes.** The provisioning capability is
  `ReconcilesCommands::reconcile(ProvisioningContext, string $command, CarbonImmutable $attemptedAt): CommandLookup`.
  It shares one kernel enum, `App\Enums\ReconcileStatus` (`executed`, `in_progress`, `not_executed`, `conflict`,
  `unknown`), with the registrar C2 lookup. The driver interprets the command; the host keeps the policy and never
  re-runs a command on its own.
- **Remote-filled settings.** A generic `ProvidesFieldOptions::fieldOptions(ServerConnection, string $fieldKey)` and
  `SettingField::$optionsSource = 'remote'` replace per-noun list capabilities such as packages, templates or plans.
- **The secret set.** `ProvisioningContext::secrets()` contains the server credentials, the secret-typed server
  settings (`ServerConnection::$secretSettingKeys`), the service password, and every Secret- or SecretText-typed
  value in the product settings and custom fields. The host redacts all of them; the contract dataset proves it.
- **Module inputs.** `configOptions` and `customFields` are keyed by module key and read through a new
  `PriceBook::moduleInputs()`. It includes hidden options, admin-only fields and retired products. It lands in PR B2
  (stacked on B), together with the encrypted `servers.settings` column behind `ServerConnection::$settings`.
- `UsernamePolicy` (a host default of 8 characters, replaced by the driver's own policy) and
  `DriverMetadata::$commandTimeoutSeconds` (capped at 180 s; see the ADR 0014 invariant) come from the cPanel spec,
  §13 K6 and K9.
