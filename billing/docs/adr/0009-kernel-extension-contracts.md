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

    ~~The `.np` manual driver implements only the core interface.~~ There is no `.np` driver (Amendment 2).

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

## Amendment 2 — 2026-10-22, after the reviews of PR C (#262, #264, #265)

PR C shipped in three parts: C1 the kernel contracts (#262), C2 Domains on them (#264), C3 the `registrar_refs`
store (#265). The architect accepted these differences from §2–§5 and `registrars.md` §2:

1. **`DomainRef::$periodYears`** (trailing, default 1): the registration term, which a driver needs where the
   registrar returns no expiry (Namecheap `domains.create`).
2. **`RegistrarConfig::$secretSettingKeys` and `secrets()`**, named like `ServerConnection::$secretSettingKeys`. Request
   DTOs that carry a secret say so: `RegistrationRequest::secrets()` returns the transfer code. The host masks
   `RegistrarConfig::secrets()` plus the request's secrets in everything a call returns or raises (the command log,
   `last_error`, the activity log, events, flash messages), using `RegistrarResult::withMessage()` and
   `SubmissionLookup::withMessage()`. The contract dataset still fails a driver that echoes any of them.
3. **`restore()`**: `RestoresDomains::restore(config, domain, years)` replaces `renew(..., restore: true)`.
4. **`test_connection` is a capability** (`TestsRegistrarConnection`), not core, as in provisioning. The host runs it
   through `RegistrarGateway` (command log, masking), also while the account is disabled so staff can test before
   enabling it, and disables "Test connection", with the reason, for drivers without it.
5. **`reconcile`** is the capability name of `ReconcilesSubmissions` (C2), matching `ProvisioningDriverRegistry::RECONCILE`.
6. **No `.np` driver**: since #199 clients register `.np` names at the registry themselves, so §3's `.np` line is void.

Kernel shapes decided now:

- **Contacts.** `ContactSet::ROLES` and `ContactSet::FIELDS` are kernel constants; the host sends exactly those roles
  and keys, with the formats documented on `ContactSet` (ISO 3166-1 alpha-2 country, phone as typed). Domains'
  `DomainContact` uses the kernel constants.
- **TLD attributes** (built with the ResellerClub driver, which needs them first): `RegistrationRequest` gains a
  trailing `array<string, string> $tldAttributes = []` (keys owned by the driver), and a capability
  `DeclaresTldAttributes::tldAttributeFields(string $tld): list<SettingField>` tells Domains which fields to render,
  validate and store per domain. Domains keeps no hand-written list of its own.
- **Premium** stays deferred. When it is switched on, costs are `App\Support\Money` (currency included) on both
  `AvailabilityResult` and `RegistrationRequest`, never bare integers.
- **Result statuses** (`RegistrarResult::$status`, `DomainInfo::$status`, `AvailabilityResult::$status`) stay strings
  by design: their values are documented on each DTO; `SubmissionLookup` uses the shared `ReconcileStatus`.
- **`RegistrarRefs`** is bound by Domains only, so a driver that injects it resolves only while Domains is loaded. Its
  `remember()` is last-writer-wins for now; first-writer-wins with a lock per local record is a follow-up (#267),
  before the first driver that writes refs.

## Amendment 3 — 2026-10-23, TLD attributes reach Orders (PR #345)

1. **`DomainDraft::$tldAttributes`** (trailing, default `[]`, `#[\SensitiveParameter]`, as `$eppCode` now is): the
   answers Orders collected, keyed by the field keys the driver declared. Domains validates them again in
   `createPending` and is the authority. Transfers carry none.
2. **`App\Contracts\Domains\TldAttributes`**, implemented by Domains and used by Orders: the order-time read of
   `DeclaresTldAttributes`.
    - `fieldsByTld(): array<string, list<SettingField>>` covers only the extensions that have fields. It makes no
      network call and runs a bounded number of queries, memoised per request.
    - `clean(string $tld, mixed $input): array<string, string>` drops unknown keys and throws `ValidationException`
      keyed `tld_attributes.<key>`. When the extension's registrar account is disabled it refuses with one error
      keyed `tld_attributes` instead.
    - `describe(string $tld, array $answers, bool $mask = true)` returns the answers as a person reads them (labels,
      option names, free text shortened to its last 3 characters), for the cart, the checkout and staff.
    - Orders treats an unbound `TldAttributes` as "no fields". It is separate from `DomainRegistry` so that existing
      implementations stay compatible (ADR 0010), and separate from the driver SPI.
3. **Fields a driver declares** are never secret and never `optionsSource: remote` (the host filters them out).
   Booleans are delivered as `'true'` or `'false'`. A driver may also implement `ValidatesTldAttributes` (an
   extension of `DeclaresTldAttributes`, not a separate capability) for rules between fields, such as the length of
   an identification number by its type.
4. **Storage and retention.** Answers are personal data, in the same class as domain contacts. They are stored in
   plain text in `domains.additional_fields.tld_attributes`, encrypted in the cart (a short-lived value, like the cart's
   EPP code), never written to the command log, activity log or errors, and kept for the life of the domain record.
   The host also masks the free-text answers (not select or boolean ones) in whatever a registrar call returns.
   Staff may view them; edits, when built, are logged by key, never by value. A field that would carry a personal
   government identifier needs an amendment first: an encrypted, `EncryptedStorage`-registered home, or a ban.
   Client erasure, when it is built, must clear them with the domain contacts.
5. **Account state.** It decides whether the fields are shown and whether new orders are taken for the extension, not
   whether answers that were already collected are kept.

## Amendment 4 — 2026-10-23, linking an existing order (PR #365)

1. **Capability `link_order`** (`RegistrarDriverRegistry::LINK_ORDER`), the interface `LinksExistingOrders` with
   `lookupOrder(RegistrarConfig, DomainRef, string $orderId): LinkedOrder`. It is read-only and runs only on a staff
   member's request, for a domain the host holds without a recorded order id. The driver accepts the order only if it is in
   our account, for exactly the domain's name, still holds it, and belongs to the customer the host uses for the
   domain's client; it refuses with a plain message otherwise. The host records `remote.order_id` only when the answer
   carries the order id that was typed, and itself refuses `.np` and other self-registered names. Nothing is ever
   linked by searching for a name or by an email address. `LinkedOrder` carries `remote`, `status` and `expiryDate`.

## Amendment 5 — 2026-10-23, masking a registrar result (PR #368)

**`RegistrarResult::mapText(Closure $mask, string $withheld, int $maxDepth = 8)`** returns a copy with the message and
everything in `meta` masked: string values and string keys, arrays walked. Anything it cannot check is replaced by the
withheld marker: a branch nested deeper than `$maxDepth`, an object or other non-scalar value, and a branch whose key the
mask changed. `remote` is never masked: its values are the registrar ids that prove ownership and later calls need, and
a mask would change one. The host uses it to mask a customer's free-text TLD answers (Amendment 3, item 4).

## Amendment (#408) — 2026-10-23, registrar refs are first-writer-wins

1. `RegistrarRefs::remember(RegistrarRef): RegistrarRef` returns the stored ref and never replaces one: for a key
   (registrar, type, local record, payload hash) the first writer wins, and every concurrent caller receives that
   ref. A wrong ref cannot be overwritten; correcting one needs a staff-facing path (follow-up).
2. Lock rule: the store needs no lock (unique key). A driver that must not create duplicate remote objects holds a
   cross-process lock on the ref key around find, create and remember, with a TTL longer than the registrar call. A
   returned `remoteId` different from the one passed means the caller's object is an orphan at the registrar.
3. Core binds a null `RegistrarRefs` for installs without Domains.
4. A driver is active without a saved account only if it implements the marker `StandaloneRegistrar` or declares no
   settings (`RegistrarDriverRegistry::needsAccount`). Other drivers are inactive until staff save the account.

## Amendment (#489) — 2026-10-25, certificate settings and credential expiry

Approved by the owner and CTO as K1 of `docs/product/payments-gateways-expansion.md` §9.2 (connectIPS signs with an
RSA key NCHL ships as a PKCS#12 file).

1. **`FieldType::Certificate` (`certificate`).** A new case on a closed enum, so breaking under ADR 0010; allowed
   before 1.0 because every in-repo consumer is updated in the same PR. It is a secret type: write-only, encrypted, in
   `GatewayConfig::$secretSettingKeys` and so redacted line by line (`RedactionSet`). Staff upload the `.pfx`/`.p12`
   (at most 64 KB) with its passphrase; `Support\Integrations\CertificateBundle::fromPkcs12()` reads it in memory with
   OpenSSL, checks that the key belongs to the certificate and that it has not expired, and returns the unencrypted PEM
   key with `Data\Integrations\CertificateInfo` (subject, issuer, serial, validity, the certificate and the chain the
   file carried, so mutual TLS later needs no new upload). The file and passphrase are never kept; the bundle masks
   the key in dumps and refuses serialisation. A refusal is `CertificateRefused`, whose message is safe to show.
   RC2-protected files are refused (OpenSSL 3 reads them only with its legacy provider); 3DES and AES files read. A
   file with a huge key-derivation iteration count costs CPU time in that request: accepted, because only staff with
   the integration's manage permission can upload, after the password step-up. An empty save keeps the stored
   certificate; text sent for the setting is ignored.
2. **Storage.** The PEM key is the setting's value. `CertificateInfo` sits in the same encrypted settings under
   `CertificateInfo::SETTINGS_KEY` (`_certificates.<key>`); drivers read it through `GatewayConfig::certificate($key)`.
   Setting keys starting with `_` are reserved for such host entries (`SettingField` refuses them). Expiry bookkeeping
   (serial, expiry instant, last warning sent) lives in Core's plain `integration_credentials` table (family, owner,
   setting key), so the daily check and the dashboard never decrypt settings.
3. **Hosts.** Only the gateway host stores certificates so far. `RegistrarDriverRegistry::fields()` and
   `ProvisioningDriverRegistry::metadata()` throw for a driver that declares one, and the registrar and server forms
   refuse the input (`prohibited`).
4. **Events** `Events\Billing\GatewayCredentialExpiring` (gateway key and name, setting key and label, `expiresAt` as
   UTC `CarbonImmutable`, company-calendar days left) and `GatewayCredentialExpired` (the same, `expiredAt`), like
   `DomainExpiring`/`DomainExpired`. Billing's daily task `billing.gateway_credentials` raises the first once per stage
   of `billing.gateways.certificate_warning_days` (30, 7, 1 by default) and the second once after the expiry instant,
   for active gateways. Notifications emails staff with `billing.gateways.manage`.
5. **Planned, not built:** when a registrar or server host first needs certificates, a family-neutral path replaces the
   gateway-only one: a source registry of stored credentials (like `StoredSecrets`, ADR 0023), one Core daily check over
   `integration_credentials`, and `Events\Integrations\IntegrationCredentialExpiring`/`Expired`, with the gateway
   events dispatched beside them for the ADR 0010 deprecation window. No `RegistrarCredentialExpiring` or similar is
   added meanwhile.
6. **`Dashboard::health()` resolvers may return null** to leave the check out (Billing's certificate line shows only
   when an active gateway has a certificate: amber within the first warning stage, red once expired).
7. **Hosts list only valid drivers** (#490). `DriverRegistry::get()` runs the family's `validate()` hook (the registrar
   and provisioning registries refuse certificate settings there), and `keys()`/`all()` report and leave out a driver
   that fails it, so one broken package cannot break the pages that list every driver. Billing's certificate check and
   health line skip, and report, a gateway driver whose fields cannot be read, and count only certificate settings a
   driver still declares. `has()` agrees with `keys()`, so nothing new is bound to a hidden driver (a product keeps a
   driver it is already bound to); `isRegistered()` answers for every registered key, and a caller that handles a
   driver's secrets fails closed on a registered but hidden driver (Catalog's `DriverSecrets` and Services'
   `ServerSettings::secretKeys()` treat every setting as secret). A failure of the family's own checks is remembered
   for 60 seconds; any other failure (a driver that cannot be built) is remembered until the request or queued job
   ends, so it is built and reported once per request or job and a long-running worker never hides a driver for good.
   `unavailable()` lists hidden drivers with a reason, the admin dashboard health card names them, and service screens
   show such a driver as unavailable rather than as manual setup. A host whose owner record can be deleted deletes its
   `integration_credentials` rows in the same transaction; gateways are never deleted, so none does yet.
8. The step-up refusal change is recorded in ADR 0018 Amendment (#490).

## Amendment (#491) — 2026-10-25, gateway capability list and settings validation

1. **Gateway optional capabilities, as the code has them.** The table's "Optional capabilities" row for gateways
   names three interfaces; the full set is: `HostedPaymentDriver`, `ConnectionTester`, `ProvidesLogo`,
   `LooksUpRefunds` (ask about a refund whose answer never arrived), `CancelsHostedPayment` (close an open provider
   session), `ResumesHostedPayment` (reopen it), `DescribesManualRefund` (#491: the driver's own refund note, read
   only when it does not declare `REFUND`) and `Integrations\ValidatesSettings` (#491). Each is a provider contract,
   detected with `instanceof` (ADR 0012).
2. **`Integrations\ValidatesSettings`** (`validateSettings(array $settings): array<string, string>`) is family-neutral,
   like `ProvidesLogo`, and shaped like `ValidatesTldAttributes`: pure (no network, database or settings access, since
   it runs in the save request), messages name the field by its label and never repeat the value, and secret values
   are never passed. The gateway form calls it after its own field checks and shows each message at
   `settings.<key>`. The registrar and server forms do not call it yet; they adopt it when a driver needs it.
