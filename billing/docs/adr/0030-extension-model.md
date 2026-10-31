# ADR 0030 — Extension model: bundled and packaged extensions, one manifest, runtime enable

- Status: Accepted · 2026-10-31 (owner decisions on Q1-Q3, Q5 and Q6; CTO calls on Q4 and Q7; see "Decisions of
  2026-10-31") · Owner: Principal architect. Proposed 2026-10-31 in #565. The number is fixed at 0030; the "Core public
  surface" ADR proposed by the best-practices audit (#569) takes 0031 (see "Relationship with ADR 0031").
  Amended the same day by "Amendment (#565)" after the independent review; where they differ, the amendment wins, and
  the text above it is what the owner approved.
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

## Amendment (#565) — 2026-10-31, independent review

From the independent principal review of #565
(https://github.com/bhimrazy/mohar/pull/565#pullrequestreview-5723218564, Approve with changes). The owner's
decisions (Q1-Q7) stand. This amendment changes how the design is built. Where it says so, it supersedes §2-§4, §6,
§7 and the migration plan; the sections above stay as approved.

### A1. One disable semantic (B1; supersedes the "Disable" bullet of §4)

- **The rule, at both levels.** An extension can be disabled, and so can one of its accounts (`gateways.is_active`,
  `registrar_accounts.is_active`, a server). Disabled means no new attempt, order, charge, module command,
  registration or transfer starts. Everything that finishes or verifies an existing record keeps running, webhooks
  included.
- **Today's per-account disable breaks this rule.** Three sites refuse settlement for an inactive account:
    - `Billing/Services/GatewayCallbackHandler.php:99` answers a webhook with 404 "gateway not active";
    - `Billing/Http/Controllers/Public/GatewayReturnController.php:25` aborts the customer's return with 404;
    - `Domains/Services/RegistrarGateway.php:72` refuses every call for an inactive account, reconcile included.

    Only the reconciler's lookup keeps running (`Billing/Actions/SettlePaymentAttempt.php:87-96`). Fixing those three
    sites is its own PR, issue #571, landing **before M5a**. It carries one test per family that disables the account
    mid-attempt and then settles.

- **Registry API.** This replaces the "`includeDisabled` path":

    | Method                        | Disabled drivers | Callers                                                                    |
    | ----------------------------- | ---------------- | -------------------------------------------------------------------------- |
    | `get(key)`                    | returned         | settlement; total for every registered key, throws only for an unknown one |
    | `has(key)`, `keys()`, `all()` | excluded         | listing, pickers, "may this be configured"                                 |
    | `enabled(key): bool`          | false            | new-work gates, called before `get()`                                      |

    Today some settlement callers gate on `has()` and some new-work callers use `get()`. Each moves to its column.

- **Settlement callers.** These must keep working while their extension or account is disabled. The list is reviewed
  when it changes, as ADR 0023's host list is:
    - **Billing:**
        - the webhook (`GatewayCallbackHandler`);
        - the return (`GatewayReturnController`);
        - `SettlePaymentAttempt` and `PaymentReconciler` (`billing:reconcile-payments`, `billing.payment_reconciliation`);
        - `SettledRefundCheck` (`billing.gateway_refund_check`);
        - refund lookups (`LooksUpRefunds`);
        - a refund staff start on an inactive gateway (`RefundTransaction`), because returning money settles an
          existing payment. It has no `is_active` check (#575);
        - `CancelsHostedPayment` for an open session.
    - **Services:**
        - `VerifyUnknownOutcomes` (`ReconcilesCommands`), which moves from `has()` (`:110`) to `get()`;
        - `SyncUsage` (`:31`) for existing services.
    - **Domains:**
        - `findSubmission` in `PerformRenewal` and `SubmitToRegistrar`;
        - `SyncDomain`, and `info` for existing domains.
- **New-work gates.** These check `enabled()`:
    - `GatewayCharger` (`:34`), checkout and the pay-method pickers;
    - `ExecuteModuleCommand` before `get()` (`:199`), which today runs a create on a hidden driver;
    - `FetchServerAccounts` (`:59`), because an import is new work;
    - `RegistrarGateway::call` for register, transfer and renew;
    - the order forms, through `TldAttributes`.

### A2. Uninstall, purge and money (B2; supersedes the "Uninstall" bullet of §4)

- **`App\Support\Extensions\ExtensionUsage`** is an aggregating registry in the ADR 0012 addendum shape:
  `register('billing', fn (array $driverKeys): int => …)`.
    - Each module counts its own rows: Billing counts `transactions`, `credit_ledger` and `payment_attempts`;
      Services counts services and servers; Domains counts domains and accounts.
    - Uninstall asks this registry, because Core cannot read those tables, and refuses on any non-zero answer.
    - A siblings-absent test proves it answers 0 with `MODULES=Core`.
- **Purge** deletes only the tables the extension's own migrations created. It never deletes a host module's rows, and
  never money or fiscal records: `transactions`, `credit_ledger`, fiscal snapshots and notes (ADR 0024) are kept for
  the life of the install, whichever extension produced them.
- **A gateway that ever took a payment ends as "disabled".** Uninstall is for an extension that was never used.

### A3. Kernel constraint (H1; supersedes §3 `kernel` and the §7 0010 matcher note)

- **`version: "self"` extensions skip the kernel check.** They are the app, and the test suite is their compatibility
  proof. The example in §3 would have failed anyway: `^0.1` breaks at the first `0.2.0`, and ADR 0010 Amendment (#513)
  bumps the minor at every pre-1.0 release.
- **Third-party manifests before 1.0** use an explicit range or `~0.N.0`. The docs say that a kernel minor may break
  them.
- **A failed constraint:**
    - before 1.0, "warned, booted", and third-party packages are unsupported;
    - from 1.0, "incompatible, not booted".
- **The matcher is a small kernel class**, `App\Support\Extensions\KernelConstraint`:
    - it handles `^`, `~`, `>=`/`<` ranges and `||`, with Composer's pre-1.0 caret rule;
    - it has unit tests;
    - `composer/semver` is not promoted to production.

### A4. Shared gateway helpers are family-wide (H3; supersedes M1b and the kernel names in the §2 Nepal bullet)

Q5 stands: eSewa, Khalti and connectIPS are separate extensions. Their shared code becomes family-neutral kernel
helpers, built in M3. All four gateway extensions adopt them, which removes review D3 everywhere at once:

- `App\Support\Billing\GatewayHttp` holds the connect and total timeouts from `GatewayConfig`, `Endpoints` pinning
  and the lookup retry policy. It replaces `ProviderHttp` and the HTTP setup in `StripeClient` and `RazorpayClient`.
- A minor-units decimal formatter beside `App\Support\Money` reads exponents through `CurrencyDirectory`. It replaces
  `NprAmount` and the exponent handling in `StripeAmount`.

`ProviderException` stays per extension, because each provider's error shape differs. There is no
`App\Support\Billing\Nepal`.

### A5. Mediums

1. **Owner attribution.** The base `ExtensionServiceProvider` opens a registration scope,
   `ExtensionScope::during($id, fn)`, around its `register()` and `boot()`. Every kernel registry reads it, and a
   registration outside any scope is "core". `provides` can then be checked without a fourth positional parameter
   (ADR 0010).
2. **Laravel discovery.**
    - A `mohar-extension` package lists no `extra.laravel.providers`. `extensions:check` fails one that does, and
      `extensions:cache` warns.
    - A package that is also `type: composer-plugin` is refused.
    - The catalogue reads `vendor/composer/installed.json`. When `bootstrap/cache/extensions.php` is absent, it falls
      back to a scan, as `packages.php` does.
    - The image build runs `optimize`, so the read-only container has the cache.
3. **Existing installs.**
    - A missing row means installed and enabled for a `version: "self"` extension, and discovered only for a package.
    - `app:install` writes rows for bundled extensions, and an M5a data migration backfills running installs.
    - The deploy steps (`docs/devops/production.md`, the cPanel upgrade) run `extensions:upgrade --all` after
      `migrate`, so `self` extensions never show "Upgrade pending".
4. **Migrations.**
    - Uninstall uses reset-by-path semantics: every migration that ran from a file under the extension's path, across
      batches, rather than `migrate:rollback`.
    - Extension migrations run in the normal `migrate` pass by timestamp, may reference Core tables only, and are
      linted by `deploy:check-migrations`.
5. **`MODULES`.** `MODULES=Core` loads no extension, and a non-empty `MODULES` loads only the extensions it names. The
   `tests/Extensions` subset tests (`AcmePanelWithoutServicesTest` expects only `['acme_panel']`) keep holding after
   M2.
6. **Conformance kits first.** `extensions:check` needs a kernel kit for each driver family.
    - Today only `ReportConformance` is a kernel kit. `ProvisioningDriverContract` and `RegistrarDriverContract` sit in
      `tests/Support`, and gateways have none.
    - M3 adds `App\Support\Billing\GatewayConformance`.
    - Before `extensions:check` ships, M7 promotes `App\Support\Services\ProvisioningConformance` and
      `App\Support\Domains\RegistrarConformance`, free of PHPUnit per ADR 0009 Amendment (#515).
7. **Key ownership.** ADR 0012 Amendment (#405)'s policy for `WebhookEvents` now covers every registry:
    - a package may not replace a key owned by core or by another extension (`replace: true` throws across owners);
    - `PermissionRegistry::register()` (`PermissionRegistry.php:35`) gains a duplicate check;
    - permission, setting and navigation keys registered inside an extension scope start with the extension's prefix.
8. **`network` is advisory.** The page labels it "declares that it calls" and never presents it as a security
   control. When enforcement comes, the list is derived from the extension's `Endpoints::for()` keys (ADR 0016) rather
   than typed twice.

### A6. Lows and the webhook invariant

- **Webhook invariant.** An extension registers no public or unauthenticated route. The host family owns the callback
  and return endpoints (`billing/webhooks/{gateway}` and `billing/gateways/{gateway}/return`, keyed by driver key). A
  URL therefore never changes when an extension moves, is disabled or is replaced. M1 adds a test that pins both URLs
  for every bundled gateway.
- **One key grammar.** Both routes allow `[a-z_]{1,50}` (`Billing/routes/web.php:131,138`), which excludes the digits
  that driver keys allow (`[a-z0-9_]{1,50}`). M1 switches both routes to the registry's pattern through one constant.
- **Family names are plural everywhere:** folder, id and `provides.family`. The families are `gateways`,
  `provisioning`, `registrars`, `fraud`, `notifications`, `mail`, `exchange_rates`, `importers`, `reports`, `widgets`,
  `themes` and `addons`.
- **Trust by location.** `app/Extensions` is first-party. Everything found through Composer is third-party, including
  a later `mohar/*` package and any `path` repository, until a verified list exists.
- **`tests/Extensions` becomes `tests/Isolation`** in M1 (`git mv` only), so that "Extensions" means extension code.
- **No worker mode.** FrankenPHP runs in classic mode (`docs/devops/container-image.md:53`), so per-request
  memoisation holds. Octane worker mode is out of scope.

### A7. Scope of M5 (H2)

- **M5a:**
    - the `extensions` table and `ExtensionState`;
    - the `install`, `upgrade`, `enable` and `disable` commands, recording the version;
    - registry gating per A1;
    - `ExtensionUsage` with the uninstall refusal;
    - events, the backfill and the deploy step.
- **M5b:**
    - per-extension migrations (reset-by-path);
    - the `install()`, `upgrade()` and `uninstall()` hooks;
    - the `extension:<id>` route middleware, guarded listeners and automation skipping.

No bundled extension needs anything in M5b, so M5b lands with its first consumer: the M7 AcmePanel package. That
package gains a migration and an `upgrade()` to prove each mechanism, following ADR 0019's "decided when the first
consumer lands". M6 needs only M5a.

### A8. Order (H4; supersedes the migration plan's order) and interleaving with #570

The code-structure review (#570, `docs/reviews/2026-10-31-code-structure.md`) runs its S-series alongside this plan.
The order below moves no file twice.

| Step | PR                                                                                                                                                                                                                                                                                                                                            | Interleaving with #570                                                                                                                                                       |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 0    | #571: per-account disable stops stranding (A1)                                                                                                                                                                                                                                                                                                | independent; must land before M5a                                                                                                                                            |
| 1    | **M3** kernel: `GatewayDriverRegistry` (key, class) with the deprecated instance adapter; `settingsFields(): list<SettingField>`; `ExchangeRateSources`/`PriceConverters` take (key, class); `GatewayHttp` and the minor-units formatter; D3 removed in all four gateways; `GatewayConformance`; `GatewayManager` leaves `Drivers/` (#570 M5) | S0 may land first. S2 (Core infrastructure to `app/Support`) touches different files, but both are kernel PRs: merge one at a time and regenerate `kernel-api.txt` on rebase |
| 2    | **M1**: loader, base provider; move Stripe, Razorpay, Cybersource; split Nepal into Esewa, Khalti, Connectips; arch scans and `scripts/test.sh` cover `app/Extensions`; `MODULES` rule; webhook-URL test; route key pattern; `tests/Isolation`                                                                                                | S1 skips `tests/Feature/Payments*` and `tests/Extensions` until M1 lands, then mirrors them under `tests/Feature/Extensions/Gateways/*` and `tests/Isolation`                |
| 3    | **M2**: cPanel, ResellerClub, Namecheap, ECB, NRB moved; each registers from its own provider                                                                                                                                                                                                                                                 | S3 and S4 on Services, Domains and Platform wait for M2, because both touch those providers and `Drivers/`. S3 and S4 on Billing wait for M3                                 |
| 4    | **M4**: manifest schema 1 (plural families), catalogue, `extensions:cache`, `extensions:list`, registration scope, `KernelConstraint` with the `self` exemption, Composer discovery rule                                                                                                                                                      | after S4 on Billing where possible, so the scope wraps Boot classes rather than private methods                                                                              |
| 5    | **M5a** (A7)                                                                                                                                                                                                                                                                                                                                  | —                                                                                                                                                                            |
| 6    | **M6**: Extensions page                                                                                                                                                                                                                                                                                                                       | after S6 if that is in flight (page paths)                                                                                                                                   |
| 7    | **M7**: `make:extension`; `extensions:check` over the promoted kits; AcmePanel as a path package with a migration and an `upgrade()`                                                                                                                                                                                                          | —                                                                                                                                                                            |
| 8    | **M5b** (A7)                                                                                                                                                                                                                                                                                                                                  | —                                                                                                                                                                            |
| 9    | **M8+** as in the plan                                                                                                                                                                                                                                                                                                                        | #570 L1 (a Payments module) comes only after M3, as #570 says                                                                                                                |

Folders inside an extension (`Drivers/`, `Support/`, `Config/`) follow #570 §3.2 once S0 is in `architecture.md`. M1
moves them as they are; by then M3 has already shrunk them.

## Amendment (#590) — 2026-11-01, notes from M3 for M4

- **Registrations happen once.** Extensions register their drivers once, in `boot()`. A kernel registry that is rebuilt
  later (`forgetInstance()`) comes back empty of them, and nothing in `app/` does that. M4's catalogue and scope must
  not rebuild a registry. If one ever must be rebuilt, the catalogue replays the registrations.
- **Route keys.** The route key pattern of A6 landed in M3: the Billing gateway routes use `DriverRegistry::KEY`.
