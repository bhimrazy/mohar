# ADR 0030 — Extension model: bundled and packaged extensions, one manifest, runtime enable

- Status: Accepted · 2026-10-31 (owner decisions on Q1-Q3, Q5 and Q6; CTO calls on Q4 and Q7; see "Decisions of
  2026-10-31") · Owner: Principal architect. Proposed 2026-10-31 in #565. The number is fixed at 0030; the "Core public
  surface" ADR proposed by the best-practices audit (#569) takes 0031 (see "Relationship with ADR 0031").
- Review and research: `docs/reviews/2026-10-31-extension-model.md` (current state with evidence, WHMCS, FOSSBilling,
  Paymenter, Blesta, HostBill and Laravel-ecosystem models, option scoring).
- Amends: ADR 0001 (modules), 0009 (driver placement), 0010 (package compatibility), 0012 (registries).
- Related: ADR 0007 (dependencies), 0015 (outbound connections), 0020 (hot-path reads), 0023 (redaction),
  `parity/roadmap.md` wave 4 "Safe upgrades" and "Extension depth", `parity/developer-architecture.md` §12.3 item 5.

## Context

The kernel contracts for gateways, provisioning and registrars are already shaped for third parties: provider
interfaces with capability interfaces, DTO inputs, kernel registries, conformance kits, and tests that boot a fixture
package with `MODULES=Core`. What is missing is everything around them:

- no notion of an "extension" distinct from a business module;
- no manifest, no record of what is installed or at which version, no compatibility check (ADR 0010 deferred it);
- no runtime enable or disable, no install, upgrade or uninstall hooks, no per-extension migrations;
- no admin page listing extensions.

Provider integrations also live in three places with no rule behind any of them:

- gateways are top-level modules (`Modules/PaymentsStripe`, `PaymentsRazorpay`, `PaymentsCybersource`, `PaymentsNepal`);
- cPanel sits inside `Modules/Services/Drivers`;
- ResellerClub and Namecheap sit inside `Modules/Domains/Drivers`.

The gateway folders followed `payments-nepal.md` §8.1, a packaging choice made at build time. Gateways also still use
the pre-ADR 0012 registration (instances on a Billing-bound interface) and read Core internals (review §1.3, D1-D4).

Constraints that shape the answer:

- the production image is read-only and built from the lockfile;
- the cPanel tarball ships built front-end assets only;
- PHP has no in-process sandbox;
- before kernel 1.0 a breaking change must update every implementer in one PR (ADR 0010).

## Decision

### 1. Vocabulary

- A **module** is a business domain in `app/Modules/<Name>`. Modules are always shipped. `MODULES` stays a test aid.
- An **extension** plugs into kernel registries and either talks to an outside system or adds an optional feature.
  Families:
    - gateway, provisioning, registrar;
    - fraud, notification channel, mail transport;
    - exchange-rate source, importer source;
    - report pack, widget pack, theme pack;
    - addon.

    An extension depends only on `App\Contracts|Data|Events|Enums|Support` and its own namespace. It never depends on
    `App\Modules\*`, Core included.

- A **built-in driver** is a fallback with no outside system, such as `BankTransferDriver`, `ManualDriver`,
  `NoneDriver`, `LogDriver`, `LogRegistrar` or `RulesFraudChecker`. A built-in driver stays in its host module.

### 2. Layout and discovery

- First-party extensions are bundled in `app/Extensions/<Family>/<Name>/`, namespace `App\Extensions\<Family>\<Name>`.
  Each has an `extension.json` and a `<Name>ServiceProvider` extending `App\Support\Extensions\ExtensionServiceProvider`.
  That class starts as the `ModuleServiceProvider` conventions: config, migrations, routes, views, commands, schedule.
- Third-party extensions are Composer packages with `"type": "mohar-extension"` and an `extension.json` at the package
  root. Mohar does not use `extra.laravel.providers` for them: the catalogue registers the provider, so an incompatible
  package never boots. Packages that use Laravel discovery anyway are ignored; `extensions:check` reports them.
- `App\Support\Extensions\ExtensionCatalog` reads both sources. It is built by `extensions:cache`, which is registered
  with Laravel's `optimizes()` so `optimize` writes `bootstrap/cache/extensions.php`. Requests read only that file.
- One extension per provider. The Nepal wallets become `Gateways/Esewa`, `Gateways/Khalti` and `Gateways/Connectips`;
  the helpers they share today (`ProviderHttp`, `NprAmount`, `ProviderException` in `Modules/PaymentsNepal/Support`)
  move to the kernel as `App\Support\Billing\Nepal\*`, marked `@api`, so a later Nepal gateway (Fonepay) or a package
  uses the same code (migration PR M1b).
- The extension id is `<family>.<name>` (`gateways.stripe`, matching `[a-z0-9_.]{3,60}`). Driver keys are unchanged
  (`stripe`, `khalti`, `cpanel`, `resellerclub`), so no stored data moves.

### 3. Manifest (`extension.json`, schema 1)

| Key          | Required | Meaning                                                                                                                                                   |
| ------------ | -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `schema`     | yes      | `1`                                                                                                                                                       |
| `id`         | yes      | `<family>.<name>`, unique                                                                                                                                 |
| `name`       | yes      | display name                                                                                                                                              |
| `vendor`     | yes      | display vendor                                                                                                                                            |
| `version`    | yes      | semver for packages; `"self"` for first-party (the app's version)                                                                                         |
| `kernel`     | yes      | constraint on `App\Support\Kernel::API_VERSION` (`^`, `~`, ranges)                                                                                        |
| `provider`   | yes      | the service provider class                                                                                                                                |
| `provides`   | yes      | list of `{family, key}`; a registration not declared here is refused                                                                                      |
| `network`    | no       | outbound hosts the extension calls; shown to staff, enforced later through `Support\Integrations\Endpoints`                                               |
| `migrations` | no       | relative path, or `false`                                                                                                                                 |
| `requires`   | no       | module names the extension needs at runtime (for example an addon that reads `Contracts\Billing\Invoicer`); missing ones make it "unavailable", not fatal |
| `links`      | no       | `docs`, `support`, `homepage`                                                                                                                             |

Trust (first-party or third-party) is derived from where the code was found, never declared. The manifest is parsed
into a final `App\Data\Extensions\ExtensionManifest`. Unknown keys are ignored, so later schema versions stay
compatible.

### 4. Lifecycle

States and transitions:

```
discovered ──install──▶ installed+enabled ⇄ disabled
     │                        │ code version ≠ recorded ▶ upgrade pending ──upgrade──▶ installed
     │                        └──uninstall──▶ discovered
     ├─ kernel constraint fails ▶ incompatible   (provider never registered)
     └─ provider or driver fails ▶ broken        (DriverRegistry::unavailable(), reason kept)
```

- **Code arrives only through Composer**, at build or deploy time. There is no upload, no download from admin and no
  runtime write to code paths. A Docker host derives an image; a VPS or cPanel host with a terminal runs
  `composer require` and then `extensions:install`.
- **Commands:** `artisan extensions:list|install|upgrade|uninstall|enable|disable|check`. `app:install` installs and
  enables every bundled extension, so a fresh install behaves exactly as today.
- **State:** Core table `extensions`, with columns `id`, `installed_version`, `enabled`, `installed_at`, `updated_at`
  and `last_error`. It is read through `App\Contracts\Extensions\ExtensionState`, which is cached and memoised per
  request (ADR 0020). A toggle bumps the cache version.
- **Migrations:** an extension's migrations load only while it is installed. `deploy:check-migrations` lints them like
  ours, and the expand-then-contract rule applies. Foreign keys may point at Core tables only; module records are
  referenced by id, because a module may be absent. File names are `<date>_<6 digits>_ext_<name>_...`, with no module
  number block.
- **Hooks:** optional capability interfaces on the provider (ADR 0012 style):
    - `InstallsExtension::install()`;
    - `UpgradesExtension::upgrade(string $fromVersion)`, called after the migrations; a downgrade is refused;
    - `UninstallsExtension::uninstall()`.
- **Disable stops new work and never strands old work.** Routes and config are cached, so a disabled extension's
  provider still loads. The kernel hides what the extension registered:
    - driver registries record the owning extension and leave its drivers out of `keys()`/`all()`, listing them in
      `unavailable()` with reason "disabled";
    - its routes sit behind an `extension:<id>` middleware that answers 404;
    - `Automation` skips its tasks;
    - its listeners are registered through `ExtensionServiceProvider::listen()`, which checks the state.

    Settlement paths stay open while an extension is disabled: payment reconciliation, refund lookups, webhooks for
    existing attempts, provisioning and registrar reconcile (ADR 0026). They resolve the driver through an
    `includeDisabled` path that only those callers use.

- **Uninstall** first requires the extension to be disabled. It refuses while any money, service or domain record
  references one of its driver keys. Otherwise it runs `uninstall()` and rolls back the extension's migrations.
  Deleting other data is never automatic: a separate `extensions:purge <id>` with confirmation and an activity-log
  entry does it.
- **Events** (ADR 0011, after commit, ids and scalars, occurrence id): `Events\Extensions\ExtensionInstalled`,
  `ExtensionUpgraded`, `ExtensionEnabled`, `ExtensionDisabled`, `ExtensionUninstalled`, each with `extensionId`,
  `version` and `?actorId`.

### 5. Admin

Settings → Extensions, behind permission `platform.extensions.manage`.

- **Columns:** logo, name, vendor, version, a first- or third-party badge, what the extension provides, status and
  reason, network hosts, links.
- **Enable / Disable:** behind `password.recent`, and logged.
- **Configure:** links to the existing family page (Billing → Gateways, Domains → Registrars, Services → Servers).
  Account settings stay there, with one form per account.
- **Install, upgrade, uninstall:** the page shows the command to run. These run migrations, so they stay out of web
  requests in v1.

### 6. Security

- Extensions are trusted code with the app's privileges, and the docs say so plainly. Their blast radius is bounded
  by the contracts:
    - DTOs, never models (ADR 0009);
    - host-side redaction (ADR 0023);
    - the outbound guard (ADR 0015);
    - secret-receiving contracts closed to packages (ADR 0012 Amendment (#555));
    - declared network hosts.
- Composer is the only channel, so the lockfile, checksums and the host's own review apply. ADR 0007 governs what
  Mohar bundles.
- `extensions:check <path|package>` runs the extension arch rules and the family conformance kits (ADR 0009 Amendment
  (#515)) over the package's PSR-4 roots.
- Third-party extensions contribute UI through schemas only: `SettingField`, Dashboard, Navigation, Reports, and UI
  slots later. No third-party JavaScript runs in the admin or the client area.
- No signing in v1. A free, reviewed "verified" list comes later (owner, 2026-10-31); it is a list of packages and
  checked versions, never a runtime download. Paid extensions (licences, editions) are out of scope for v1.

### 7. Changes to other ADRs (applied when each migration PR lands, as `## Amendment (#<pr>)`)

- **0001:** the module, extension and built-in driver vocabulary; `app/Extensions`; extensions depend on the kernel
  only.
- **0009:** first-party provider drivers live in `app/Extensions/<Family>/<Name>` and register from their own provider.
  Drivers read no Core internals: currency exponents come through `CurrencyDirectory`, and the gateway HTTP timeout
  through `GatewayConfig`.
- **0010:** the manifest's `kernel` key replaces `"extra": {"mohar": {"api": …}}`, and the catalogue enforces it. Kernel
  `1.0.0` ships with the first public release (CTO, 2026-10-31). Until then the kernel stays `0.x`: a breaking change
  follows the ADR 0010 pre-1.0 window and needs a kernel changelog fragment and a minor bump at the next release. The constraint matcher is either a small
  kernel class or `composer/semver` promoted from dev to production; that promotion needs ADR 0007 approval.
- **0012:** registries record the owning extension and hide disabled ones with a reason. Aligning the gateway,
  exchange-rate and price-converter registries becomes a prerequisite (migration PR M3).

## Migration plan (small PRs; moves first, behaviour unchanged)

| PR  | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Behaviour change                                 |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| M1  | Loader discovers `app/Extensions/*/*`; empty `ExtensionServiceProvider`; move `Modules/Payments{Stripe,Razorpay,Cybersource}` to `Extensions/Gateways/{Stripe,Razorpay,Cybersource}` with their tests; every arch scan and `scripts/test.sh` mapping over `app/Modules` also covers `app/Extensions`; config keys (`payments_stripe` …) unchanged; arch rules for `App\Extensions` (no sibling-extension or module imports; the four Core imports in review D3 are a listed baseline that M3 deletes); `PLANNED_MODULES` fixed; `MODULES` also accepts `Gateways/Stripe`; docs updated. | none                                             |
| M1b | Kernel and move (CTO session or called out in the PR body): `ProviderHttp`, `NprAmount`, `ProviderException` to `App\Support\Billing\Nepal` (`@api`, kernel fragment and snapshot); `Modules/PaymentsNepal` split into `Extensions/Gateways/{Esewa,Khalti,Connectips}`, each with its own provider; config keys unchanged (the shared `http` block moves to `config/payments_nepal.php`, and each extension merges only its own provider subtree into `payments_nepal`).                                                                                                                | none                                             |
| M2  | Move cPanel to `Extensions/Provisioning/Cpanel`, ResellerClub and Namecheap to `Extensions/Registrars/*`, ECB and NRB to `Extensions/ExchangeRates/*`; registration moves from the host module's provider to the extension's; `tests/Extensions` subsets updated.                                                                                                                                                                                                                                                                                                                       | none                                             |
| M3  | Kernel: `Support\Billing\GatewayDriverRegistry` (ADR 0012), `GatewayRegistry::register(instance)` kept as a deprecated adapter; gateways register `(key, class)`; `settingsFields()` → `list<SettingField>`; `ExchangeRateSources`/`PriceConverters` take `(key, class)`; D3 removed (CurrencyDirectory, timeout via `GatewayConfig`), Core baseline deleted from the arch rule. Kernel fragment and snapshot.                                                                                                                                                                          | none for users                                   |
| M4  | Kernel: `extension.json` schema 1, `ExtensionManifest`, `ExtensionCatalog`, `extensions:cache` via `optimizes()`, `extensions:list`, kernel constraint check (incompatible = not booted, listed); manifests for every bundled extension. Composer `mohar-extension` discovery.                                                                                                                                                                                                                                                                                                          | none                                             |
| M5  | Core `extensions` table, `ExtensionState`, `extensions:install`, `upgrade`, `uninstall`, `enable` and `disable`, per-extension migrations, lifecycle hooks and events; registries record owners and hide disabled; `extension:<id>` middleware; guarded listeners; `app:install` installs bundled extensions; settlement paths keep disabled drivers.                                                                                                                                                                                                                                   | new commands only; everything enabled by default |
| M6  | Admin Settings → Extensions page, permission, step-up, activity log; health card links.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | new page                                         |
| M7  | Author DX: `make:extension <family> <name>` skeleton that passes its kit; `extensions:check`; `tests/Fixtures/Extensions/AcmePanel` becomes a Composer path package with `extension.json`, proving discover → install → enable → disable → uninstall with `MODULES=Core`; docs-site "Write an extension" page.                                                                                                                                                                                                                                                                          | none                                             |
| M8+ | Later, each with its own decision: fraud and notification-channel families into the kernel (ADR 0009 §7, review D5), declared-host enforcement, UI slots, verified list.                                                                                                                                                                                                                                                                                                                                                                                                                | per PR                                           |

M1 and M2 are pure moves (`git mv` plus namespace edits). They are reviewed with `git diff -M` and run when no
payments or driver PR is open, to avoid rebase pain. M3 can run in parallel with M2. M1b follows M1. M4 depends on M1 and M1b. M5 depends on
M3 and M4. M6 depends on M5. M7 can start after M4.

## Risks

- **Rename churn.** M1 and M2 touch about 100 files and every open branch that edits them. Mitigation: one family per
  PR, scheduled in a quiet window, with rename detection in review and no edits mixed into a move.
- **Cached routes and config versus runtime disable.** If disable is implemented by skipping providers, a cached route
  or config will still point at the extension. Mitigation: gate in registries, middleware and helpers, never by
  skipping a provider (§4).
- **Stranded money or provisioning.** Disabling an extension could cut off payments or commands that are in flight.
  Mitigation: the "never strands old work" rule, with a test per family that disables the extension mid-attempt and
  then settles the attempt.
- **Destructive uninstall.** Mitigation: uninstall refuses while records reference the extension, and purging is
  separate and explicit.
- **False sense of safety.** Hosts may assume that a third-party extension is sandboxed. Mitigation: plain wording on
  the page and in the docs, `extensions:check`, and the schema-only UI rule.
- **Two discovery sources** (in-repo and Composer). Mitigation: one manifest, one catalogue, and the M7 fixture package
  proving the Composer path end to end.
- **Freezing the kernel too early.** Kernel 1.0 freezes the provider contracts. Mitigation: 1.0 waits for the first
  public release, and no third-party support is advertised before M3 has aligned the gateway family.
- **Silent loss of arch coverage.** Several arch scans glob `app/Modules` (`ArchitectureTest.php:130,156`,
  `CompanyClockScanTest.php:54`, `ExtensionsTest.php:54`, `LogRedactionTest.php:120`, `WebhookEventsScanTest.php:18`);
  code moved to `app/Extensions` would pass them by not being scanned. Mitigation: M1 extends each scan and adds a guard
  that the scanned file count includes `app/Extensions`.
- **Boot cost.** Mitigation: the catalogue is read from a cached PHP file, and the state is one cached read memoised
  per request. M5 records its cost against the `sizing.md` load-test profile.

## Decisions of 2026-10-31

| #   | Question                                            | Decision                                                                                                                            | By    |
| --- | --------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ----- |
| Q1  | Hybrid (option d) or one repository per integration | Hybrid approved: bundled first-party extensions in `app/Extensions/<Family>/<Name>`, third-party ones as Composer packages.         | Owner |
| Q2  | Install channel                                     | Composer only. No zip upload and no download from admin.                                                                            | Owner |
| Q3  | Where the lifecycle runs in v1                      | Install, upgrade and uninstall from the CLI; enable and disable on the admin Extensions page.                                       | Owner |
| Q4  | Third-party UI                                      | Schema-only in v1 (settings fields, dashboard, navigation, reports; UI slots later). No third-party JavaScript.                     | CTO   |
| Q5  | Nepal gateways                                      | One extension per provider: eSewa, Khalti and connectIPS. Shared helpers move to the kernel (`App\Support\Billing\Nepal`, M1b).     | Owner |
| Q6  | Verified list, paid extensions                      | A free verified list later. Paid extensions are out of scope for v1.                                                                | Owner |
| Q7  | Kernel 1.0.0 trigger                                | With the first public release. Until then `0.x`: breaking changes take a kernel changelog fragment and a version bump per ADR 0010. | CTO   |

## Relationship with ADR 0031 (Core public surface, #569)

The best-practices audit (#569) found modules import 47 Core classes and proposes an ADR on Core's public surface. It
takes number 0031 and aligns with this boundary:

- **Extensions import no `App\Modules\*` at all, Core included.** ADR 0031 governs what _modules_ may use from Core; it
  does not widen what extensions may use.
- **When an extension needs a Core service** (settings, currencies, the activity log), the answer is a kernel contract
  in `app/Contracts` that Core implements, as `CurrencyDirectory` already is, not a Core class on an allow-list.
- **One list:** a Core class that ADR 0031 makes public for modules and that extensions also need becomes a kernel
  contract in the same PR, so the two ADRs never keep two allow-lists.
- **Ordering:** M3 (which removes the gateway drivers' Core imports, review D3) should use the contracts ADR 0031
  introduces where they exist, and add only the ones it needs where they do not.

## Consequences

- One answer to "where does an integration go": `app/Extensions/<Family>/<Name>` for ours, or a Composer package for
  anyone else. Both go through one manifest, one lifecycle and the registries we already have.
- Hosts get an Extensions page and a kill switch without a deploy. Adding code still needs a deploy, by design.
- Third parties get a compatibility check, lifecycle hooks, migrations, a scaffold and a check command. They do not get
  a sandbox, and the docs say so.
- Business modules stay as they are, and `MODULES` keeps its test role.
