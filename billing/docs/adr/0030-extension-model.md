# ADR 0030 — Extension model: bundled and packaged extensions, one manifest, runtime enable

- Status: Proposed · 2026-10-31 · Owner: Principal architect. The number may change if a sibling ADR merges first.
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
- No signing in v1. A reviewed "verified" list is a later owner decision; it will never be a runtime download.

### 7. Changes to other ADRs (applied when each migration PR lands, as `## Amendment (#<pr>)`)

- **0001:** the module, extension and built-in driver vocabulary; `app/Extensions`; extensions depend on the kernel
  only.
- **0009:** first-party provider drivers live in `app/Extensions/<Family>/<Name>` and register from their own provider.
  Drivers read no Core internals: currency exponents come through `CurrencyDirectory`, and the gateway HTTP timeout
  through `GatewayConfig`.
- **0010:** the manifest's `kernel` key replaces `"extra": {"mohar": {"api": …}}`, and the catalogue enforces it. The
  first third-party package we document as supported triggers kernel `1.0.0`. The constraint matcher is either a small
  kernel class or `composer/semver` promoted from dev to production; that promotion needs ADR 0007 approval.
- **0012:** registries record the owning extension and hide disabled ones with a reason. Aligning the gateway,
  exchange-rate and price-converter registries becomes a prerequisite (migration PR M3).

## Migration plan (small PRs; moves first, behaviour unchanged)

| PR  | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Behaviour change |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- |
| M1  | Loader discovers `app/Extensions/*/*`; empty `ExtensionServiceProvider`; move `Modules/Payments{Stripe,Razorpay,Cybersource,Nepal}` to `Extensions/Gateways/{Stripe,Razorpay,Cybersource,Nepal}` (about 73 PHP files incl. tests); config keys (`payments_stripe` …) unchanged; arch rules for `App\Extensions` (no sibling-extension or module imports; the four Core imports in review D3 are a listed baseline that M3 deletes); `PLANNED_MODULES` fixed; `MODULES` also accepts `Gateways/Stripe`; docs updated. | none             |
| M2  | Move cPanel to `Extensions/Provisioning/Cpanel`, ResellerClub and Namecheap to `Extensions/Registrars/*`, ECB and NRB to `Extensions/ExchangeRates/*`; registration moves from the host module's provider to the extension's; `tests/Extensions` subsets updated.                                                                                                                                                                                                                                                    | none             |
| M3  | Kernel: `Support\Billing\GatewayDriverRegistry` (ADR 0012), `GatewayRegistry::register(instance)` kept as a deprecated adapter; gateways register `(key, class)`; `settingsFields()` → `list<SettingField>`; `ExchangeRateSources`/`PriceConverters` take `(key, class)`; D3 removed (CurrencyDirectory, timeout via `GatewayConfig`), Core baseline deleted from the arch rule. Kernel fragment and snapshot.                                                                                                       | none for users   |
| M4  | Kernel: `extension.json` schema 1, `ExtensionManifest`, `ExtensionCatalog`, `extensions:cache` via `optimizes()`, `extensions:list`, kernel constraint check (incompatible = not booted, listed); manifests for every bundled extension. Composer `mohar-extension` discovery.                                                                                                                                                                                                                                       | none             |
| M5  | Core `extensions` table, `ExtensionState`, `extensions:install                                                                                                                                                                                                                                                                                                                                                                                                                                                       | upgrade          | uninstall | enable | disable`, per-extension migrations, lifecycle hooks and events; registries record owners and hide disabled; `extension:<id>`middleware; guarded listeners;`app:install` installs bundled extensions; settlement paths keep disabled drivers. | new commands only; everything enabled by default |
| M6  | Admin Settings → Extensions page, permission, step-up, activity log; health card links.                                                                                                                                                                                                                                                                                                                                                                                                                              | new page         |
| M7  | Author DX: `make:extension <family> <name>` skeleton that passes its kit; `extensions:check`; `tests/Fixtures/Extensions/AcmePanel` becomes a Composer path package with `extension.json`, proving discover → install → enable → disable → uninstall with `MODULES=Core`; docs-site "Write an extension" page.                                                                                                                                                                                                       | none             |
| M8+ | Later, each with its own decision: fraud and notification-channel families into the kernel (ADR 0009 §7, review D5), declared-host enforcement, UI slots, verified list.                                                                                                                                                                                                                                                                                                                                             | per PR           |

M1 and M2 are pure moves (`git mv` plus namespace edits). They are reviewed with `git diff -M` and run when no
payments or driver PR is open, to avoid rebase pain. M3 can run in parallel with M2. M4 depends on M1. M5 depends on
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
- **Freezing the kernel too early.** The first supported third-party package starts kernel 1.0 and freezes the
  provider contracts. Mitigation: no third-party support is advertised until M3 has aligned the gateway family.
- **Boot cost.** Mitigation: the catalogue is read from a cached PHP file, and the state is one cached read memoised
  per request. M5 records its cost against the `sizing.md` load-test profile.

## Questions for the owner

1. Approve the hybrid: bundled first-party extensions in `app/Extensions/<Family>/<Name>`, with third-party extensions
   as Composer packages? The alternative is splitting every integration into its own repository now (option c in the
   review), which we advise against while the kernel is pre-1.0.
2. Third-party code only through Composer, with no zip upload in admin? This means cPanel hosts need a terminal, which
   they already need to install.
3. In v1, should install, upgrade and uninstall be CLI only, with the admin page doing enable, disable and configure?
4. Should third-party extensions be limited to schema-driven UI, with no custom React pages, until UI slots exist?
5. Should the Nepal gateways stay one extension (Khalti, eSewa, connectIPS share their HTTP and amount helpers) or
   become one extension per provider?
6. Do we want a reviewed "verified extensions" list or marketplace later, and should paid third-party extensions
   (licences, editions) be in scope at all?
7. The kernel 1.0.0 trigger: the first supported third-party package, or the first tagged release, whichever comes
   first (ADR 0010)? Or a fixed date?

## Consequences

- One answer to "where does an integration go": `app/Extensions/<Family>/<Name>` for ours, or a Composer package for
  anyone else. Both go through one manifest, one lifecycle and the registries we already have.
- Hosts get an Extensions page and a kill switch without a deploy. Adding code still needs a deploy, by design.
- Third parties get a compatibility check, lifecycle hooks, migrations, a scaffold and a check command. They do not get
  a sandbox, and the docs say so.
- Business modules stay as they are, and `MODULES` keeps its test role.
