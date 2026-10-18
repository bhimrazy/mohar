# ADR 0010 — Kernel contract evolution, versioning and deprecation

- Status: Proposed · 2026-10-18 · Owner: Principal architect

## Context

ADR 0008 set two rules for one round of changes: new interfaces get Core null defaults, and DTOs gain only trailing
defaulted fields. There is still no general rule for:

- which symbols are public;
- what counts as a breaking change;
- how a symbol is deprecated;
- how an outside package declares which kernel it works with. Wave 4 "safe upgrades" needs this.

Recent PRs show the gap:

- `PaymentAttemptRef::$interactive`, `RedirectInstruction::$expiresAt` (#26) and the three new `Brand` fields (#28)
  followed ADR 0008 by habit, not by rule.
- `Dashboard::widget()` has grown to nine positional parameters (#41).
- Webhooks (#28) send merge-field resolver output to external systems, which turns merge-field keys into a public
  API by accident.

## Decision

### Public surface

The following are public API and fall under these rules:

- everything in `app/Contracts`, `app/Data`, `app/Events` and `app/Enums`;
- the registration and resolve methods of registries in `app/Support`, plus the payload shapes they document (dashboard
  widgets, navigation items, settings definitions);
- `App\Support\Money`;
- webhook event type names and webhook payloads;
- merge-field keys used by shipped email templates.

Other `app/Support` classes are internal unless their docblock carries `@api`. Module code is never public.

### Two kinds of contract

- **Service contracts** are implemented by one module and consumed by many: `Invoicer`, `PriceBook`,
  `CurrencyDirectory`. Adding a method is compatible for consumers. It breaks only fakes, which the adding PR updates.
- **Provider contracts (SPI)** are implemented by drivers, possibly from third parties: `GatewayDriver`,
  `ProvisioningDriver`, `RegistrarDriver`, `ExchangeRateSource`, `PriceConverter`. Never add a method to one. Add a
  capability interface instead (ADR 0012), or a default in the matching abstract base class
  (`AbstractGatewayDriver`). Each provider contract's docblock says it is a provider contract.

### Compatible changes (minor version)

- A new interface, capability interface, event, enum, DTO or registry method.
- A trailing constructor parameter with a default, on a DTO or an event. Parameter names are part of the contract:
  callers may use named arguments, so renaming a parameter is breaking and reordering parameters is forbidden.
- A new key in a documented array shape when consumers must ignore unknown keys (dashboard payloads, webhook
  payloads).
- A new enum case only on enums documented as open (`@open`, for example `LookupResult` statuses or `FraudDecision`).
  Consumers of open enums need a default branch. Closed enums (`BillingCycle`) add cases only in a major version.
- Widening a parameter type or narrowing a return type.

Anything else is breaking: removing or renaming, changing a type, changing documented semantics, or making an
optional parameter required.

### Versioning

- One kernel API version, `App\Support\Kernel::API_VERSION` (semver, starting at `1.0.0` when the first external
  package or the first tagged release ships). Breaking changes bump the major version, compatible ones the minor.
- An extension package declares `"extra": {"mohar": {"api": "^1.0"}}` in its `composer.json`. Core refuses to boot an
  incompatible package with a clear message. That check belongs to the wave-4 module registry; until then the rule is
  documentation.
- Webhook envelopes carry `"api_version"`, and webhook types and payloads follow the same compatibility rules. Webhook
  payloads come from per-event webhook resources owned by the emitting module, not from merge-field resolvers, so that
  editing an email template's data cannot change an external API.

### Deprecation

- `@deprecated since <version>, removed in <next major>; use X`, plus an entry in
  `docs/architecture/kernel-changelog.md`.
- A deprecated symbol keeps working for at least one minor release and at least 90 days, whichever is longer.
- Deprecated registration paths call `trigger_error(..., E_USER_DEPRECATED)` once per process, so the
  `LOG_DEPRECATIONS_CHANNEL` shows extension authors what to fix.
- A superseded event is dispatched alongside its replacement during the deprecation window (for example
  `ProvisioningFailed` next to `ModuleCommandFailed`, ADR 0009).

### Pre-1.0 window

Until `API_VERSION` reaches 1.0.0, a kernel change may be breaking if all of these hold:

- the same PR updates every in-repo implementer and fake;
- the PR gets the principal-architect gate;
- the change is listed in the kernel changelog.

No aliases are needed. ADR 0009's moves rely on this.

### Enforcement

- The `kernel:api-snapshot` artisan command (Core, no dependencies) writes the public signatures (classes, methods,
  parameter names and types, constants, enum cases) to `docs/architecture/kernel-api.txt`. CI regenerates the file and
  fails if it differs. Every contract change therefore shows up as a reviewed diff, and the architect gate is easy to
  trigger.
- New positional parameters on registry methods past the third must be passed by name. Docs show named arguments.

## Consequences

- Driver authors get a stable SPI. Contract growth happens through capability interfaces, not breaking changes.
- One more generated file to keep in sync. The snapshot command keeps that mechanical.
- Merge-field resolvers and webhook resources become separate. That is extra code, but a template edit can no longer
  break an integration.
