# ADR 0012 — Capability interfaces vs flags, and one extension-registry pattern

- Status: Accepted · 2026-10-18 (CTO) · Owner: Principal architect

## Context

**Capabilities are declared two ways at once.** `GatewayDriver` has eleven capability constants. Billing checks
`supports()` for `CAPTURE`, `TOKENIZE`, `REMOTE_INPUT`, `REFUND`, `PARTIAL_REFUND` and `WEBHOOK`, but detects hosted
checkout with `instanceof HostedPaymentDriver` (`GatewayManager.php:47`). Five constants are never read anywhere:
`REDIRECT`, `LOOKUP`, `QR`, `STORED_METHODS` and `PENDING_SETTLEMENT`. Khalti and eSewa still declare
`REDIRECT`/`LOOKUP`, so a flag and an interface can disagree. `ConnectionTester` and `ProvidesLogo` (#26) are
interfaces. Provisioning and registrar drivers use only flags, and registrar methods outside the flags throw.

**Registries have five registration shapes:**

| Registry                        | Where                      | Registration                                 | Resolution                                   |
| ------------------------------- | -------------------------- | -------------------------------------------- | -------------------------------------------- |
| `GatewayRegistry`               | contract, bound by Billing | `register(GatewayDriver $instance)`          | eager instances                              |
| `DriverRegistry` (provisioning) | Services module class      | `register(string $key, class-string)`        | lazy                                         |
| `RegistrarManager`              | Domains module class       | `extend(string $key, class-string\|Closure)` | lazy, then mutated by `configure()`          |
| `ExchangeRateSources` (#43)     | `app/Support`              | `register(class-string)`                     | eager: `app($class)` at boot to read the key |
| `PriceConverters` (#43)         | `app/Support`              | `register(class-string)`, keyed by class     | lazy, but `get($key)` resolves all           |

Because `GatewayRegistry` is bound by Billing, a package that calls `register()` directly while Billing is absent
gets a binding error. PaymentsNepal avoids this with `callAfterResolving()`, but the convention is not written down
anywhere.

## Decision

### Capabilities

1. If a capability adds methods, it is an interface, and consumers detect it with `instanceof`. Examples:
   `HostedPaymentDriver`, `ConnectionTester`, `ProvidesLogo`, and the ADR 0009 interfaces.
2. A flag is allowed only for whether a capability the driver has is enabled under a given configuration, for
   example `PARTIAL_REFUND` when `payments_nepal.khalti.partial_refunds` is off. A flag is never a second declaration
   of something an interface already declares.
3. A string list for the UI (badges, props) is computed by the registry from the interfaces and enabled flags, never
   declared by hand.
4. For gateways, deprecate `REDIRECT`, `LOOKUP`, `QR`, `STORED_METHODS` and `PENDING_SETTLEMENT` (ADR 0010). Keep
   `CAPTURE`/`REFUND`/`TOKENIZE`/`WEBHOOK`/`REMOTE_INPUT` as flags for now: their methods sit on the base interface
   with `AbstractGatewayDriver` defaults. Moving them to interfaces is a 2.0 change. Until the deprecated flags are
   removed, a test asserts that `supports(REDIRECT)` holds exactly when the driver is an `instanceof
   HostedPaymentDriver`, for every registered driver.

### Registries

Every registry that extensions write to follows one shape:

- It is a concrete class in `app/Support/<Area>/`, bound as a singleton in `AppServiceProvider`, never by a feature
  module. It exists whatever `MODULES=` contains, so registration cannot fail because the consuming module is absent.
- `register(string $key, class-string<T>|Closure(Container): T $item, bool $replace = false): void`. Keys match
  `[a-z0-9_]{1,50}`. A duplicate key throws unless `$replace` is set, and a replacement is logged.
- Resolution is lazy, through the container. `register()` does no I/O and resolves nothing. Listing metadata may
  instantiate items, so constructors must be cheap: no HTTP, no queries, no decryption.
- Reads are `has(key)`, `get(key): T` (throws for an unknown key), `keys()`, and a typed `all()` or `metadata()`.
- The registry checks on first resolve that the item's own `key()` equals the registered key.
- A registry holds no request or account state. Per-account configuration is passed per call (`GatewayConfig`,
  `RegistrarConfig`).

UI-contribution registries (`Navigation`, `Dashboard`, `SettingsRegistry`, `PermissionRegistry`, `GlobalSearch`,
`ThemeRegistry`) keep their own methods. They are already kernel-owned, and the "Registries" rules apply to new
methods: options with defaults come after the third parameter and are documented with named arguments, and payload
shapes are documented next to the method (ADR 0010).

### Alignment of existing registries

- `ExchangeRateSources` and `PriceConverters` (#43): change to `register(key, class)`. Not blocking for #43. Once
  merged, the change is compatible: the old one-argument form keeps working, deprecated.
- `GatewayRegistry`: add a kernel `App\Support\Billing\GatewayDriverRegistry` with the shape above. Billing's
  `GatewayManager` reads it. `GatewayRegistry::register(instance)` stays as a deprecated adapter, so packages stop
  needing `callAfterResolving()`.
- Provisioning and registrar: ADR 0009.

## Consequences

- One way to declare a capability, and one way to register an extension. Packages register in `boot()` without
  knowing which modules are installed.
- Five dead gateway constants are deprecated rather than deleted. Removal waits for 2.0, or happens sooner within the
  pre-1.0 window if no external gateway exists by then.

## Addendum (2026-10-21, client importer #184): registries that combine answers

The registries above are lookups: one item per key, read with `get(key)`. An **aggregating registry** asks every
registered module the same question and combines the answers: `App\Support\Clients\ClientCurrencyUsage` (which
clients hold amounts in their currency) and `ClientMergeRegistry` (what a client merge touches, and vetoes). Use one
when a question about a kernel-owned record is answered by several modules' tables, which Core must not read. It is
not a service contract: no single module owns the answer.

**Shape.**

- `register(string $key, Closure $source, bool $replace = false): void`, on a concrete `@api` class in
  `app/Support/<Area>/` bound as a singleton in `AppServiceProvider`. `register()` stores the closure and calls
  nothing. If a source ever needs a second method, the registry accepts `class-string<Source>|Closure` (ADR 0010).
- **Keys** are the registering module's key (`billing`), or `<module>_<part>` when a module registers several, and
  match `[a-z0-9_]{1,50}`. A duplicate throws `LogicException` unless `$replace` is set; a replacement is logged. A key
  names the contributor for replacement, tests and logs, and never selects a source at read time.
- **Batch input.** A source gets a list of ids, once per batch (ADR 0020), and returns the subset it holds. The
  registry normalises the result (integers, unique, limited to the ids asked).
- **Combination** does not depend on registration order, which only affects display and cost.
- **Sources** are read-only, one indexed query each. They may run in the caller's transaction, under its row locks
  (ADR 0021).

**No source registered.** The registry returns the answer that is true for an install without those modules: no
client in use, no participants, no blockers. Callers never treat an empty registry as an error or read `keys()` to
choose behaviour. Removing a module from `MODULES=` while its tables hold data is not an uninstall path.

**Tests.** Each aggregating registry has:

1. a registry test: keys, duplicate and `replace`, combination (`tests/Feature/Core/ClientCurrencyUsageTest.php`);
2. a registration test with every module loaded: `keys()` equals the expected set and each source answers for a
   record it owns, soft-deleted ones included where they count (B3 in `ImportApplyReReviewProbesTest`). A module that
   starts storing per-client data adds its key there;
3. a siblings-absent test in `tests/Extensions`: without the contributing modules `keys()` is empty and the caller's
   flow works on the empty answer (`MigrationAloneTest`).

**Alignment.** `ClientCurrencyUsage::source()` is renamed `register()` (#219). `ClientMergeRegistry::participant()`
has dotted keys (`core.contacts`) and no duplicate check; it moves to this shape in a follow-up, under the pre-1.0
window (ADR 0010).

## Addendum (2026-10-22, #255): optional services say whether they are available

An optional kernel service whose null default does nothing (a Core binding that a module replaces) exposes a pre-check
on the contract: `problem(): ?string` (a sentence for staff, or null when the service can act now) or
`available(): bool` when there is nothing to explain. The null default answers it ("not installed"); the real binding
answers for its own configuration (for example, `AccountLinks::problem()` for invitation links). Callers ask the
contract and never test the class of a binding (`instanceof Null…`): a package's own unavailable binding, or a
decorator around the null one, must answer the same way. Examples: `GatewayDirectory::available()`,
`ClientLoginInvitations::problem()` (#255).

## Amendment (#405) — 2026-10-23, event-keyed registries and kernel-owned keys

`App\Support\Events\WebhookEvents` (ADR 0011) is an allowed exception to the `register(key, item)` shape: it is keyed
by the event class, and the public webhook type is the second argument, `register(event, type, entity, idProperty, replace)`.

- The type is a public name that subscribers store, so its pattern is `<area>.<action>` (`[a-z0-9_]+(\.[a-z0-9_]+)+`, up to
  50 characters), not `[a-z0-9_]{1,50}`. A type and an event class are each registered once.
- `entity` and `idProperty` are not validated at registration (nothing is resolved); an unknown entity falls back to the
  event's scalar properties.
- **Replace policy.** `replace: true` cannot take over a type whose registered event class is under `App\Events\`
  (kernel-owned): it throws `LogicException`. Replacing a package-owned type is allowed and logged at warning with the
  previous and new event class. Other registries keep the general rule above.
- Types must be registered in a provider's `boot()`: the consuming listeners attach after all providers have booted.

## Amendment (#432) — 2026-10-23, `SettingLocks`

- A kernel-owned registry next to `SettingsRegistry`: `lock(keys, reason, guard)` and `reason(key)`. A module registers the
  settings it fixes while a state holds (Billing: the numbering settings while Nepal VAT mode is on). Platform's catalog shows
  them locked with the reason and its save refuses a change, under a shared lock on the module's guard row. It holds no request
  state: the reason closure reads the database. `SettingDefinition` stays plain data.
- The guard row is created by the module, never by Platform: the save only locks it. A row that reads the same as "unset"
  is fine; a JSON-null row would make `Settings::get()` return null instead of the config default and would skip the
  settings cache, so Platform does not write one. Reasons are remembered per request until `flush()`.

## Amendment (#555) — 2026-10-31, kernel contracts that receive secrets are closed to packages (Accepted)

Status: Accepted 2026-10-31 (owner approved `client-import.md` Q13), with ADR 0018 Amendment (#555). It comes from the #555 design review (B2).

- **The rule.** A kernel contract with a method that receives a plaintext credential belongs to a separate class from
  the extension points this ADR describes. Such credentials include a typed password, a one-time code and a recovery
  code. The class has these rules:
    - It has **exactly one first-party implementation**, in a named module, plus Core's null default. It is never a
      registry, and its docblock says it is not an extension point.
    - Every secret parameter carries `#[\SensitiveParameter]` on the interface and on every implementation.
    - A secret crosses the boundary only after a secret-free method has said the implementation wants it, for
      example `pending(User): bool` before `check(..., $password, ...)`. The kernel never hands every failed secret
      to the implementation.
- **Enforcement.**
    - An Arch test lists the allowed implementer and the null class for each such contract. It fails on any other
      class that implements it, in `app/` or a package's PSR-4 roots.
    - At boot, the kernel checks that the bound class is one of the allowed two, and refuses to start otherwise. A
      package therefore cannot rebind the contract.
- **First case:** `App\Contracts\Auth\PendingPasswordCheck` (ADR 0018 Amendment (#555)), implemented in
  `app/Modules/Migration`.
- **Packages.** Packages keep every other extension point in this ADR. A package that needs a secret-receiving hook
  asks for an ADR, as any new kernel surface does under ADR 0010.

## Amendment (#590) — 2026-10-31, the gateway registry is aligned (ADR 0030 M3)

- `App\Support\Billing\GatewayDriverRegistry` extends `DriverRegistry` and is bound in `AppServiceProvider`.
  Extensions call `register(key, class)` in `boot()` and bind stateful drivers as singletons. Billing's built-in
  drivers register when the registry is first built, so they are listed first.
- `GatewayRegistry::register(instance)` is the deprecated adapter this ADR planned. It keeps the old replace
  semantics. `GatewayManager` is Billing's host over the registry and lives in `Services/`.
- `DriverRegistry::enabled()` is ADR 0030 A1's new-work gate. It equals `has()` until M5a.
- Still open: `ExchangeRateSources` and `PriceConverters` taking `(key, class)`.
