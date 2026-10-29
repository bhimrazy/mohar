# ADR 0010 — Kernel contract evolution, versioning and deprecation

- Status: Accepted · 2026-10-18 (CTO) · Owner: Principal architect

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

## Amendment (#482) — 2026-10-25, defaulted parameters on serialised events

- A trailing defaulted constructor parameter is a compatible addition for queued and stored copies of an event too.
  `IdentifiesOccurrence::__unserialize` gives every promoted constructor parameter missing from the payload its
  default, as a new event would get it; without that, a copy serialised before the parameter existed would leave the
  typed property uninitialised. First used by `CreditNoteIssued::$notify` and `DebitNoteIssued::$notify`; it also
  covers `InvoiceCreated::$notify`.

## Amendment (#513) — 2026-10-29, the snapshot, the version rule and deprecated array keys

### The snapshot

- `php artisan kernel:api-snapshot` (Core) writes `docs/architecture/kernel-api.txt`. Lines are sorted, so a diff
  names exactly what changed. Each line is one of these:
    - a class line: its kind, `final`/`abstract`/`readonly`, parent, interfaces, traits, and the `@open` and
      `@deprecated` markers;
    - a constant: visibility, `final`, type and value;
    - an enum case and its value;
    - a property: modifiers, type and default (a promoted property's default is on the constructor line);
    - a method: modifiers, and parameter names, types and defaults, including `new X(...)` defaults with their
      arguments as written in the source;
    - a `doc` line under its member, for each `@param`, `@return`, `@var`, `@throws`, `@template`, `@extends` and
      `@implements` type (array shapes, generics, closures). These are read as text with no parser, keep class
      names as the file imports them, and are left out when they say no more than the native type.
- Every kind of member carries `@deprecated` when its docblock does. Members are listed on the class that declares
  them. Private members never appear, and protected ones appear only on classes that can be extended. A member whose
  docblock carries `@internal` is left out, for example `ReportExporter::__construct`, which takes Core services and
  is resolved from the container.
- Scope:
    - every class in `app/Contracts`, `app/Data`, `app/Events` and `app/Enums`;
    - each `app/Support` class whose class docblock carries `@api`. That makes "internal unless `@api`" mechanical.
      These now carry it: `Money`, `Kernel`, the registries (including `Dashboard`, `Navigation` and `GlobalSearch`),
      their payload classes (`NavItem`, `SettingDefinition`, `ThemePreset`), `Queues`, `Automation`,
      `AbstractGatewayDriver`, `IdentifiesOccurrence`, `InvoiceNotCancellable`, and the exceptions drivers throw.
- The scope is closed. Every `App\` type a native signature names must be in the snapshot itself or under
  `App\Models`, and no `App\Modules\` type may appear. The snapshot test enforces both, so a kernel signature can
  neither leak a module class nor depend on an untagged support class.
- Left out: attributes (none is declared in the kernel yet; they join with the first one), webhook payloads and
  merge-field keys. Those stay under review and the kernel changelog.
- CI runs `kernel:api-snapshot --check` through a Pest test (`tests/Feature/Core/KernelApiSnapshotTest.php`), so the
  SQLite shards and `scripts/merge-gate.sh` both run it, and a failure prints how to fix it. It reflects over the
  kernel folders only (well under a second) and never parses the whole app.
- `scripts/changelog-check.sh` checks two things, using only git, so it also runs on the docs-only path that skips
  Pest. It fails a PR that changes `kernel-api.txt` without adding or editing a `kernel-changelog.d/` fragment. It
  also fails one that changes `kernel-api.txt` while no `billing/app/` file changes, because a snapshot edited by hand
  would otherwise fail the next kernel PR instead of this one.

### The version

- `App\Support\Kernel::API_VERSION` is `0.1.0`. The snapshot prints it on its second line.
- Only the release PR into `main` bumps it, after the kernel changelog is assembled. It regenerates the snapshot in
  the same PR, and the bump reaches `develop` through the back-merge. The assemble commit cannot carry the bump,
  because `changelog-check.sh` lets it edit only the two changelogs. A feature PR never bumps it: parallel kernel PRs
  would all conflict on one constant (ADR 0027).
- The bump rule:
    - Before 1.0.0, a release with kernel entries bumps the minor (`0.2.0`), breaking ones included under the
      pre-1.0 window. A release without kernel entries keeps the version.
    - From 1.0.0, a release bumps the major when any entry is marked breaking, otherwise the minor when it has
      entries. A breaking fragment starts its first bullet with `**Breaking.**`.
    - The patch number stays 0. A fix that changes no signature and no documented semantics needs no kernel entry.
- `1.0.0` still comes with the first external package or the first tagged release.

### Deprecating a key in a documented array shape

- Add the replacement key next to the old one. That is compatible. The method's docblock then says
  "`<key>` is deprecated since `<version>`, removed in `<next major>`; read `<replacement>`", and the kernel changelog
  records it. PHPDoc has no per-key tag, so that sentence is the marker. The shape is in the method's `doc` line,
  so adding or removing a key shows in the snapshot diff.
- Implementers return both keys for the whole deprecation window (one minor release and 90 days, whichever is
  longer). No runtime notice is possible, because nobody can tell when a caller reads an array key. So the removing
  PR has to show that nothing in the repository still reads the key, and it updates fakes and tests.
- A new shape that crosses a service contract should be a DTO. A documented array is for data that goes straight
  into a page prop or a payload.
- Before 1.0.0 the pre-1.0 window applies: `ProvisioningDrivers::all()` drops `config_fields` in #513 (issue #68)
  with no window.

### Long constructors

- Callers may use named arguments, so every parameter name on a kernel method or constructor is API. Renaming one
  is breaking, and the snapshot records the names.
- A kernel DTO or event with more than five constructor parameters gains new ones only as trailing defaulted
  parameters, and callers pass those by name. Docs and new in-repo callers build such a class with named arguments.
  Splitting `DriverMetadata` or `ProvisioningContext` into option objects, or moving the WHM-only
  `ServerConnection::$accessHash` out, is a breaking change and stays open in issue #83.
