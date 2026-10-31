# Code structure review — 2026-10-31

- Reviewer: principal architect. First whole-codebase structure review. It is about how the code is organised: folders,
  layers, names, where each kind of class lives, how the frontend and the tests mirror the modules. The extension model
  has its own review and ADR 0030 (#565); best practices were audited in #569. Neither is repeated here; this review
  builds on both.
- Scope: `develop` at `a5b803bf`. Read against `architecture.md`, ADRs 0001, 0009, 0010, 0011, 0012, 0020, 0025, 0027,
  0030 (proposed, #565) and the proposed 0031 (#569), the Boost skills in `billing/.claude/skills/` (Laravel best
  practices, infer-conventions, Inertia React, testing, Wayfinder, Tailwind) and the `engineering:architecture`,
  `system-design` and `tech-debt` skills.
- Method: counts and `use` graphs were taken with `find` and `grep` over the worktree (the exact patterns are quoted
  where a number depends on them). Nine peer repositories were cloned shallowly and read, never run (§2, with the
  commit read for each). Paths are relative to `billing/` unless they start with `docs/`, `scripts/`, `loadtest/` or
  `deploy/`.
- Nothing was executed: no tests, no PHPStan, no build. See "Not verified".

## Summary

Mohar already has what none of the nine peers has: true module folders with a tested dependency rule (zero sibling
imports across 17 modules), a typed kernel (89 contracts, 77 DTOs, 118 events with occurrence ids), a versioned kernel
API snapshot, 29 ADRs and 28 product specs, and 38 architecture rules in `tests/Arch`. On boundaries and enforcement it is
the best-structured codebase of its kind that I could find.

Where it is not yet that is **inside** the lines it drew:

1. **Core is a second kernel.** 81 distinct `App\Modules\Core` classes are imported by the other modules (the audit
   counted 47 with a narrower pattern); `tests/Arch/ArchitectureTest.php:47` lists `App\Modules\Core` *as* a kernel
   namespace, and eight kernel files import Core back (`app/Support/Reports/ReportExporter.php:17-18`). Half of
   `Core/Support` (51 files) is database and settings infrastructure, 17 files are null fallbacks for other modules'
   contracts, and two are developer tooling. ADR 0031 decides what Core promises; this review says where the files go.
2. **The layer names are overloaded.** "Support" means the kernel (`app/Support`), the helpdesk module
   (`Modules/Support`) and a per-module helper folder that 15 of 17 modules have and `architecture.md:25` does not
   list. "Services" is a module and a layer. "Notifications" is a module and a folder of Laravel notification classes
   in three other places. A new engineer cannot tell from a path what a class is.
3. **Actions, Services and Support blur.** 142 Actions have `handle()`, but 16 have three to twelve public methods
   (`Billing/Actions/RefundTransaction.php`: 12 public methods, 773 lines; `Quotes.php` is a six-verb service). Seven of
   252 service classes carry a `Service` suffix. Read models (`BillingDashboard`, 767 lines) sit in `Support/`.
4. **Service providers are the module manifest, written as code.** `BillingServiceProvider.php` is 640 lines of
   permission, setting, navigation, search, webhook, report, merge-field and automation registrations, already split
   into private methods but still one file that every PR in the module edits. Peers declare most of this as data
   (Akaunting `module.json`, FOSSBilling `manifest.json`); ADR 0030 §3 starts a manifest.
5. **Tests and pages mirror the modules only mostly.** 130 test files are named after a PR or review round, and
   `tests/Feature/Security` has four review-dated subfolders; fakes of one kernel contract exist in two or three
   consumer folders. In the frontend, `pages/admin/promotions` belongs to Orders, `pages/admin/settings` to Themes and
   `pages/admin/clients` to Core; 33 starter-kit components sit flat beside `components/app`.

None of this blocks a release. All of it compounds: every new module copies the ambiguity. The plan in §6 is
twelve small, mostly mechanical PRs, sequenced around ADR 0030's moves so nothing is moved twice.

### Scorecard

| Dimension                               | Score /10 | One line                                                                                              |
| --------------------------------------- | --------- | ----------------------------------------------------------------------------------------------------- |
| Module boundaries                       | 9         | No sibling imports; contracts and events only; `MODULES=` filter tested in `tests/Extensions`.        |
| Kernel shape                            | 7         | Small, typed, versioned; but Core carries kernel infrastructure and the kernel imports Core.          |
| Module internal layering                | 5         | Documented tree lists 12 folder kinds, code uses 25; Actions with many verbs; read models in Support. |
| Naming                                  | 5         | Support ×3, Services ×2, Notifications ×3; `Service` suffix on 7 of 252.                              |
| Integration placement                   | 5         | Three homes for drivers; ADR 0030 fixes it (not re-scored here).                                      |
| Frontend structure                      | 6         | `components/<module>` mirrors modules; pages do not always; hand-written types; 15 pages > 800 lines. |
| Test layout                             | 5         | By module, with review-round folders and 130 PR-named files on top; duplicated fakes.                 |
| Documentation of the structure          | 7         | ADRs and specs are excellent; `architecture.md` tree is stale and the file is becoming everything.    |
| Enforcement                             | 8         | 38 arch rules, API snapshot, migration linter; nothing yet enforces the layering inside a module.      |
| **Overall**                             | **63**    | Boundaries are solved; the inside of the boundary is next.                                            |

## 1. Mohar's current map

### 1.1 Shape and size

| Area                           | Files | Lines   | Notes                                                                                   |
| ------------------------------ | ----- | ------- | --------------------------------------------------------------------------------------- |
| `app/Modules/*` (17 modules)   | 1,253 | ~122k   | Billing 231 files / 25.7k lines; Services 143 / 15.6k; Core 137 / 10.7k; Domains 106.   |
| Kernel: `Contracts`            | 89    |         | 16 areas; Domains 22, Billing 16, Services 15, Reports 7, Migration 6.                  |
| Kernel: `Data`                 | 77    |         | 11 areas.                                                                               |
| Kernel: `Events`               | 118   |         | 12 areas; 5 implement `ShouldDispatchAfterCommit` (audit H3).                           |
| Kernel: `Enums`                | 17    |         |                                                                                         |
| Kernel: `Support`              | 82    |         | 22 subfolders; 53 files carry `@api`; `Kernel::API_VERSION` 0.1.0.                      |
| Root `app/`: `Actions/Fortify`, `Concerns`, `Http`, `Models`, `Notifications`, `Providers` | 33 | | Starter-kit auth and staff self-service, outside every module. |
| `resources/js`                 | 421   | ~84k    | pages 159, components 203 (ui 32, app 45, flat 33, 14 module folders), lib 25, types 14. |
| `tests`                        | 704   | ~94k    | Feature 554, Unit 60, Fakes 33, Fixtures 16, Support 15, Arch 10, Extensions 10, Simulators 6. |
| `tools/simulators`             |       |         | Dev-only Composer package (ADR 0016): bin, src, tests.                                  |
| `billing/docs`                 | 1,276 |         | 897 screenshots, 258 design files, 41 architecture, 29 ADRs, 28 product specs.          |
| `docs/`                        | 39    |         | handoff (process), reviews, research.                                                   |
| `docs-site`                    | 71    |         | Astro; 26 pages: get-started, setup, daily, operate.                                    |
| `scripts/`, `loadtest/`, `deploy/` | 16, 22, 16 |  | Repo tooling, k6 profiles, Docker/FrankenPHP/Caddy.                                     |

Test-to-code: 0.66 lines of PHP test per line of app code (Lunar 230 test files for 721 source files is the closest
peer ratio; Paymenter has 17 test files).

### 1.2 Module building blocks (PHP files per folder)

| Module         | Actions | Services | Support | Jobs | Listeners | Models | Enums | Data | Drivers | Controllers | Requests | Migrations | Other folders                     |
| -------------- | ------- | -------- | ------- | ---- | --------- | ------ | ----- | ---- | ------- | ----------- | -------- | ---------- | --------------------------------- |
| Billing        | 29      | 35       | 15      | 1    | 2         | 18     | 11    | 4    | 5       | 24          | 17       | 28         | Reports, Rules, Import, Resources |
| Services       | 27      | 12       | 18      | 3    | 3         | 13     | 10    | 0    | 8       | 10          | 10       | 13         |                                   |
| Core           | 0       | 0        | 51      | 0    | 0         | 14     | 2     | 0    | 0       | 5           | 2        | 23         | Install 14, Import, Security, Notifications |
| Domains        | 23      | 11       | 6       | 6    | 4         | 9      | 1     | 0    | 8       | 6           | 13       | 9          |                                   |
| Support        | 17      | 17       | 0       | 0    | 0         | 15     | 10    | 2    | 3       | 19          | 17       | 8          | Rules                             |
| Orders         | 15      | 13       | 3       | 1    | 3         | 6      | 7     | 3    | 0       | 6           | 8        | 3          | Fraud 7, Pricing 11               |
| Platform       | 18      | 5        | 9       | 2    | 1         | 4      | 0     | 0    | 2       | 18          | 10       | 5          | Notifications 4                   |
| Notifications  | 2       | 11       | 5       | 2    | 3         | 7      | 6     | 3    | 0       | 7           | 4        | 5          | Mail 6, Rendering, Webhooks 6, Resources |
| Catalog        | 17      | 3        | 4       | 0    | 1         | 10     | 8     | 0    | 0       | 8           | 17       | 7          |                                   |
| Kyc            | 5       | 9        | 0       | 0    | 1         | 3      | 3     | 0    | 0       | 3           | 1        | 4          |                                   |
| Migration      | 4       | 0        | 11      | 4    | 0         | 5      | 0     | 0    | 0       | 2           | 0        | 6          |                                   |
| Storefront     | 0       | 5        | 3       | 0    | 0         | 1      | 0     | 0    | 0       | 3           | 1        | 2          |                                   |
| Themes         | 0       | 0        | 4       | 0    | 0         | 1      | 0     | 0    | 0       | 4           | 6        | 2          | Resources (presets)               |
| Payments ×4    | 0       | 0        | 2–5     | 0    | 0         | 0      | 0     | 0    | 1–3     | 0           | 0        | 0          | Pure driver packages              |

Observations, with evidence:

- **Policies: documented, never used.** `architecture.md:25` lists `Policies/`; no module has one. Authorization is
  `$this->authorize('<permission>')` (257 calls) plus `abort_unless(...)` (45). The documented layer is fiction.
- **`Support/` is the most common undocumented folder** (15 modules) and the largest in Core (51) and Services (18).
  What it holds, by reading Billing and Services: read models for dashboards and pages (`BillingDashboard` 767 lines,
  `BillingAdminClientSummary`, `ServicesDashboard`, `TaxNoteList`, `TaxNotePanel`), typed settings readers
  (`BillingSettings`, `ServiceSettings`, `ServerSettings`), sibling adapters (`Services/Support/Billing.php`,
  `Catalog.php`, each wrapping a kernel contract with `available()`), domain calculators (`Proration`,
  `UsernameGenerator`, `BikramSambat`), and a transaction helper the kernel is meant to replace
  (`Billing/Support/AfterCommit.php`, audit H3). Five kinds of thing, one folder name.
- **25 folder kinds in use, 12 documented.** Beyond the documented tree: `Support`, `Data`, `Enums`, `Exceptions`,
  `Rules`, `Reports`, `Import`, `Resources`, `Fraud`, `Pricing`, `Webhooks`, `Rendering`, `Security`, `Mail`,
  `Notifications`. Most are good names for what they hold (`Orders/Pricing` and `Orders/Fraud` are the clearest
  sub-domains in the codebase). The problem is only that nothing says which are allowed, so each module invents.
- **Actions that are services.** `grep -c "public function"` over `Actions/*.php`: 16 files with more than two public
  methods. `Billing/Actions/RefundTransaction.php:59-689` has `handle`, `settle`, `check`, `resolve`, `release`,
  `failedAtProvider`, `flagFailedAtProvider`, `unsettled`, `existing`, `assertNoRefundInFlight`,
  `assertGatewayCanRefund`, `refundAtGateway`. `Billing/Actions/Quotes.php:48-232` has `save`, `deliver`, `accept`,
  `decline`, `convert`, `expire`. `PaymentBatches`, `ManagePayMethods`, `ConvertPrices`, `ConvertTldPrices`,
  `VerifyUnknownOutcomes`, `ExecuteModuleCommand` likewise. The rule at `architecture.md:168-170` ("single-purpose
  classes with `handle()`") is followed by 126 of 142.
- **`Service` suffix drift.** `InvoiceService`, `CreditLedgerService` (`BillingServiceProvider.php:60-62`) against
  `InvoiceSettlement`, `GatewayCharger`, `TaxNoteIssuer`: 7 of 252 classes under `Services/` carry the suffix.
- **Hosts inside `Drivers/`.** `Billing/Drivers/Gateways/GatewayManager.php` is the host that calls drivers, beside
  `BankTransferDriver` and `ManualDriver` (the built-ins ADR 0030 §1 keeps in the module). Same folder, opposite role.
- **Laravel notification classes under a folder named after another module.** `Core/Notifications/LoginEmailNotice`,
  `Platform/Notifications/{ClientInvitationNotification,MailServerTest,SecurityNotice,StaffInvitationNotification}`,
  `app/Notifications/SetPasswordNotification`, while `Modules/Notifications` is the templated-email module and
  `Notifications/Mail/` holds its mailables.
- **Service providers as manifests.** `BillingServiceProvider.php` is 640 lines: `bootModule()` (143) registers
  webhooks and reports inline, then calls private `registerAutomation` (236), `registerNavigation` (252),
  `registerDashboard` (283), `registerPermissions` (321), `registerSettings` (360), `registerSearch` (465) and
  `registerMergeFields` (588); the merge-field resolvers themselves (`invoiceMergeFields` 499-545,
  `taxNoteMergeFields` 546-582) are data mapping, not registration. Domains 582 lines, Services 433, Support 401,
  Platform 340, Orders 332. The base class (`app/Support/Modules/ModuleServiceProvider.php:29-57`) is 100 lines and
  sound, and Billing's private-method split is the right shape; it is one file, so it is the file every PR in the
  module edits.
- **Module-local DTOs** exist (`Billing/Data`, `Orders/Data`, `Support/Data`, `Notifications/Data`) beside kernel
  `app/Data`. The split is right (private shapes stay private); it is undocumented.

### 1.3 Core, Platform and the kernel

- `Core/Support` (51 files) decomposes as: **kernel infrastructure** that happens to live in a module (`Settings`,
  `ActivityLogger`, `ClientContext`, `OnlineIndex`, `LockTimeout`, `Like`, `ChunkedDelete`, `RequestMemo`,
  `DateFormats`, `RetentionRunDate`, `SettingList`, `ConstantRows`, `CurrencyRollup`, `CachedCurrencyDirectory`,
  `InMemoryMergeFieldRegistry`, `DefaultBrandProvider`, `CoreCompanyClock`); **17 `Null*` fallbacks** for contracts
  other modules implement, bound with `bindIf` in `CoreServiceProvider.php:115-133`; **developer tooling**
  (`MigrationLint` 584 lines, `KernelApiSnapshot` 481); and Core's own domain (`AccountLinks`, `LoginSessions`,
  `LoginEmailChange`, `InstallSecrets`, `ClientRules`, `InvitationExpiry`).
- Imports of Core by other modules (`^use App\\Modules\\Core\\`, union, `Null*` excluded): 81 distinct classes;
  the top ten are `ActivityLogger` 141, `Client` 134, `Settings` 89, `Currency` 42, `OnlineIndex` 33, `ClientContext`
  29, `LockTimeout` 19, `Role` 13, `Contact` 10, `DemoSeeder` 10. Three Core classes carry `@api`; none is in
  `kernel-api.txt` (audit H1).
- The kernel imports Core in eight files: `app/Support/Integrations/CertificateBundle.php:7`,
  `app/Support/Reports/ReportExporter.php:17-18`, `app/Http/Middleware/HandleInertiaRequests.php:8-9`,
  `app/Providers/{App,Fortify}ServiceProvider.php`, and three starter-kit controllers. `architecture.md:44` permits
  it ("because Core always loads"); `ArchitectureTest.php:47` encodes it by listing Core among the kernel namespaces.
  A dependency that points from the kernel into a module is a kernel class in the wrong folder.
- **Platform is Core's admin surface.** 10 of Platform's 16 admin controllers edit Core models (`ActivityLog`,
  `Automation`, `ClientContact`, `ClientGroup`, `ClientLifecycle`, `ClientUser`, `Currency`, `Role`, `Settings`,
  `Staff`); Platform owns four models of its own (`ExchangeRateHistory`, `ExchangeRateReview`,
  `ImpersonationSession`, `StaffInvitation`) and imports 30 distinct Core classes 143 times. Core keeps its own five controllers
  (`Admin/{Client,Dashboard,StaffNotice}Controller`, `Client/{Dashboard,Account}Controller`). So an admin screen for
  a Core model has two possible homes, and the pages mirror that: `pages/admin/clients` (Core) next to
  `pages/admin/platform/client-groups` (Platform).
- **Starter-kit auth lives nowhere.** `app/Actions/Fortify` (6), `app/Http/Controllers/{Auth,Settings}` (5),
  `app/Http/Requests/Settings` (4), `app/Concerns` (2), `app/Notifications` (1), `routes/settings.php`,
  `pages/settings/*` and `pages/auth/*`. It is the one area with real behaviour (step-up, passkeys, password
  guesses, sessions) that no module owns and no product spec names.

### 1.4 Cross-module dependencies

- Sibling imports (`^use App\\Modules\\<X>` where X is neither the module itself nor Core): **zero** in all 17 modules.
  The arch rule at `ArchitectureTest.php:34-42` is one `arch()` per module, as the pest-plugin-arch caveat at
  `architecture.md:52-55` requires.
- Cross-module calls go through 89 contracts; optional siblings are handled two ways: Core's `Null*` fallbacks
  (17) and the Services module's adapters (`Services/Support/Billing.php:22-27` checks `container->bound(Invoicer::class)`).
  Both work. Only Services names the pattern; Orders and Domains inline the same checks in actions.
- Reports (ADR 0025) is the one documented exception (reads sibling tables); in practice `Billing/Reports/*` holds
  the VAT registers and nothing else uses the exception yet.

### 1.5 Frontend

- `resources/js/pages/{admin,client,public}/<module>/<resource>/{index,show,form,edit,create}.tsx`: 56 index, 21 show,
  19 form, 8 edit, 6 create. Good, regular, and matched by `Inertia::render` strings.
- Mismatches between page folder and owning module: `admin/promotions` (rendered by
  `Orders/Http/Controllers/Admin/PromotionController`), `admin/settings/{appearance,theme-editor}` (Themes),
  `admin/clients` (Core) beside `admin/platform/*` (Platform), `settings/*` (staff self-service, starter kit),
  `dev/*` (design showcase, `routes/web.php:13-17`), `public/store` (Orders) beside `public/storefront` (Storefront).
- `components/`: 32 shadcn `ui/`, 45 shared `app/`, 14 module folders that mirror the modules, and **33 flat files**
  from the starter kit (`input-error` is imported by 53 pages, `password-input` by 13, `heading` 8).
- `types/`: 14 hand-written files, one per area, plus `components/billing/types.ts`. Nothing is generated from PHP
  resources; a prop renamed on the server is caught only by a page test that asserts it.
- Size: 14 pages over 800 lines and 22 over 600, the largest `pages/admin/billing/invoices/show.tsx` at 1,381 and
  `pages/admin/services/show.tsx` at 1,247; `layouts/public-layout.tsx` is 864 and `components/billing/tax-notes.tsx`
  879.
- Hooks (10) and `lib/` (16 files, 8 with Vitest tests in `lib/__tests__`) are small and sane.

### 1.6 Tests

- `tests/Feature/<Module>` exists for every module (Billing 104, Services 66, Core 45, Domains 42, Platform 33, Kyc 31).
- On top of the module layout: `Feature/Security` (41 files, with subfolders `Review1029`, `Review1031`, `Rr536`),
  `Feature/ReviewProbes` (6), `Feature/Review` (1), `Feature/Integrations`, `Feature/Reports`, `Feature/Settings`,
  `Feature/Auth`, `Feature/TestHarness`, `Unit/Deploy` (9, tests of `deploy/` scripts), `Unit/Scripts` (6, tests of
  `scripts/`). `scripts/test.sh:117-119` has to list these by hand because they do not follow the module rule.
- `Feature/Core` mixes Core-module tests (`AdminClientsTest`, `ClientAreaTest`) with kernel tests
  (`KernelApiSnapshotTest`, `ReferenceDataRegistryTest`, `DashboardRegistryTest`, `InstallCommandTest`,
  `CheckMigrationsRulesTest`), because the kernel has no test folder of its own; `Unit/Support` (20) is the kernel's
  unit folder under the name of the helpdesk module.
- PR-named files: 130 match `Probe|Pr[0-9]+|Rr[0-9]+|Review[0-9]+` (the audit's 144 used a broader pattern). Examples:
  `Feature/Kyc/Pr286StepDownProbeTest.php`, `Feature/Security/Pr253Recheck3ProbesTest.php`,
  `Extensions/Pr264ArchitectProbesTest.php`. The review rules say probes become tests; nothing says they are then
  renamed by behaviour, so they are not.
- Fakes are organised by consumer, not by contract: `Fakes/Orders/FakeInvoiceQueries` and
  `Fakes/Services/FakeInvoiceQueries`; `FakeInvoicer` in Domains and Services; `FakePriceBook` in Orders and Services.
- `tests/Arch` (10 files, 38 rules) is the strongest asset: boundaries, DTO purity, event conventions, simulator
  isolation, redaction scope, report conformance, company-clock scans, tax-note write paths. What it does not cover:
  the shape of an Action, the `Service` suffix, controller size, page path versus module, queue names, probe naming.

## 2. Peer comparison

Read read-only at these commits: Paymenter `5c29ca0` (2026-10-30), FOSSBilling `2dc164b` (2026-10-30), WHMCS sample
modules `664b55b`/`a3e6196`/`78881ca`/`5be7ff7`/`62d1fba` (2020-2024) plus developers.whmcs.com on 2026-10-31
(structure and concepts only), Akaunting `a7a5fc7` (2026-10-31), Lunar `82f6e4a` (2026-08-27), Laravel React starter
kit `98d9b82` (2026-10-29), Pelican panel `464f1a6` (2026-10-30), Invoice Ninja `15ea35e` (2026-10-18).

| Dimension            | Mohar                                                          | Paymenter                                              | FOSSBilling                                                    | WHMCS samples                                          | Akaunting                                              | Lunar                                                  | Pelican                                                      | Invoice Ninja                                            |
| -------------------- | -------------------------------------------------------------- | ------------------------------------------------------ | -------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------------ | -------------------------------------------------------- |
| Domain organisation  | 17 `app/Modules/<Name>`, auto-discovered, `MODULES=` filter    | Flat Laravel folders, 54 models                        | 40 `src/modules/<Name>` with `manifest.json`                   | n/a (one module per integration)                       | Flat core (1,086 files), `modules/` empty in core      | 8 packages; `core` is one package, 43 models           | Flat; 3 Filament panels                                      | Flat; 2,559 files; `Services/<Entity>/*`                 |
| Internal layering    | Actions, Services, Support, Models, Drivers, Jobs; blurred     | Livewire + 807-line static helper; no DTOs             | `Service.php` (Invoice: 5,496 lines), Api/{Admin,Client,Guest} | One file, prefixed global functions                    | Jobs-as-actions (`Create/Update/Delete<X>`) with marker interfaces | Actions + config-driven Pipelines + Managers           | 55 single-purpose Services                                   | Controller → Repository → `Services/<Entity>` → Transformer |
| Driver layer         | Interfaces + capability interfaces + DTOs + kernel registries  | Duck-typed `Extension` base; `ExtensionHelper::call()` | Abstract adapters, static `getConfig()`                        | Prefixed functions, `$params` arrays                   | Payment modules via event `PaymentMethodShowing`       | `Illuminate\Support\Manager` + `extend()`              | `<Kind>SchemaInterface` + `<Kind>Service` registry           | `BaseDriver` (768 lines), class name from DB row         |
| Manifest / lifecycle | None yet (ADR 0030 §3-4 proposed)                              | `#[ExtensionMeta]` attribute; install/upgrade/boot     | `manifest.json`, `min_fossbilling_version`, directory client   | `whmcs.json` metadata; `activate/deactivate/upgrade`   | `module.json`, per-company enable, in-app store        | composer `extra.lunar`                                 | `plugin.json`, `SUPPORTED_API_VERSION`, crash-safe mode      | nwidart `modules_statuses.json`                          |
| Settings schema      | `SettingDefinition` registry + `SettingField` DTO + `FieldType` | One big PHP array, DB override                         | DB per module, untyped                                         | 6 field types in arrays                                | `laravel-setting`, flat dotted keys                    | Config files only                                      | Written to `.env`                                            | Typed `CompanySettings` with cascade                     |
| Events / hooks       | 118 kernel events, occurrence ids, webhook registry            | Model observer events + string hooks                   | Typed events, `#[AsEventListener]`, signature-validated        | Global `add_hook(point, priority, fn)`                 | 169 `*ing/*ed` pairs                                   | 1 event; pipelines and modifiers                       | 10 events → configurable webhooks                            | 139 events, 778-line map                                 |
| Frontend             | Inertia/React/TS; `pages/<area>/<module>`, `components/<module>` | Blade + Livewire + Filament; themes                    | Twig per module; themes                                        | Smarty `.tpl` per module                               | Blade + Livewire + Vue islands                         | Filament only                                          | Filament only                                                | Blade + Livewire; React app in another repo              |
| Tests                | 704 files; Pest; 38 arch rules; conformance kits; simulators   | 17 files, PHPUnit                                      | ~297 files; per-module `tests/`; template and API linters      | PHPUnit stubs                                          | 50 files                                               | 230 files per package                                  | 151 files; API snapshot fixtures; custom PHPStan rule        | 652 files, flat                                          |
| Docs in repo         | 29 ADRs, 28 specs, architecture.md, kernel changelog           | README                                                 | `AGENTS.md` (267 lines), CONTRIBUTING, GOVERNANCE              | README per sample                                      | README                                                 | README, CONTRIBUTING                                   | `code-standards.md`, `api-versioning.md`                     | README, OpenAPI tree                                     |

What the peers do better, and what to take:

- **FOSSBilling: tests live with the module** (`src/modules/Invoice/tests/{Unit,E2E}`) and the suite lints
  templates and API serialisation (`tests/Support/ToApiArrayAuditor.php`, `entity-columns.snapshot.json`). Mohar keeps
  tests under `tests/`, which is right for Pest and CI sharding, but the path rule should be as strict as theirs
  (§3.5). Its `AGENTS.md` records the in-flight migration state of legacy code; Mohar's `state.md` does the same.
- **Akaunting: Jobs-as-actions with marker interfaces** (`ShouldCreate`, `HasOwner`) and a `module.json` that
  declares `reports`, `widgets`, `settings`. The declarative half is the lesson: a module's registrations as data,
  not 500 lines of `bootModule()` (§3.3, §5 M2).
- **Lunar: config-driven pipelines** (`config/cart.php` `pipelines`) for the calculation chains, and
  `ModelManifest` for swapping a model class. Mohar's `Orders/Pricing` is already a pipeline in spirit; naming it
  as one would let a package insert a step. Not structural, noted for ADR 0030 M8.
- **Pelican: one schema interface per extension kind** (`Extensions/OAuth/OAuthSchemaInterface` with
  `getSettingsForm()`), explicit `app/Extensions/<Kind>`, and a custom PHPStan rule (`app/PHPStan`) that forbids
  `app()`/`resolve()`. ADR 0030 adopts the folder; the PHPStan rule is a cheaper enforcement than a scan test for
  rules about call sites (§4).
- **WHMCS: the sample module per type is the documentation.** `tests/Fixtures/Extensions/AcmePanel` is Mohar's
  equivalent for provisioning; ADR 0030 M7 extends it. Keep one sample per family under `tests/Fixtures/Extensions`.
- **Invoice Ninja: typed, cascading settings** (`DataMapper/CompanySettings` → group → client). Mohar's
  `<Module>Settings` readers in `Support/` are the start of the same thing; they deserve a folder and a name (§3.2).
- **Starter kit: pages mirror controllers one-to-one** and generated Wayfinder output is git-ignored. Mohar kept both.
  The kit has no feature folders; Mohar added `components/<module>` and should finish the job for pages (§3.4).

What Mohar does better than every peer: tested module boundaries; typed contracts and DTOs instead of arrays; a
versioned kernel API with a snapshot; occurrence ids on events; conformance kits for drivers; simulators for every
provider; ADRs; a migration linter; and a company clock. No peer generates TypeScript from PHP either, so that gap is
open ground, not a lag.

## 3. Target structure

The target keeps every decision already taken (ADR 0001 modules, 0009/0012 drivers and registries, 0030 extensions,
0031 Core surface) and adds the rules that are missing: which folders a module may have, what each holds, and how
the frontend and tests mirror them.

### 3.1 Folder tree

```
app/
  Contracts/<Area>/            interfaces (kernel); Null<Contract>.php fallbacks live beside them in Support/<Area>
  Data/<Area>/                 kernel DTOs
  Events/<Area>/               kernel events
  Enums/
  Support/<Area>/              kernel services and registries; Database/ (OnlineIndex, LockTimeout, Like, ChunkedDelete),
                               Settings/ (store + registry), Audit/ (ActivityLogger behind Contracts\Audit),
                               Tooling/ (MigrationLint, KernelApiSnapshot)
  Http/                        kernel middleware, error pages
  Extensions/<Family>/<Name>/  first-party extensions (ADR 0030 §2)
  Modules/<Module>/
    <Module>ServiceProvider.php   bindings + calls the Boot classes; under 120 lines
    Boot/                      one class per registry: Permissions, Settings, Navigation, Search, MergeFields,
                               Webhooks, Reports, Automation, Listeners
    Config/<module>.php
    Models/                    Eloquent; factories referenced by #[UseFactory]
    Enums/
    Data/                      module-private DTOs (kernel ones go to app/Data)
    Actions/                   one public handle(), one write use case; may call Services and other Actions
    Services/                  stateless collaborators with several verbs; no "Service" suffix
    Queries/                   read models: dashboards, summaries, lists, panels; the only place Inertia props are
                               composed from the database outside a controller (ADR 0020 hot paths live here)
    Adapters/                  the module's typed access to sibling contracts, with available() (Services/Support/
                               Billing.php is the model)
    Settings/                  <Module>Settings typed reader(s)
    Support/                   pure domain helpers with no framework role (calculators, value objects, generators)
    Drivers/<Family>/          built-in drivers only (ADR 0030 §1); the host is a Service
    Jobs/  Listeners/  Rules/  Exceptions/  Mail/
    Console/Commands/          <module>:<action>
    Install/                   InstallsReferenceData
    Database/{Migrations,Factories,Seeders}/
    Http/Controllers/{Admin,Client,Public,Webhooks}/  Http/Requests/  Http/Resources/  Http/Middleware/
    Resources/views/           Blade for PDFs and mail only
    routes/{web,api}.php
  Auth/                        (owner decision, §5 H6) Fortify actions, step-up, sessions, staff self-service
resources/js/
  pages/<area>/<module>/<resource>/{index,show,create,edit,form}.tsx   area ∈ admin, client, public, auth, account
  components/ui/               shadcn
  components/app/              shared composites (page, data-table, field, money, ...)
  components/auth/             the starter-kit auth pieces (moved from the flat folder)
  components/<module>/         module composites, with types.ts beside them
  layouts/  hooks/  lib/  types/ (shared only: auth, navigation, ui, app)
tests/
  Arch/  Extensions/  Simulators/
  Kernel/{Feature,Unit}/<Area>/     kernel tests (today split across Feature/Core and Unit/Support)
  Feature/<Module>/<Area>/<Behaviour>Test.php
  Unit/<Module>/
  Fakes/<ContractArea>/Fake<Contract>.php   one fake per contract, by contract area
  Fixtures/  Support/
  Tooling/                     tests of scripts/ and deploy/ (today Unit/Deploy, Unit/Scripts)
```

### 3.2 Layering rules per module

| Building block | Holds                                                                                               | May depend on                                              | Must not                                                                 |
| -------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------ |
| Controller     | validate (FormRequest) → Action or Query → render/redirect; under 150 lines                         | Actions, Queries, Requests, Resources                      | query other modules' tables; `DB::`; business branching; inline `validate()` except trivial filters |
| Action         | one write use case, `handle()`, transaction, dispatches events                                      | Models, Services, Adapters, Settings, other Actions        | render; read sibling models; more than one public method                 |
| Service        | stateless collaborator with several verbs (`InvoiceSettlement`, `GatewayCharger`)                   | Models, Adapters, Settings, Support                        | HTTP; `Service` suffix                                                   |
| Query          | read model returning arrays or DTOs for pages, dashboards, exports                                  | Models, Settings                                           | write; dispatch events                                                   |
| Adapter        | typed access to a sibling's kernel contract, `available()` first                                    | `App\Contracts`, `App\Data`                                | any `App\Modules\<Sibling>` class                                        |
| Settings       | typed reader over `Settings::get('<module>.*')`                                                     | kernel Settings contract                                   | writes                                                                   |
| Support        | pure helpers: calculators, value objects, generators                                                | kernel value objects                                       | the container, models, requests                                          |
| Job            | queued wrapper that calls one Action; `Queues::*` constant; `$tries`, `$timeout` below `retry_after` | Actions                                                    | business logic of its own                                                |
| Listener       | reacts to a kernel event by calling an Action; only reacts to `BillableType`s it owns                | Actions, Adapters                                          | sibling models                                                           |
| Driver         | implements a kernel driver contract; sees DTOs only                                                 | kernel only                                                | models, the database, Core                                               |
| Boot           | one registry's registrations for the module                                                         | kernel registries                                          | bindings (those stay in `registerModule()`)                              |

Naming: Actions are verb phrases (`ApplyPayment`, `IssueInvoice`); Services are noun phrases naming the collaborator
(`InvoiceSettlement`, `TaxNoteIssuer`); Queries end in what they return (`BillingDashboard`, `TaxNoteList`,
`ServicesAdminClientSummary`); Adapters are named after the sibling area (`Billing`, `Catalog`); Settings are
`<Module>Settings`; Boot classes are named after the registry (`Permissions`, `Navigation`). Laravel notification
classes go in `Mail/` with the mailables (they are mail), so that `Notifications/` means only the module.

### 3.3 Service provider

`<Module>ServiceProvider` keeps `moduleKey()`, `registerModule()` (bindings) and `schedule()`; `bootModule()` becomes
`$this->boots([Permissions::class, Settings::class, Navigation::class, ...])` where each Boot class has one
`__invoke(Container $app)`. This is a pure move; when ADR 0030's manifest grows beyond extensions, the Boot classes
are what a manifest generator would read.

### 3.4 Frontend rules

- Page path = `<area>/<module key>/<resource>/<verb>`; the module key is the one in `moduleKey()`; `promotions` is
  therefore `admin/orders/promotions`, appearance is `admin/themes/appearance`. Staff self-service pages go under
  `account/` (profile, security, set-password) and `auth/` stays for the guest flows.
- A page composes; a section that passes 150 lines becomes a component in `components/<module>/`. Target: no page
  over 600 lines (22 are today; none grows).
- `components/` has no flat files: shared composites in `app/`, auth pieces in `auth/`, shadcn in `ui/`.
- Types: props types live beside the components that use them (`components/<module>/types.ts`, as Billing does);
  `types/` keeps only cross-cutting shapes. Generating them from PHP (`spatie/typescript-transformer`) is a dependency
  decision under ADR 0007; it is the right next step once Resources are the only prop source (today props are
  Resources *or* arrays, `architecture.md:255-256`), and the audit's PR 9 should decide it, not this review.

### 3.5 Test rules

- Path mirrors code: a test for `App\Modules\Billing\Actions\ApplyPayment` is under `tests/Feature/Billing/`
  (sub-area optional), named by behaviour (`ApplyPaymentTest`, `InvoiceOverpaymentTest`), never by a PR, a round or
  a reviewer. A probe is adopted into the behaviour file whose behaviour it pins, or becomes one.
- Cross-cutting suites keep their folders when the subject is cross-cutting (`Security` for headers, proxies, TLS,
  encryption coverage; `Auth` for sign-in; `Integrations` for the redaction and outbound guard), not when the subject
  is one module's behaviour found during a security review.
- Kernel tests live in `tests/Kernel`; tests of repository scripts in `tests/Tooling`.
- One fake per contract, under the contract's area; a module-specific test kit (`ServicesTestKit`) may compose them.

## 4. Rules to enforce with Pest arch tests

Each as one `arch()` per target (the pest-plugin-arch caveat), with a frozen baseline where the codebase does not
yet pass, and a guard that the baseline only shrinks. In order of value:

1. **Action shape.** Every class under `App\Modules\*\Actions` has exactly one public method besides the constructor,
   named `handle`. Baseline: the 16 files in §1.2. (Scan test: reflection over the class list.)
2. **No `Service` suffix** under `Services/`. Baseline: 7.
3. **Queries never write.** `App\Modules\*\Queries` does not use `Illuminate\Support\Facades\DB::transaction`,
   `Model::save/create/update/delete`, or dispatch events. (`not->toUse` on the facade; a text scan for the model
   write methods.)
4. **Adapters use the kernel only.** `App\Modules\*\Adapters` does not use `App\Modules\*` beyond its own namespace
   (already true for Services; becomes a rule when the folder exists).
5. **Support is pure.** `App\Modules\*\Support` does not use `Illuminate\Http`, `Illuminate\Contracts\Container`,
   `Illuminate\Database\Eloquent\Model` after the Queries/Adapters/Settings split. Baseline: the current contents.
6. **Allowed module folders.** A scan that the first directory level under each module is one of the §3.1 names;
   a new kind needs a line in `architecture.md` and in the test in the same PR (as the migration blocks do today).
7. **Drivers hold drivers.** Every class under `Drivers/<Family>` implements the family's kernel contract or is named
   `*Exception`; hosts are elsewhere. Baseline: `GatewayManager`.
8. **Page path = module.** A scan over `Inertia::render('<path>'` in each module's controllers: the second path
   segment equals the module key, or the first is `auth`/`account`/`dev`. Baseline: the six mismatches in §1.5.
9. **Jobs use `Queues::*`** (audit L2) and call one Action: `App\Modules\*\Jobs` uses `App\Support\Queues` and no
   `Illuminate\Support\Facades\DB`.
10. **Test naming.** `tests/Arch/HygieneTest.php` gains: no test file name matches `/Pr\d+|Rr\d+|Probes?|Review\d+/`,
    and no test directory matches `/Review\d+|Rr\d+|Probes/`, baseline 130 that only shrinks.
11. **One fake per contract**: no two files under `tests/Fakes` declare a class with the same short name.
12. **Service provider size**: `<Module>ServiceProvider` under 150 lines once the Boot split lands (a line-count scan
    like `CheckMigrationsRulesTest`).
13. **Kernel does not import Core**: delete `App\Modules\Core` from the kernel list at `ArchitectureTest.php:47` and
    add `App\Support|Contracts|Data|Events|Enums|Http` `not->toUse('App\Modules\Core')`, after S2 in §6.

Rules about call sites rather than imports (no `app()` in Actions, `Settings` only through the typed reader) are
better as a custom PHPStan rule, as Pelican does; PHPStan runs on every PR already.

## 5. Ranked gaps

### High

**H1. Core carries kernel infrastructure, and the kernel imports it back.**
Evidence: §1.3; `ArchitectureTest.php:47`; `architecture.md:44`; `ReportExporter.php:17-18`;
`CoreServiceProvider.php:115-131` binding 17 `Null*` fallbacks for other modules' contracts.
Impact: every module and every future extension depends on an unversioned folder; the arch test cannot say "kernel
does not depend on modules" without lying; ADR 0031 cannot version Core without first separating what is kernel.
Fix: S2 moves the infrastructure and tooling to `app/Support/{Database,Tooling}` and the `Null*` fallbacks to
`app/Support/<Area>` beside their contracts (bound by `bindIf` in `AppServiceProvider`); ADR 0031 then puts `Settings`,
`ActivityLogger` and `ClientContext` behind `Contracts\{Settings,Audit,Clients}` with Core as the implementer, and the
kernel list loses `App\Modules\Core`.

**H2. One folder name, five kinds of class (`Support/`), and the Actions/Services line is not held.**
Evidence: §1.2; `RefundTransaction.php:59-689`; `Quotes.php:48-232`; `BillingDashboard.php` (767 lines) in Support;
`architecture.md:25` does not list `Support/`.
Impact: the hot-path read rule (ADR 0020) has no folder to point at; reviewers cannot say "that is not an Action"
from the path; the next module copies whichever shape its author saw last.
Fix: §3.2 rules; S3 per-module moves into `Queries/`, `Adapters/`, `Settings/`; S5 splits the 16 multi-verb Actions
(the only non-mechanical PRs in the plan); arch rules 1-5.

**H3. Service providers are manifests written as code.**
Evidence: `BillingServiceProvider.php` 640 lines, already split into private `register*` methods (236-640); Domains
582; Services 433; Support 401.
Impact: the file every PR in a module touches, so the one that conflicts in every parallel wave (the operating model
runs up to seven agents on one codebase); and the data ADR 0030's manifest and a future "what does this module
register" page need is buried in private methods. The shape is right; the unit is wrong.
Fix: S4, promote each private `register*` method to a Boot class; move the merge-field resolvers to a Query; a snapshot
test that the registries' contents are identical before and after.

**H4. Tests named after PRs, folders named after review rounds, fakes duplicated.**
Evidence: §1.6; 130 files; `Feature/Security/{Review1029,Review1031,Rr536}`; `scripts/test.sh:117-119`.
Impact: a failing `Pr253Recheck3ProbesTest` names a PR nobody remembers; the security folder is a journal, not a
suite; the test selection script keeps a hand-written exception list.
Fix: S1, one PR per module, `git mv` only; arch rules 10-11; a line in `review-rules.md` that an adopted probe is
renamed by behaviour in the adopting PR.

### Medium

**M1. Core versus Platform: two owners for Core's admin screens.**
Evidence: §1.3; 10 of 16 Platform admin controllers edit Core models; Core keeps 5 controllers; pages `admin/clients`
versus `admin/platform/client-groups`.
Impact: "where does the Currency edit page live" has two answers; Platform cannot be absent in any real install, so
its module boundary buys nothing and costs 143 imports.
Fix: owner decision (§7): (a) merge Platform into Core (recommended: one module, one owner, no cross-imports; migration
block 90xxxx stays reserved to Core), or (b) Core keeps no HTTP layer and Platform is its UI. Either is one mechanical
PR (S8).

**M2. Pages and components do not fully mirror the modules.**
Evidence: §1.5; six path mismatches; 33 flat components; 15 pages over 800 lines.
Fix: S6; arch rule 8; the 600-line page rule applied to new pages and to the three largest when next touched.

**M3. The documented tree is stale and `architecture.md` is becoming everything.**
Evidence: 12 documented folder kinds versus 25 in use; `Policies/` documented and unused; 60 lines on migration locks
(`architecture.md:307-371`) in the conventions file.
Fix: S0 rewrites the tree and the building-block table (§3.1-3.2) and drops Policies; later (L3) the file splits into
`docs/architecture/{modules,kernel,database,frontend,testing}.md` with `architecture.md` as the index and the rules
that are enforced.

**M4. Starter-kit auth lives outside every module and imports Core.**
Evidence: §1.3; `app/Http/Controllers/Settings/ProfileController.php:9`, `FortifyServiceProvider.php:17-18`.
Fix: owner decision (§7) on an `Auth` module (`app/Modules/Auth`, pages `auth/*` and `account/*`, the Fortify actions,
step-up, sessions, passkeys, `User` stays in `app/Models` until ADR 0031 decides). After S2 and ADR 0031; S7.

**M5. Hosts inside `Drivers/`, Laravel notifications under `Notifications/`.**
Evidence: `Billing/Drivers/Gateways/GatewayManager.php`; `Platform/Notifications/*`, `Core/Notifications/*`.
Fix: ADR 0030 M3 moves `GatewayManager` when it builds `GatewayDriverRegistry`; S3 moves the notification classes to
`Mail/`. Small.

**M6. The kernel has no test folder.**
Evidence: §1.6; `Feature/Core` and `Unit/Support` carry kernel tests under module names.
Fix: S1 creates `tests/Kernel` and `tests/Tooling`; `scripts/test.sh` maps `app/Support|Contracts|Data|Events|Enums`
to `tests/Kernel` instead of "the whole suite".

### Low

**L1. Billing is 2.4× the next module** (231 files; gateways host, invoices, quotes, credit, tax notes, registers,
refunds, pay methods). A `Payments` module (attempts, transactions, refunds, callback handler, reconciler: roughly 60
files) would be the natural cut, with `Invoicer` and `CreditLedger` already kernel contracts. Not now: ADR 0030 M1-M3
already churn the gateway side; revisit after M3 lands, and only if Billing passes 300 files.

**L2. Fallback pattern has two spellings** (`Null*` in Core; `available()` adapters in Services). Keep both:
`Null*` for contracts with a sensible no-op, adapters for consumers that branch on absence. Document in S0.

**L3. Split `architecture.md`** into an index plus topic pages (M3 second half). Docs only, any time.

**L4. `tests/Unit/{Deploy,Scripts}`** test repository scripts from inside the app suite; they run on every PR for a
reason (the deploy gate), so they stay, under `tests/Tooling`.

**L5. TypeScript types from PHP.** Open ground among peers; a dependency decision. After Resources are the only prop
source.

### Not worth changing

- **Renaming the `Support` or `Services` modules.** `support.*` permissions, `support_*` tables, `services.*`
  settings, routes and 200+ pages would change for a naming nicety. The fix is to make the *other* meanings
  unambiguous (§3.2: per-module `Support/` becomes pure helpers; kernel tests move to `tests/Kernel`).
- **Adopting Policies.** String permissions with `$this->authorize()` work and are registered; delete the folder from
  the doc.
- **Packages per domain (Lunar) or `nwidart/laravel-modules` (Akaunting, Invoice Ninja).** Mohar's auto-discovered
  `app/Modules` with arch tests is simpler and better tested than either; a package layout would add Composer
  plumbing for no boundary the tests do not already enforce. ADR 0030 uses Composer only where it earns its place
  (third-party extensions).
- **Repositories, transformers, presenters** (Invoice Ninja). Mohar's Resource + Query split is enough; the extra
  layers are what make Invoice Ninja's entities 1,000 lines each.
- **The five starter migrations in `database/migrations`** (users, cache, jobs, passkeys, 2FA). Framework tables;
  leave them.
- **Moving tests into module folders** (FOSSBilling). Pest sharding and `scripts/test.sh` work on `tests/<Suite>/<Module>`;
  the mirror rule gives the same locality.

## 6. Migration plan

Small PRs, mechanical first, no behaviour change unless marked. Coordinated with ADR 0030's M1-M8 and the audit's
fix PRs (#569): nothing here moves a file that M1-M3 will move, and S2 precedes ADR 0031's contract work.

| PR  | Title                                                                                | Scope                                                                                                                                                                                                                                                                   | Behaviour | Depends on              | Size |
| --- | ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------------- | ---- |
| S0  | `docs(architecture): module building blocks and folder rules`                        | §3.1 tree and §3.2 table into `architecture.md`; drop `Policies/`; name `Null*` and adapter fallbacks; the review-rules line on renaming adopted probes; the allowed-folders arch rule (6) with today's set as the baseline.                                             | none      | —                       | S    |
| S1  | `test: mirror the module layout` (one PR per module, Billing first)                  | `git mv` PR-named files to behaviour names in the module folder; dissolve `Security/Review*`, `Rr536`, `ReviewProbes`, `Review`; create `tests/Kernel` and `tests/Tooling`; dedupe `tests/Fakes` by contract area; `scripts/test.sh` mapping; arch rules 10-11 with the shrinking baseline. | none      | audit PR 6 (#558 lands) | S ×8 |
| S2  | `refactor(kernel): Core infrastructure moves to app/Support` (CTO session)           | `OnlineIndex`, `LockTimeout`, `Like`, `ChunkedDelete`, `RequestMemo`, `DateFormats`, `ConstantRows` → `Support/Database`; `MigrationLint`, `KernelApiSnapshot` → `Support/Tooling`; 17 `Null*` → `Support/<Area>` bound in `AppServiceProvider`; imports updated (~120 files); kernel fragment and snapshot; arch rule 13 after. | none      | S0                      | M    |
| S3  | `refactor(<module>): Queries, Adapters, Settings out of Support` (Billing, Services, Domains, Orders, Platform, Notifications) | `git mv` plus namespace; notification classes → `Mail/`; `Billing/Support/AfterCommit` left for audit PR 5; arch rules 3-5 with baselines.                                                                                                                                | none      | S0; not during ADR 0030 M1 on the same module | S ×6 |
| S4  | `refactor(<module>): Boot classes` (Billing, Domains, Services, Support, Platform, Orders) | `bootModule()` split per registry; a registry snapshot test (permission keys, setting keys, nav items, webhook keys, report keys, automation task keys) asserted equal before and after; arch rule 12.                                                                 | none      | S0                      | S ×6 |
| S5  | `refactor(<module>): one verb per Action` (Billing ×3, Services ×2, Catalog, Domains, Support, Migration) | Split the 16 multi-verb Actions: the verbs become Actions or a Service; callers updated; existing tests cover each verb; arch rules 1-2 with a baseline that reaches zero.                                                                                              | none, but reviewed as code | S3 | S ×9 |
| S6  | `refactor(frontend): pages mirror modules, no flat components`                        | `admin/promotions` → `admin/orders/promotions`; `admin/settings/*` → `admin/themes/*`; `pages/settings` → `pages/account`; flat `components/*` → `app/` or `auth/`; `Inertia::render` strings and tests updated; arch rule 8.                                           | URLs unchanged (paths are page names, not routes) | S0 | M |
| S7  | `refactor(auth): Auth module` (owner decision)                                        | `app/Actions/Fortify`, `app/Http/Controllers/{Auth,Settings}`, requests, `app/Concerns`, `app/Notifications`, `routes/settings.php` → `app/Modules/Auth`; `User` stays.                                                                                                | none      | S2, ADR 0031            | M    |
| S8  | `refactor(core): one owner for platform screens` (owner decision)                     | (a) merge Platform into Core, or (b) move Core's 5 controllers and pages to Platform; migration blocks unchanged.                                                                                                                                                       | none      | S3                      | M    |
| L1  | `refactor(billing): Payments module`                                                  | Only after ADR 0030 M3 and only if Billing passes 300 files.                                                                                                                                                                                                            | none      | M3                      | L    |
| L3  | `docs(architecture): split architecture.md`                                           | Index plus topic pages.                                                                                                                                                                                                                                                | none      | S0                      | S    |
| L5  | `chore(frontend): TypeScript types from Resources`                                    | Dependency decision (ADR 0007), after audit PR 9.                                                                                                                                                                                                                      | none      | audit PR 9              | M    |

Ordering: S0 first (one day). S1 and S4 can run in parallel with ADR 0030 M1 because they do not touch the gateway
modules' files that M1 moves (S1 skips `tests/Feature/Payments*` until M1 lands). S2 is a CTO-session kernel PR and
should land before ADR 0031's contract PR so that ADR 0031 versions only what is left in Core. S3 runs module by
module in quiet windows, never on a module with an open feature PR. S5 is the only wave that changes code shape
rather than location; it goes through the normal review board. S6 is one PR, best right after a UI wave merges.

## 7. Owner decisions needed

1. **Platform and Core (M1, S8):** merge Platform into Core (recommended), or make Platform the only HTTP layer for
   Core's models.
2. **An `Auth` module (M4, S7):** extract the starter-kit auth and staff self-service into `app/Modules/Auth`, or keep
   it as a named kernel area `app/Auth` outside the module rules.
3. **Test renaming policy (H4, S1):** confirm that adopted probes are renamed by behaviour in the adopting PR and that
   the 130 existing files are renamed in test-only PRs (no code changes), so the history stays readable through
   `git log --follow`.
4. **TypeScript generation (L5):** whether to add `spatie/typescript-transformer` under ADR 0007 once Resources are
   the only prop source.

## Not verified

- No command ran against the code: no tests, PHPStan, build, or Wayfinder generation. Every count is a `find`/`grep`
  over `develop` at `a5b803bf`; the patterns are given where a number depends on them, and the audit's 47 Core classes
  versus my 81 shows how much the pattern matters.
- The multi-verb Action count (16) uses "more than two `public function` lines"; a class with `__construct`,
  `handle` and one `public static` helper is included.
- Peer repositories were read at the commits listed in §2 by three subagents and summarised to me; I did not open
  every file they cite. developers.whmcs.com pages beyond the module-type index were partly unreachable; the WHMCS
  notes rest on the sample modules.
- The Boot-class and Queries moves were not prototyped; the registry snapshot test in S4 is the proposed proof that
  they are behaviour-free.
- The Billing split (L1) was sized by folder reading, not by a dependency graph of the ~60 payment files.
