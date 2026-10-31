# Extension and plugin model review — 2026-10-31

Principal architect, on the owner's question: why are the payment gateways separate top-level modules
(`billing/app/Modules/PaymentsStripe`, `PaymentsRazorpay`, `PaymentsCybersource`, `PaymentsNepal`) next to `Billing`,
`Services` and `Domains`, and should Mohar have a real plugin model like WHMCS or FOSSBilling?

Short answer: the contracts are already plugin-grade, but nothing around them is. The gateway folders are an
accident of history, not a decision: no ADR chose them, and the same kind of driver lives in three different places
today. The recommendation is a hybrid (option d): first-party integrations bundled in one place grouped by family,
third-party ones as Composer packages, both through one manifest, one catalogue and the registries we already have,
with a runtime enable/disable state and an admin Extensions page. The decision is drafted as
[ADR 0030](../../billing/docs/adr/0030-extension-model.md).

**Outcome (2026-10-31):** ADR 0030 is Accepted, then amended the same day by its `Amendment (#565)` after the
independent review (one disable semantic, uninstall and purge rules, the pre-1.0 kernel check, family-wide gateway
helpers, M5 split, order M3 → M1 → M2 → M4 → M5a → M6 → M7 → M5b). Owner decisions: the owner approved the hybrid, Composer-only installs, the CLI for
install, upgrade and uninstall with enable and disable in admin, one extension per Nepal provider (shared helpers to the
kernel), and a free verified list later with paid extensions out of scope for v1. The CTO decided schema-only
third-party UI in v1 and kernel 1.0.0 with the first public release. The table in ADR 0030 "Decisions of 2026-10-31"
is the record; §4 below is the recommendation as proposed and differs only on the Nepal split.

Read at `develop` a5b803bf. Nothing was run besides `grep`, `ls` and file reads; no tests, no app.

## 1. Current state

### 1.1 What already exists

**Contracts (strong).** Three driver families have kernel provider contracts (SPI) with capability interfaces, DTO
inputs and outputs, and conformance datasets:

- Gateways: `app/Contracts/Billing/GatewayDriver.php`, `HostedPaymentDriver` and seven more capability interfaces
  (ADR 0009 Amendment (#491) §1), base class `app/Support/Billing/AbstractGatewayDriver.php`.
- Provisioning: `app/Contracts/Services/Provisioning/*`, registry `app/Support/Services/ProvisioningDriverRegistry.php`.
- Registrars: `app/Contracts/Domains/Registrar/*`, registry `app/Support/Domains/RegistrarDriverRegistry.php`.
- Shared: `Data\Integrations\SettingField` + `Enums\FieldType` (one settings schema), `ConnectionCheck`,
  `ProvidesLogo`, `ValidatesSettings`, `Redactor`, `RedactionScope`, `OutboundAddressGuard`, `CertificateBundle`.
- The base `app/Support/Integrations/DriverRegistry.php:73` gives `register(key, class|Closure, replace)`, lazy
  resolution, key checks, and since #490/#495 hides a broken driver with a reason (`unavailable()`, line 112) instead
  of breaking every page that lists drivers. That is the seed of an extension health model.

**Contract evolution (strong on paper).** ADR 0010 defines the public surface, provider vs service contracts,
compatible changes, deprecation, and a kernel API version. `App\Support\Kernel::API_VERSION = '0.1.0'`
(`app/Support/Kernel.php:16`) and `kernel:api-snapshot` exist. ADR 0010 also already decided that a package declares
its kernel constraint in `composer.json` and that core refuses an incompatible one, but deferred the check to "the
wave-4 module registry" (`billing/docs/adr/0010-contract-evolution-and-versioning.md:63-66`). Nothing reads the
version today.

**UI and behaviour registries (good).** `Navigation`, `Dashboard`, `SettingsRegistry`, `PermissionRegistry`,
`GlobalSearch`, `ThemeRegistry`, `ReportRegistry` (ADR 0025), `ImportRegistry` (ADR 0019), `Automation::task()`,
`MergeFieldRegistry`, `WebhookEvents`. A package can contribute navigation, widgets, settings, permissions, reports,
importers, scheduled tasks and merge fields without editing core.

**Isolation tests (good).** `tests/Arch/ArchitectureTest.php` forbids sibling-module imports; `tests/Extensions/*`
boot subsets through `Tests\TestCase::$onlyModules` (`tests/TestCase.php:31`), and
`tests/Fixtures/Extensions/AcmePanel` is a plain service provider outside `app/Modules` that registers a provisioning
and a registrar driver with `MODULES=Core` (ADR 0009 Verification). Conformance kits ship in the kernel (ADR 0009
Amendment (#515)).

**Per-account enable (exists, per family).**

| Family        | Where staff enable it                                                                 | State                                                                                                                                                                                                                                 |
| ------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Gateway       | Admin → Billing → Gateways (`Billing/routes/web.php:74-78`)                           | `gateways.is_active` default false, encrypted `config` (`Billing/Database/Migrations/2026_10_17_300001_create_billing_tables.php:35-44`); save needs `password.recent`                                                                |
| Registrar     | Admin → Domains → Registrars (`Domains/routes/web.php:19-23`)                         | `registrar_accounts.is_active`, encrypted `settings`, `test_mode` (`Domains/Database/Migrations/2026_10_17_500001_create_domains_tables.php:51-58`); inactive until saved unless `StandaloneRegistrar` (ADR 0009 Amendment (#408) §4) |
| Provisioning  | Admin → Services → Servers (`Services/routes/web.php:19-24`), then a product's module | per server row and per product; no family-level switch                                                                                                                                                                                |
| Fraud         | `orders.fraud.driver` (`Orders/Config/orders.php:47-49`) picks `none` or `rules`      | module-internal interface                                                                                                                                                                                                             |
| Exchange rate | Platform registers the ECB and NRB sources (`Platform/Drivers/ExchangeRates`)         | `ExchangeRateSources::register(class)`                                                                                                                                                                                                |

Settlement does **not** keep running for an inactive gateway or registrar account (corrected after the #565 review; the
first version of this paragraph claimed it did from a grep of the reconciler alone). Traced:
`Billing/Services/GatewayCallbackHandler.php:99` answers a webhook with 404 "gateway not active",
`Billing/Http/Controllers/Public/GatewayReturnController.php:25` aborts the customer's return with 404, and
`Domains/Services/RegistrarGateway.php:72` refuses every call for an inactive account, reconcile included. Only the
reconciler's lookup (`Billing/Actions/SettlePaymentAttempt.php:87-96`) keeps going. A webhook for a payment completed a
minute before staff switch a gateway off is therefore refused. The rule any disable must keep, at account and
extension level alike, is "disable stops new work, never strands old work"; fixing the three sites is issue #571, ahead
of ADR 0030 M5a (Amendment (#565) A1).

**Module loading.** `app/Providers/ModulesServiceProvider.php:31-38` globs `app/Modules/*` one level deep and
registers `App\Modules\<Name>\<Name>ServiceProvider`; `config/modules.php:8` (`MODULES`) narrows the set and is
documented as a test aid. `App\Support\Modules\ModuleServiceProvider` loads config, migrations, routes, views,
commands and a schedule hook by convention. Laravel package discovery is on (`composer.json` `extra.laravel.dont-discover: []`), and the repo already has one in-repo Composer path package with an auto-discovered
provider: `tools/simulators` (`mohar/simulators`, dev only).

### 1.2 Why the gateways became top-level modules

No ADR or architecture rule decided it. The trail:

1. `billing/docs/product/payments-nepal.md` §3.1 (2026-10-17) planned Khalti and eSewa **inside** Billing
   (`Drivers/Gateways/Nepal/<Name>Driver.php`), and noted that a driver outside Billing would need the interface and
   DTOs in the kernel, a CTO decision.
2. By the build (2026-10-18) the contracts had moved to `app/Contracts/Billing`, so the builder made
   `app/Modules/PaymentsNepal` "an extension, not part of Billing" with no new decision (`payments-nepal.md` §8.1,
   lines 454-462). The module has no tables, routes or settings of its own; it only registers drivers.
3. Stripe (`payments-stripe.md`) and the 2026-10-25 gateway expansion copied the precedent. The owner's wording that
   connectIPS, Cybersource and Razorpay are "extension drivers" / an "extension/driver module"
   (`docs/handoff/requirements.md:106`, `payments-gateways-expansion.md` §9, MUST 20 at line 701) asked for
   self-contained, contract-tested drivers behind the registry. It did not ask for a top-level module each; the
   top-level folder was the only "outside Billing" home the loader knew.

Meanwhile the other two families went the other way: cPanel lives in `Modules/Services/Drivers/Provisioning/Cpanel`,
ResellerClub and Namecheap in `Modules/Domains/Drivers/Registrars/{ResellerClub,Namecheap}`, registered by the host
module's provider (`ServicesServiceProvider.php:128`, `DomainsServiceProvider.php:129-130`). Those drivers import
only kernel namespaces, so they could live anywhere; they sit in the host module by habit.

So the honest answer to the owner: splitting a provider integration out of its host module was right (it proves the
driver uses only the kernel, and a host can drop it), but making each one a sibling of the business modules mixes
two different things under one word, and doing it for gateways only is inconsistent.

### 1.3 Drift found while reading

| #   | Finding                                                                                                                                                                                                                                                                                                    | Evidence                                                                                                                                                                                    |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | ADR 0012 "Alignment of existing registries" asked for a kernel `Support\Billing\GatewayDriverRegistry`. It was not built: gateways still register **instances** on a Billing-bound interface, so every gateway module needs `callAfterResolving()`.                                                        | `app/Contracts/Billing/GatewayRegistry.php:12-27`; `Billing/BillingServiceProvider.php:130-136`; `PaymentsStripeServiceProvider.php:24-26` (same in the other three)                        |
| D2  | Four registration styles for one concept: instance (`GatewayRegistry`), `register(key, class)` (`DriverRegistry`), `register(class)` (`ExchangeRateSources.php:27`, `PriceConverters.php:23`, also an ADR 0012 alignment item), `register(ThemePreset)` (`ThemeRegistry.php:22`).                          | as cited                                                                                                                                                                                    |
| D3  | Gateway drivers read Core internals, so they could not ship as an outside package today: Stripe queries Core's `Currency` model from a driver although `Contracts\Currencies\CurrencyDirectory` exists; three clients read Billing's `billing.gateways.http_timeout_seconds` through Core `Settings`.      | `PaymentsStripe/Support/StripeAmount.php:86`; `PaymentsStripe/Support/StripeClient.php:148`; `PaymentsRazorpay/Support/RazorpayClient.php:138`; `PaymentsNepal/Support/ProviderHttp.php:74` |
| D4  | Gateway `settingsFields()` still returns arrays (known, ADR 0009 Consequences).                                                                                                                                                                                                                            | `app/Contracts/Billing/GatewayDriver.php:70-72`                                                                                                                                             |
| D5  | ADR 0009 §6 lists `Orders\FraudCheckCompleted` with `App\Enums\FraudDecision` "moved from Orders". Neither exists; the enum is still `Modules/Orders/Enums/FraudDecision.php` and `app/Events/Orders` has no such event. Fraud and inbound mail remain module-internal (ADR 0009 §7 promised an addendum). | `ls app/Enums`, `ls app/Events/Orders`                                                                                                                                                      |
| D6  | `architecture.md:273` reserves the migration block 75xxxx for PaymentsNepal, which has no migrations. Harmless, but the migration-number scheme has no rule for extensions.                                                                                                                                | `ls app/Modules/PaymentsNepal`                                                                                                                                                              |
| D7  | `tests/Arch/ArchitectureTest.php:19-22` `PLANNED_MODULES` lists `Affiliates` and `Ai` (not on disk) and omits `PaymentsCybersource` (the glob adds it). Any move must update this list in the same PR.                                                                                                     | as cited                                                                                                                                                                                    |
| D8  | ADR 0010's package compatibility rule exists only as text; `Kernel::API_VERSION` has no reader.                                                                                                                                                                                                            | `Kernel.php:16`; ADR 0010:63-66                                                                                                                                                             |

D1, D3 and D4 block "a third-party package can ship a gateway without editing core" more than any missing plugin
feature does: a package written against today's gateway shape would break at the alignment.

### 1.4 What is missing compared with a real plugin model

| Capability                        | Today                                                                                                                                                  | Gap                                                                                                                                                              |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Discovery and installation        | Laravel package discovery would load any package's provider; `app/Modules/*` glob for ours.                                                            | No manifest, no list of "extensions" distinct from libraries, no record of what is installed or which version.                                                   |
| Enable/disable at runtime         | Per account (gateway, registrar, server). `MODULES` is an env var for tests.                                                                           | No extension-level switch without a deploy; no kill switch for a misbehaving package.                                                                            |
| Per-extension migrations          | Module migrations always run with `migrate`.                                                                                                           | No install-time migrations, no upgrade-from-version hook, no rule for numbering or for foreign keys into modules.                                                |
| Versioned API compatibility       | Kernel version and snapshot exist.                                                                                                                     | Nothing checks a package's constraint; an incompatible package boots and fails at first use.                                                                     |
| Isolation and permissions         | Arch rules for our code; DTO-only driver inputs; secret-receiving contracts closed to packages (ADR 0012 Amendment (#555)); outbound guard (ADR 0015). | Arch rules do not run over a package's code; no declared network hosts; no per-extension permission boundary (PHP has none in-process).                          |
| Admin UI                          | Family pages (Gateways, Registrars, Servers).                                                                                                          | No page listing installed extensions, their version, compatibility, health or source.                                                                            |
| Uninstall and data cleanup        | None.                                                                                                                                                  | No uninstall hook, no rule for what data may be dropped (money records never).                                                                                   |
| Signing and trust                 | Composer lockfile, 7-day cooldown and approval (ADR 0007) for our dependencies.                                                                        | No stance for third-party extensions: who vouches for them, how a host tells first-party from third-party.                                                       |
| Scaffold and test kit for authors | Conformance kits (ADR 0009 Amendment (#515)); `AcmePanel` fixture.                                                                                     | No `make:extension`, no `extensions:check` that runs our arch rules and kits over a package.                                                                     |
| UI from a package                 | Schema-driven forms (`SettingField`), dashboard and navigation registries.                                                                             | One Vite bundle; the release tarball ships built assets only (`docs/devops/cpanel.md` Install step 1), so a package cannot add React pages. UI slots are wave 4. |

## 2. Research

Public documentation only, described in our own words; no code or text copied. Sources and dates are in §5.

### 2.1 WHMCS

- **Module types:** provisioning (servers), registrar, payment gateway, addon, mail provider and notification
  provider; reports and admin widgets are lighter file-based extensions. Each type lives in its own folder under
  `modules/<type>/<name>/`, with a main file named after the module and an optional `lib/` that is autoloaded under a
  module namespace (our `developer-architecture.md` §2-§6, read 2026-10-18).
- **Activation:** each type is activated from its own admin screen (gateways under the payment gateways setup, addons
  under addon modules, servers by being chosen for a server or product). Activation is per install, and for addons
  full administrators choose which admin roles may use the module.
- **Lifecycle:** an addon declares activate and deactivate functions. Activation may create tables or refuse with a
  message; deactivation is expected to undo it, typically dropping the module's tables. Upgrades are a separate
  function that receives the previously installed version.
- **Hooks:** any PHP file in a shared hooks folder (or a module's own hooks file) is loaded automatically on every
  request; renaming a file with a leading underscore disables it. Hooks register a callback for a named hook point
  with a priority; some hook points let the return value change or stop the default behaviour.
- **Trade-offs:** very low barrier (drop a folder, activate), a huge ecosystem, and a marketplace. The costs are the
  ones our contracts already avoid: loosely typed arrays in and out, modules that read and write core tables
  directly, hooks that run on every request with no declared contract, and no compatibility metadata, so upgrades of
  the core routinely break modules. Dropping a folder of PHP into a web root is also the classic way hosts get
  compromised, and it cannot work on our read-only container image.

### 2.2 FOSSBilling

- Modules live in a `modules` folder, one directory per module named after its id, each with a required
  `manifest.json` that the engine reads for the module's details; controllers add admin and client routes, API
  classes expose admin, client and guest endpoints, and Twig folders hold templates.
- Staff enable or disable modules on an Extensions page in admin. Manual install is: download the archive, unpack it
  into a folder named after the extension id, then enable it in admin.
- An extension directory lists auto-installable modules, themes, gateways, server managers, registrars, hooks and
  translations, publishing each extension's version, minimum compatible FOSSBilling version and licence; submissions
  are moderator-reviewed. We found no code signing.
- Our 2026-10-29 OSS comparison (`docs/reviews/2026-10-29-oss-comparison.md` §1, source read under Apache-2.0) adds:
  install and uninstall run through an extension manager; modules call each other through the DI container with no
  boundary test; adapters are abstract base classes that throw for unsupported operations.
- **Trade-offs:** a clear manifest and a real enable/disable lifecycle with a compatibility field; the directory gives
  hosts one place to look. Weak boundaries and runtime installation of unpacked archives into the web root.

### 2.3 Paymenter

- Built-in gateway and server "extensions"; more come from the Paymenter marketplace. A third-party extension is
  installed by placing its folder under `extensions/Gateways/` (or `Servers`, `Others`) and enabling it in admin;
  gateway accounts are then created under the payments area and bound to an extension.
- From our 2026-10-29 source read (MIT): metadata comes from a PHP attribute on the extension class; discovery scans
  the Composer classmap and folders; the base class has installed, uninstalled and upgraded-from-version hooks;
  per-extension migrations are run and rolled back by the host; artisan commands create, install, upgrade and disable
  extensions. Capabilities are boolean "supports" methods and calls are dispatched by method name.
- **Trade-offs:** the most complete lifecycle of the open-source set, Laravel-native, close to what we need. The
  contract side (call by name, models passed to drivers) is weaker than ours.

### 2.4 Blesta

- Three kinds: plugins (`/plugins/<name>/`), modules (provisioning, `/components/modules/`) and gateways
  (`/components/gateways/merchant|nonmerchant/`). Each has install, uninstall and upgrade methods.
- Upgrade runs when the code's version differs from the version Blesta recorded at install, and receives the
  installed version, so the extension migrates its own data step by step and can refuse a downgrade.
- Uninstall tells a module whether it is the last instance (only then remove shared data), and a gateway is
  uninstalled per company in multi-company installs.
- **Trade-offs:** the cleanest version-ladder model of the commercial products: the host records the installed
  version, the code carries the target version. Still folder drop-in, untyped arrays, no compatibility constraint.

### 2.5 HostBill

- Modules (hosting, gateway, registrar) and plugins are extracted into the main install directory and then
  activated in admin under the settings → modules screens, from an "inactive" list. A public developer kit shows
  procedural sample modules for each type.
- **Trade-offs:** the same drop-in-and-activate model as WHMCS; little public detail on versioning.

### 2.6 Laravel ecosystem

- **Package auto-discovery (Laravel 12 docs):** a package lists its service providers under `extra.laravel` in its
  `composer.json`; Laravel registers them on install. The application can opt out per package or for all packages
  with `dont-discover`. Packages load migrations, routes, translations and views from their provider, can register
  commands that run on `optimize`, and publish assets. Discovery is cached in `bootstrap/cache/packages.php` at
  `composer dump-autoload`.
- **Filament plugins:** a plugin is a Composer package with a small plugin class that a panel opts into; assets can be
  scoped to the panel that uses the plugin. A skeleton repository bootstraps new plugins. Enabling is in code (the
  panel provider), not at runtime.
- **Statamic addons:** a Composer package whose `composer.json` marks it as an addon (`extra.statamic`) and lists its
  provider for Laravel discovery; conventional folders are autoloaded; a scaffold command creates one; private addons
  use a Composer path repository. Installed addons appear in the control panel. The marketplace adds selling and
  editions that the code can check. We found no runtime enable/disable.
- **nWidart/laravel-modules:** modules are folders with their own `module.json`, `composer.json`, migrations, routes
  and front-end build; an "activator" stores enabled state (a JSON status file by default, a database activator as an
  option) and artisan commands enable or disable a module. It is an app-structuring tool, not a third-party
  distribution model.
- **Spatie laravel-package-tools:** a base provider where a package declares its config, migrations (published or run
  directly), routes, assets and a generated install command in one method.
- **Trade-offs:** Composer is the right install channel for a Laravel product: lockfile, versions, constraints,
  autoload and discovery come free, and it is how every serious Laravel extension ecosystem distributes. What none of
  these give is a host-facing lifecycle (installed version, enable/disable, uninstall) or a compatibility check against
  a product API; Statamic and Filament leave that to Composer constraints on the product package itself.

### 2.7 What to take

| Take                                                                                     | From                                |
| ---------------------------------------------------------------------------------------- | ----------------------------------- |
| One manifest per extension with id, version, compatibility and what it provides          | FOSSBilling, nWidart                |
| Composer as the only install channel, provider auto-discovery, path repos for local work | Laravel, Statamic                   |
| Installed-version record plus upgrade-from-version hook; refuse downgrades               | Blesta, Paymenter                   |
| Host-run per-extension migrations, linted like ours                                      | Paymenter                           |
| Admin page listing extensions with enable/disable; per-account config stays where it is  | FOSSBilling, WHMCS                  |
| Per-role access to an extension's admin pages                                            | WHMCS (we have permissions already) |
| Scaffold command that produces a passing skeleton                                        | Paymenter, Statamic, Filament       |
| A reviewed directory later, never a runtime downloader                                   | FOSSBilling                         |

What not to take: folder drop-in into the web root, hooks loaded from a shared folder on every request, arrays in and
out, drivers that touch core tables, call-by-name capabilities, deactivate-drops-tables as the default.

## 3. Options

| Option                                                                                                                                                   | DX (us) | DX (3rd party) | Host UX | Security | Upgrade safety | Testability | Performance | Migration cost (5 = cheap) | Total |
| -------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | -------------- | ------- | -------- | -------------- | ----------- | ----------- | -------------------------- | ----- |
| (a) Today, cleaned up: keep `Modules/Payments*`, fix D1-D4, move nothing else                                                                            | 3       | 2              | 2       | 4        | 2              | 4           | 5           | 5                          | 27    |
| (b) First-party drivers grouped by family, runtime enable/disable registry, admin Extensions page; no third-party lifecycle                              | 4       | 2              | 4       | 4        | 3              | 4           | 4           | 4                          | 29    |
| (c) Full plugin system: every integration a Composer package (own repo), manifest, discovery, install/upgrade/uninstall, migrations, compat check        | 2       | 5              | 4       | 3        | 4              | 3           | 4           | 1                          | 26    |
| (d) Hybrid: first-party bundled in-repo, grouped by family; third-party as Composer packages; one manifest, catalogue, lifecycle and registries for both | 4       | 4              | 4       | 4        | 4              | 5           | 4           | 3                          | 32    |

Reasoning per criterion:

- **DX (us).** (c) splits seven integrations still changing weekly across repos while the kernel is pre-1.0 and
  allowed to break (ADR 0010 "Pre-1.0 window" relies on one PR updating every implementer). That rule only works in
  one repository. (b) and (d) keep one PR per change.
- **DX (third party).** Only (c) and (d) give a package author a manifest, a compatibility check, lifecycle hooks and
  a scaffold. (d) loses a point because our own extensions are not separate packages, so we feel less of the
  author's pain; the fixture package and `extensions:check` (M7) close that.
- **Host UX.** (b), (c) and (d) all give an Extensions page. (a) leaves "is Razorpay installed?" answerable only by
  reading the Gateways list.
- **Security.** Any in-process PHP extension runs with full app privileges; none of the options sandbox it. (c) scores
  lower because it pushes toward installing many packages and invites a "download from admin" feature. (d) keeps the
  install channel to Composer at build time, which the read-only image already forces.
- **Upgrade safety.** (a) and (b) have no compatibility check, so a third-party package breaks silently at a kernel
  change. (c) and (d) refuse an incompatible extension at boot and list it with the reason.
- **Testability.** (d) runs the same arch rules and conformance kits over in-repo extensions and over a package's
  PSR-4 roots, and the existing `tests/Extensions` pattern proves each extension boots with `MODULES=Core`.
- **Performance.** All options add at most one cached manifest file read at boot and one memoised state read per
  request; (a) adds nothing.
- **Migration cost.** (a) about 4 small PRs; (b) and (d) about 8; (c) needs new repos, CI, release and versioning
  for each integration before any host value.

## 4. Recommendation: option (d), the hybrid

### 4.1 Vocabulary

- **Module:** a business domain in `app/Modules/<Name>` (Core, Catalog, Orders, Billing, Services, Domains, Support,
  Notifications, Platform, Storefront, Themes, Kyc, Migration). Always shipped. `MODULES` stays a test aid.
- **Extension:** anything that plugs into a kernel registry and talks to an outside system or adds an optional
  feature: gateway, provisioning panel, registrar, fraud checker, notification channel, mail transport, exchange-rate
  source, importer source, report pack, dashboard widget pack, theme pack, addon. Depends on the kernel only, never
  on `App\Modules\*` (Core included).
- **Built-in driver:** a fallback with no outside system (`BankTransferDriver`, `ManualDriver`, `SandboxCardDriver`,
  `NoneDriver`, `LogDriver`, `LogRegistrar`, `RulesFraudChecker`). Stays in its host module.

### 4.2 Target layout

```
billing/
  app/
    Modules/<Module>/...                    business domains, unchanged
    Extensions/                             first-party extensions, bundled
      Gateways/Stripe/
        extension.json
        StripeServiceProvider.php           extends App\Support\Extensions\ExtensionServiceProvider
        Drivers/  Support/  Config/  Console/Commands/  Database/Migrations/ (only if needed)
      Gateways/Razorpay/  Gateways/Cybersource/  Gateways/Nepal/   (Khalti, eSewa, connectIPS; split per provider by the
                                                                    owner's decision; shared helpers become
                                                                    family-wide, Amendment (#565) A4)
      Provisioning/Cpanel/
      Registrars/ResellerClub/  Registrars/Namecheap/
      ExchangeRates/Ecb/  ExchangeRates/Nrb/
    Support/Extensions/                     kernel: manifest DTO, catalogue, state, base provider, compat check
  tests/Feature/Extensions/<Family>/<Name>/  and tests/Unit/Extensions/...
  tests/Fixtures/Extensions/AcmePanel/      becomes a real Composer path package with extension.json
vendor/<vendor>/<package>/                   third-party extensions: Composer "type": "mohar-extension", extension.json at the root
```

Namespace `App\Extensions\<Family>\<Name>`. The extension id is `<family>.<name>` in lower case (`gateways.stripe`);
driver keys stay what they are (`stripe`, `khalti`, `cpanel`), so no stored data changes.

### 4.3 Manifest (`extension.json`, schema 1)

```json
{
    "schema": 1,
    "id": "gateways.stripe",
    "name": "Stripe",
    "vendor": "Mohar",
    "version": "self",
    "kernel": "^0.1",
    "provider": "App\\Extensions\\Gateways\\Stripe\\StripeServiceProvider",
    "provides": [{ "family": "gateway", "key": "stripe" }],
    "network": ["api.stripe.com", "checkout.stripe.com"],
    "migrations": false,
    "links": { "docs": "https://…", "support": "https://…" }
}
```

_Revised by Amendment (#565) A3:_ `^0.1` would fail at the first `0.2.0`; `version: "self"` extensions skip the
kernel check, and a small kernel `KernelConstraint` class replaces the `composer/semver` idea. Family names are plural
(`"family": "gateways"`, A6).

- `version` is a semver string for packages; `"self"` for first-party means "the app's version".
- `kernel` is a Composer-style constraint checked against `Kernel::API_VERSION`. This replaces ADR 0010's
  `extra.mohar.api` key, so there is one place for metadata whether the extension is in-repo or a package.
- `provides` lists every registry key the extension registers; the catalogue refuses a registration that is not
  declared, so the Extensions page never lies about what a package adds.
- `network` declares outbound hosts; shown to staff on the Extensions page and, in a later PR, enforced by
  `Support\Integrations\Endpoints`.
- Trust (first-party or third-party) is derived from where the code sits, never declared.

### 4.4 Lifecycle

States: **discovered** (code present, manifest valid) → **installed** (version recorded, migrations run) →
**enabled** / **disabled** → **upgrade pending** (code version differs from the recorded one) → **uninstalled**.
Plus two unhealthy states: **incompatible** (kernel constraint fails; the provider is never registered) and
**broken** (the provider or a driver failed to build; reuses `DriverRegistry::unavailable()`).

- Code arrives only through Composer at build or deploy time (`composer require` in a derived image, or on a VPS or
  cPanel host with a terminal). Never from the admin UI, never by uploading an archive.
- `artisan extensions:install|upgrade|uninstall|enable|disable|list` run the lifecycle. First-party extensions are
  installed and enabled by `app:install`, so a fresh install looks exactly like today.
- Install and upgrade run the extension's migrations (loaded only for installed extensions) and an optional
  `upgrade(string $fromVersion)` hook on the provider; downgrades are refused.
- Enable and disable are runtime state in Core's `extensions` table, read through a cached `ExtensionState`
  (ADR 0020). Because routes and config are cached, a disabled extension's provider still loads; the kernel hides
  what it registered: registries leave out its drivers (reported as unavailable with reason "disabled"), its routes
  answer 404 through an `extension:<id>` middleware, its automation tasks are skipped, its listeners are registered
  through a guarded helper. **Disable stops new work and never strands old work:** reconciliation, refund lookups,
  webhooks for existing attempts and provisioning reconcile keep using the driver.
  _Revised by ADR 0030 Amendment (#565) A1:_ one semantic at account and extension level; `get()` stays total for
  settlement callers, `has()`/`keys()` exclude disabled drivers, `enabled()` gates new work; the settlement callers are
  listed by name.
- Uninstall runs an optional `uninstall()` hook and rolls back the extension's own migrations, but refuses while any
  money, provisioning or domain record references its keys. Purging data is a separate, explicit, logged command.
  _Revised by Amendment (#565) A2:_ references are counted through an `ExtensionUsage` registry each module answers;
  purge touches only the extension's own tables, never money or fiscal records (ADR 0024); a gateway that took money
  ends as disabled.

### 4.5 Admin UX

Settings → Extensions (permission `platform.extensions.manage`, enable and disable behind `password.recent` and
logged to the activity log):

- one row per extension: logo, name, vendor, version, first-party or third-party badge, what it provides
  ("Gateway: Stripe"), status (Enabled, Disabled, Upgrade pending, Incompatible, Broken) with the reason;
- Enable / Disable; "Configure" links to the family page that already exists (Billing → Gateways → Stripe), so there
  is still one settings form per account;
- declared network hosts and documentation links;
- for install, upgrade and uninstall the page shows the exact command to run, because they run migrations.

### 4.6 Security stance

- Extensions are trusted code with the app's privileges. PHP has no in-process sandbox, and we do not pretend
  otherwise on the page or in the docs.
- The install channel is Composer only, which keeps the lockfile, the 7-day cooldown and approval (ADR 0007) for
  anything we bundle, and lets a host apply the same to what they add. The production image is read-only
  (`docs/devops/container-image.md`), so runtime installation is impossible by design.
- Contracts limit the blast radius: DTOs not models (ADR 0009), host-side redaction (ADR 0023), outbound guard
  (ADR 0015), secret-receiving contracts closed to packages (ADR 0012 Amendment (#555)), declared network hosts.
- `extensions:check <package>` runs our arch rules and the family conformance kit over a package's PSR-4 roots;
  hosts and reviewers can run it before adding a package.
- No signing in v1. A reviewed "verified" list (publisher, versions checked, kit results) is an owner decision for
  later, never a runtime download.
- Third-party extensions contribute UI through schemas only (settings fields, dashboard widgets, navigation, reports,
  later UI slots), so no third-party JavaScript runs in the admin.

### 4.7 How the ADRs change

- **ADR 0001:** adds the module / extension / built-in driver distinction and `app/Extensions`; an extension depends
  on the kernel only.
- **ADR 0009:** first-party provider drivers live in `app/Extensions/<Family>/<Name>` and register from their own
  provider; drivers read no Core internals (D3).
- **ADR 0010:** the manifest's `kernel` key replaces `extra.mohar.api`; the compatibility check is implemented by the
  extension catalogue; the first third-party package listed as supported triggers kernel `1.0.0`.
- **ADR 0012:** every driver registry records the owning extension and hides disabled ones with a reason; the
  gateway, exchange-rate and price-converter alignment is a prerequisite, not a wish.

## 5. Sources

Read 2026-10-31 unless noted. Paraphrased; nothing copied.

- WHMCS: https://developers.whmcs.com/modules/ , https://developers.whmcs.com/addon-modules/ ,
  https://developers.whmcs.com/addon-modules/installation-uninstallation/ , https://developers.whmcs.com/hooks/ ,
  https://developers.whmcs.com/hooks/getting-started/ ; module folders, gateway and addon details from
  `billing/docs/parity/developer-architecture.md` §2-§6 and Appendix A (read 2026-10-18).
- FOSSBilling: https://github.com/FOSSBilling/example-module , https://github.com/FOSSBilling/extension-directory ;
  source read in `docs/reviews/2026-10-29-oss-comparison.md` (2026-10-29).
- Paymenter: https://paymenter.org/docs/guides/gateways/ , marketplace listings
  https://paymenter.org/marketplace/63007-btcpay-paymenter-gateway ,
  https://paymenter.org/marketplace/92274-tebex-for-paymenter (search results only); source read in the 2026-10-29
  OSS comparison.
- Blesta: https://docs.blesta.com/developers/gateways/overview and the plugin and module pages linked from it (search
  results only, versions vary).
- HostBill: https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/491850/Configure+Provisioning+Modules ,
  https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/491908/Configure+Apps , https://github.com/timh5/procedural_devkit
  (search results only).
- Laravel: https://laravel.com/docs/12.x/packages (fetched).
- Filament: https://filamentphp.com/docs/4.x/plugins/getting-started (fetched).
- Statamic: https://statamic.dev/extending/addons (fetched).
- nWidart/laravel-modules: https://laravelmodules.com/docs/12/getting-started/introduction (fetched; the
  configuration page returned an error, so the activator details are from the package's general documentation and are
  not re-verified today).
- Spatie: https://github.com/spatie/laravel-package-tools (fetched).

Not verified today: FOSSBilling's manifest field list (no official page found), whether Statamic or Filament support
runtime disable, HostBill's folder layout.
