# Architecture & Engineering Conventions

Read this before writing code. It is the contract between modules and between engineers.
Product behaviour lives in `docs/product/<module>.md`; UI rules in `docs/design/design-system.md`.

## Stack

Laravel 13 · PHP 8.4 · Inertia 3 + React 19 + TypeScript · Tailwind 4 + shadcn/ui (Radix) · Wayfinder ·
Fortify auth (2FA, passkeys) · Pest 5 · Larastan level 7 · Pint · SQLite (dev/test; migrations must be
portable to PostgreSQL/MySQL) · RustFS (S3-compatible object storage, `objects` disk) · Laravel queues & scheduler.

## Modular monolith

```
app/
  Contracts/<Area>/        interfaces other modules depend on (Invoicer, PriceBook, ServiceRegistry, ...)
  Data/<Area>/             readonly DTOs passed through contracts
  Events/<Area>/           domain events (scalar payloads, ids not models)
  Enums/                   shared enums (BillingCycle, BillableType)
  Support/                 Money, Modules base provider, Navigation, Dashboard registries
  Http/Middleware/         kernel-owned, module-agnostic middleware (password.recent), aliased in bootstrap/app.php
  Modules/<Module>/
    <Module>ServiceProvider.php   extends App\Support\Modules\ModuleServiceProvider
    Config/<module>.php           defaults, merged as config('<module>.*')
    Models/  Actions/  Services/  Drivers/  Jobs/  Listeners/  Policies/
    Console/Commands/             auto-registered artisan commands (`<module>:<action>`; Core uses its area)
    Install/                      InstallsReferenceData implementations (reference data, every environment)
    Database/Migrations|Factories|Seeders/<Module>Seeder.php (demo data; DatabaseSeeder runs it outside production)
    Http/Controllers/{Admin,Client,Public}/  Http/Requests/  Http/Resources/
    routes/web.php                auto-loaded in the "web" middleware group
resources/js/pages/{admin,client,public}/<module>/...   Inertia pages
tests/Feature/<Module>/  tests/Unit/<Module>/            Pest tests
```

Modules are discovered automatically (`App\Providers\ModulesServiceProvider`). `MODULES=Billing php artisan test`
loads only Core + Billing.

### Dependency rules (enforced in review)

1. A module may import: its own namespace, `App\Modules\Core\*`, `App\Contracts|Data|Events|Enums|Support\*`,
   `App\Http\Middleware\*` (kernel-owned HTTP middleware), `App\Models\User`, framework/vendor code.
   Middleware home: framework-level, module-agnostic middleware goes in `App\Http\Middleware`; middleware that
   needs Core models or services goes in `App\Modules\Core\Http\Middleware`.
   Kernel `App\Support` services may use Core services (`ActivityLogger`, `Settings`), because Core always loads
   (`ReportExporter` does, ADR 0025).
2. A module must NOT import another module's classes (models, services, controllers). Cross-module calls go
   through a contract in `app/Contracts`; cross-module reactions go through events in `app/Events`.
3. Exceptions: **Reports** (read model) may read other modules' tables via their models.
4. Enforced by Pest architecture tests (`tests/Arch/ArchitectureTest.php`, run in CI): sibling-module imports,
   kernel → module imports, non-interface contracts, non-final DTOs/events, Eloquent models in DTOs/events, debug
   calls, and `DB` facade use in controllers all fail the build. The module list is read from `app/Modules`. (Pest only resolves existing classes, so violations surface once branches meet in `develop`.)
   Each rule has exactly one `expect()` target: in pest-plugin-arch 5.0, `expect([A, B])->not->toUse(X)` passes as
   soon as one target does not use `X`, so a violation in any other target goes unreported (issue #275, upstream
   pestphp/pest#1751). Write a `foreach` with one `arch()` per namespace instead. Each file in `tests/Arch` also
   checks that its target namespaces match at least one class, so a typo or a moved directory cannot pass silently.
   Targets may be single classes: list them in that guard too (`archTargetsWithoutClasses()`), because
   `expect(Foo::class)->toBeFinal()` over a renamed class passes without checking anything. The same one-target rule
   holds for `not->toBeUsedIn()`, which negates a check that stops at the first clean target (#277).
5. Need a new contract/event/DTO? Ask the CTO — shared kernel changes are reviewed centrally.
6. Each module binds its contract implementations in `registerModule()`. In tests of a consuming module,
   bind a fake (`$this->app->instance(PriceBook::class, new FakePriceBook)`), never the real sibling.

### Contracts (implementer → consumers)

| Contract                                                            | Implemented by         | Used by                                                       |
| ------------------------------------------------------------------- | ---------------------- | ------------------------------------------------------------- |
| `Billing\Invoicer`, `Billing\TaxCalculator`, `Billing\CreditLedger` | Billing                | Orders, Services, Domains, Affiliates                         |
| `Catalog\PriceBook`                                                 | Catalog                | Orders, Services                                              |
| `Services\ServiceRegistry`                                          | Services               | Orders                                                        |
| `Domains\DomainRegistry`, `Domains\DomainPricing`                   | Domains                | Orders                                                        |
| `Domains\TldAttributes`                                             | Domains                | Orders                                                        |
| `Notifications\MergeFieldRegistry`                                  | Core (in-memory)       | every module registers resolvers; Notifications reads         |
| `Branding\BrandProvider` (→ `Data\Branding\Brand`)                  | Themes (Core fallback) | Inertia shared props, mail layout, Billing invoice PDF header |
| `Billing\BillingDocuments` (→ `Data\Billing\DocumentFile`)          | Billing                | Notifications (email attachments)                             |

Email documents (#482): the record's owner supplies the document (`BillingDocuments::document($type, $id, $locale)`,
`$type` a merge-field entity key); Notifications decides per template whether a message carries it (`attaches_document`
in `Resources/templates.php`) and asks at send time with the message's related record and locale.

### Integration drivers (ADR 0009, 0012)

Settings, connection checks, logos and log redaction are shared by every driver family:

- `Data\Integrations\SettingField` with `Enums\FieldType` is the one settings-field schema. A secret is a type
  (`Secret`, `SecretText`, `Certificate`), not a flag. A `Certificate` is a .pfx/.p12 upload read in memory by
  `Support\Integrations\CertificateBundle`; the host keeps the PEM key as the secret, `CertificateInfo` beside it, and
  expiry bookkeeping in Core's `integration_credentials` (ADR 0009 Amendment (#489); only the gateway host takes one
  so far, the registrar and provisioning registries refuse it). Gateways still return arrays, which Billing reads through
  `SettingField::fromArray()` until the gateway family is aligned. Every field list sent to the browser is
  `SettingField::toArray()`, rendered by `resources/js/components/app/setting-field.tsx`.
- `Data\Integrations\ConnectionCheck` is the result of any credential test.
- `Contracts\Integrations\ProvidesLogo` (returning `Data\Integrations\IntegrationLogo`) lets any driver ship a logo.
- `Support\Integrations\Redactor` masks secrets in the gateway, module and registrar logs (one rule, matched by
  word). The host calls it on what drivers return; drivers never call it. `excerpt()` makes raw provider output safe
  to show staff (redacted, 4 KB). Text (headers, query strings, form bodies, JSON or XML after a label) is masked in
  one linear pass. The rule is that anything storing or logging provider text, error messages included, goes
  through it; the redesign is ADR 0023. It fails closed: an unterminated quote, element or CDATA section masks to the
  end of the text, a branch past the depth cap or the object budget is masked, and a pattern that fails withholds the
  whole string. Every logger masks its records (a backstop, never a replacement for the host's own masking):
  `log` is `Support\Logging\RedactingLogManager`, which taps every channel it creates with `RedactLogs`, the
  emergency logger and `MessageLogged` included. A host runs each driver call in
  `Support\Integrations\RedactionScope::during()` so log lines and exceptions from it are masked by the call's values
  too (Billing's gateway calls go through `GatewayManager::during()`; Domains and Services follow the host pattern in
  ADR 0023, amendment #462), and `failed_jobs.exception` is masked before it is stored.
- `Support\Integrations\OutboundAddressGuard` checks every connection to a host a person typed in (SSRF, ADR 0015):
  resolve once, refuse non-public addresses under the install policy in `config/integrations.php`, pin the request to
  the checked IP and port (`CURLOPT_CONNECT_TO`), bypass proxies (`proxy: ''`), follow no redirects. Drivers call it before they connect and never choose the
  policy.

Driver registries are kernel classes that extend `Support\Integrations\DriverRegistry`. They are bound as singletons in
`AppServiceProvider`, so a package registers a driver in its own `boot()` whether or not the consuming module is
loaded. Registration is `register(key, class-string|Closure, replace: false)`, resolution is lazy, and the capability
lists the UI shows are computed from the interfaces a driver implements. Kernel API changes are listed in
`docs/architecture/kernel-changelog.md`, through a fragment in `docs/architecture/kernel-changelog.d/`.

Provisioning drivers implement `Contracts\Services\Provisioning\ProvisioningDriver` plus capability interfaces,
receive `ProvisioningContext` snapshots (never models), and register with `Support\Services\ProvisioningDriverRegistry`.
Every driver passes `tests/Support/Contracts/ProvisioningDriverContract.php`. Tests that prove a package works without
sibling modules live in `tests/Extensions` and set `Tests\TestCase::$onlyModules`; they never touch the database.

### Billing flow (the backbone)

```
Orders checkout ─► PriceBook quote ─► ServiceRegistry/DomainRegistry createPending ─► Invoicer::issue
                                                                                         │
Gateway payment ─► Billing applies payment ─► InvoicePaid(lines[]) ─┬─► Services: activate/renew (BillableType::Service*)
                                                                    ├─► Domains: register/renew (BillableType::Domain)
                                                                    ├─► Affiliates: commission
                                                                    └─► Notifications: receipt email
Daily scheduler: Services/Domains create renewal invoices via Invoicer (idempotent: hasOpenInvoiceFor)
                 Billing sends reminders, late fees, marks overdue → InvoiceOverdue → Services suspend after N days
```

`InvoiceLineData` carries `billableType` + `billableId` (+ period); modules only react to types they own.

## Conventions

**Money** — integers in minor units (`*_amount`, `total`, `subtotal` columns as `bigInteger`), always with a
`currency` char(3) on the owning record. Use `App\Support\Money` for arithmetic; never floats.
Display: every amount people read goes through the currency's configured display (Admin → Currencies:
prefix, suffix, number format incl. Indian lakh grouping) — `App\Support\Currencies\MoneyFormatter` on the server
(dashboards, list meta, merge fields, mail, PDFs) and `formatMoney` / `<Money>` in the browser, which read the same
config from the shared `currencyFormats` prop. `Money::format()` ("24.99 USD") is for logs, audit and CLI only. Show an
ISO code only where it identifies a currency (switchers, selects, settings, CSV columns, API, logs), not next to amounts.

**Dates** — `CarbonImmutable` for instants (`Date::use` is set). A company-calendar date in code is
`App\Support\CalendarDate` (ADR 0013 Amendment (#410)): `CompanyClock::calendarDate()` gives today's, `CalendarDate::parse()`
reads `Y-m-d`, and `CompanyClock::dayBounds()` is the only way to its instants. Store dates (`date`) for billing anchors
(`next_due_date`), timestamps for events. Advance cycles with `BillingCycle::advance($date, anchorDay: $model->billing_anchor_day)` — store the
original day-of-month as an anchor on recurring items to avoid drift. Query `date` columns with
`whereDate()` (Eloquent stores date casts as `Y-m-d H:i:s`; exact matches break across databases).

**Automation** — daily jobs are tasks in the central pipeline, never ad-hoc scheduler entries:
`app(Automation::class)->task('services.suspensions', Automation::SUSPENSIONS, fn (CalendarDate $today) => $count)`.
Stages run in order EXCHANGE_RATES → RENEWALS → LATE_FEES → AUTO_CHARGE → REMINDERS → CANCELLATIONS → SUSPENSIONS →
TERMINATIONS → SYNC → HOUSEKEEPING via `php artisan automation:run [--date=] [--only=]`; each task must be
idempotent for its run date (recorded in `automation_runs`; dates older than the automation log retention, or already pruned, are refused, and
retention tasks cap the run date at today through `RetentionRunDate`: ADR 0013 Amendment (#460)). Renewal invoicing must call
`Invoicer::hasInvoiceForPeriod()` before issuing.

**Statuses** — PHP backed enums in the module (`Enums/InvoiceStatus.php`, TitleCase cases, snake values);
state changes happen in Actions/Services, not controllers, and dispatch the matching event.

**Business logic** — Controllers are thin: validate (FormRequest) → call an Action/Service → redirect/render.
Actions are single-purpose classes (`Actions/ApplyPayment.php` with `handle()`), reused by controllers,
jobs, commands and other actions. Wrap multi-write operations in `DB::transaction`. Make automation idempotent.
Kernel exceptions (`App\Support\*`) know nothing about HTTP: the module that throws them to users renders them
through a renderer it registers (`callAfterResolving(ExceptionHandler::class, …)->renderable(…)`, as Domains does for
`RegistrarException`), never with a `render()` method on the kernel class.

**Settings** — `app(Settings::class)->get('billing.invoice_prefix')` → DB override, else `config()` default from
the module's `Config/<module>.php`. Every tunable number in a spec becomes a setting with a default.

**Authorization** — staff permissions are strings `module.area.action`, e.g. `billing.invoices.view`,
`billing.invoices.manage`; roles grant them with `*` wildcards (`billing.*`). Use `$this->authorize('...')`
in admin controllers or `->can('...')` route middleware. Client area: inject `ClientContext`, call
`$context->authorize('billing')` (areas: billing, services, domains, support, profile, contacts) and ALWAYS
scope queries to `$context->id()` — never trust ids from the request without scoping (IDOR tests required).

**Routes** — in `routes/web.php` of the module:

```php
Route::adminArea(fn () => Route::prefix('billing')->name('billing.')->group(...));   // /admin/billing/..., admin.billing.*
Route::clientArea(fn () => Route::prefix('invoices')->name('invoices.')->group(...)); // /client/invoices/..., client.invoices.*
// public (no auth): Route::prefix('store')->name('store.')->group(...)
// webhooks/callbacks: Route::post('webhooks/...')->withoutMiddleware(PreventRequestForgery::class)
```

**Navigation & dashboard** — register in `bootModule()`:

```php
app(Navigation::class)->add('admin', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', 'Billing', 10, 'billing.invoices.view', 'admin.billing.invoices.*'));
app(Navigation::class)->add('client', new NavItem('Invoices', 'client.invoices.index', 'Receipt', 'Billing', 10));
app(Navigation::class)->add('admin-client', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', order: 10, permission: 'billing.invoices.view')); // link on client profile (?client=ID)
app(Navigation::class)->add('admin-create', new NavItem('New invoice', 'admin.billing.invoices.create', 'FileText', permission: 'billing.invoices.manage')); // quick-create menu
// Sidebar count badge (cheap indexed count): new NavItem(..., badge: fn ($user) => Ticket::awaitingStaff()->count())
app(Dashboard::class)->card('admin', 'billing.overdue', fn ($user) => ['label' => ..., 'value' => ..., 'href' => ..., 'icon' => 'Receipt'], 'billing.invoices.view');
app(Dashboard::class)->widget('admin', 'billing.income', 'chart', fn ($user) => [...], 'billing.invoices.view', size: 'md', deferred: true); // list|chart widgets
app(Dashboard::class)->todo('admin', 'billing.overdue', fn ($user) => ['label' => 'Overdue invoices', 'count' => 3, 'href' => ..., 'tone' => 'danger'], 'billing.invoices.view'); // "Needs attention"
```

Icon names must exist in `resources/js/lib/nav-icons.ts` (add one line if needed).

**Permissions, settings, search** — also in `bootModule()` (all registries live in `app/Support`):

```php
app(PermissionRegistry::class)->register('Billing', ['billing.invoices.view' => 'View invoices', 'billing.invoices.manage' => 'Manage invoices']);
app(SettingsRegistry::class)->section('billing', 'Billing', [new SettingDefinition('billing.invoice_prefix', 'Invoice prefix'), ...], 'billing.settings.manage');
app(GlobalSearch::class)->provider('Invoices', fn (string $term) => [...['title','subtitle','href']], 'billing.invoices.view');
```

Every permission you check must be registered; every tunable setting must be registered with a definition.

**Reference data and demo data** — rows the app cannot run without (currencies, system roles, later the default
email templates) are reference data: an `App\Contracts\Install\InstallsReferenceData` in the module's `Install/`,
registered in `bootModule()`:

```php
app(ReferenceDataRegistry::class)->register('notifications_templates', DefaultTemplates::class, order: 100); // Core uses 10–20
```

`php artisan app:install` (production install, `docs/devops/install.md`) and `DatabaseSeeder` both run every
registered installer in order, each in a transaction, so the demo and production never drift. `install()` is
idempotent (a second run creates and updates nothing) and returns a `ReferenceDataResult`. Rows the operator owns
afterwards (currencies, template wording) are created on the first install only, so a deleted one never comes back.
Rows the system owns (system roles) are found by a stable key (`roles.key`), never by a name staff can change, and are
reset to their definition with an audit entry. No users, credentials, demo rows or network calls. The contract is a
provider contract (ADR 0010): it never gains a method.
Everything else a module seeds (demo accounts, products, invoices, tickets) goes in its `<Module>Seeder`, which
extends `DemoSeeder` and so refuses to run in production. Settings, the automation time and theme presets are
defaults in code and config, not rows. Commands are named `<module>:<action>` (`billing:reconcile-payments`); Core and
cross-cutting commands use their area instead (`automation:run`, `objects:ensure-bucket`, and the brand-neutral
`app:install`).

**Themes & brand** — the Themes module renders the active theme's CSS variables into the root view
(schema: `App\Support\Themes\ThemeSchema`, "billing-theme" v1). Theme packs add presets with
`app(ThemeRegistry::class)->register(ThemePreset::fromDocument($json))`; presets are validated on
registration. Pages/emails/PDFs get the brand from `app(BrandProvider::class)->brand()` (name, tagline,
logo/mark URLs) or `->logoDataUri()` for PDFs — never hard-code the product name. Uploaded logos,
mark and favicon are stored on the private `objects` disk (shared across app servers) and served
by `GET /branding/{kind}` — registered outside the `web` group (no session/cookies), immutable only
for the current `?v=<content hash>`, otherwise a short-cached redirect to it. SVG uploads are
rejected (no sanitiser); the shipped defaults in `public/brand/` are SVG. The brand name is Core's
`core.company_name` (Settings → Company, `core.settings.update`, default `config('app.name')`);
Appearance shows it read-only. Every Themes change is written to the activity log.
Groups: General, Clients, Orders, Billing, Services, Domains, Support, Marketing, Reports, Setup.

**Merge fields** — register `app(MergeFieldRegistry::class)->register('invoice', fn (int $id) => [...])`
for every entity your events reference; include `client_id`.

**Inertia** — `Inertia::render('admin/billing/invoices/index', [...])`; props via API Resources (`Http/Resources`,
wrapping disabled) or explicit arrays — never whole models with hidden relations. Paginate with
`->paginate(25)->withQueryString()`. Toasts: `Inertia::flash('toast', ['type' => 'success', 'message' => __('...')])`.
Frontend uses Wayfinder imports (`@/actions/App/Modules/...`), shared components from `@/components/app/*`,
shadcn primitives from `@/components/ui/*`. Run `php artisan wayfinder:generate --with-form` after route changes.

**Files** — business files go to `Storage::disk('objects')` (RustFS/S3) with private visibility, served via
authorized controller downloads or temporary URLs. Tests: `Storage::fake('objects')`.

**Queues & scheduler** — provisioning/registrar/gateway/webhook calls run in queued jobs with `$tries` and
backoff; automation registered in the provider's `schedule()` with `->withoutOverlapping()`. Queue names come from
`App\Support\Queues`. A job's `$timeout` stays below its connection's `retry_after`, or a second worker starts it
while the first still runs it; work that can run longer gets its own connection with a larger `retry_after`, as
`RunAutomationJob` does with `Queues::AUTOMATION`.

**Database** — portable migrations (no SQLite/MySQL-only types), foreign keys with explicit on-delete,
indexes on foreign keys and status/date columns used by automation. Migration filenames:
`2026_10_17_<module-prefix-number>_...` so modules don't collide (Core 000100–099999, Catalog 1xxxxx, Orders 2xxxxx,
Billing 3xxxxx, Services 4xxxxx, Domains 5xxxxx, Support 6xxxxx, Themes 70xxxx, Storefront 72xxxx, PaymentsNepal 75xxxx, Kyc 77xxxx,
Notifications 80xxxx, Affiliates 85xxxx, Platform 90xxxx, AI 95xxxx, Migration 98xxxx). New modules take a free
block and add it here in the same PR.
Models use `#[Fillable]`, `#[UseFactory(XFactory::class)]`, `casts()` method, PHPDoc `@property` blocks.

PostgreSQL portability (CI runs every suite that touches the database on SQLite **and** PostgreSQL; the
database-free Unit and Arch suites run on SQLite only — ADR 0006, `docs/devops/ci.md`):

1. User search: `whereLike($column, "%{$term}%")` (case-insensitive), never raw `like`.
2. `sum()`/`avg()` return strings on PostgreSQL — cast `(int)` before `Money`.
3. Never hardcode ids in tests or links; sequences are not reset between tests.
4. `orderBy(...)->orderBy('id')` wherever order matters.
5. Strict `GROUP BY`; no SQLite-only functions (`strftime`, `ifnull`, `julianday`); no backticks.
6. Validate string lengths against column sizes (PostgreSQL rejects overlong values).
7. A failed statement aborts the transaction — use `upsert`/`firstOrCreate`/`lockForUpdate`, never catch `QueryException` to retry.
   Row locks, the lock order and the two-process race test: ADR 0021.
8. Lowercase emails (and domain names, promo codes) on write; unique indexes are case-sensitive.
9. Booleans as `where('flag', true)`; no explicit ids in seeders.
10. Dashboard and report figures are summed in SQL (one row per currency or group; a conditional `sum(case …)` per
    series point); never read unbounded rows into PHP to total them. `DashboardQueryCountTest` enforces it.
11. A contains search (`whereLike`, `Like::where`) on a table that grows with clients needs a trigram index on exactly
    the searched expression of every OR'd column (`OnlineIndex::trigram()`), or PostgreSQL scans the table.
    `SearchIndexesTest` checks the admin searches.

### Feature flags (unfinished work)

- `config/features.php` holds one key per unfinished feature, `features.<module>.<flag>`, env `FEATURE_<MODULE>_<FLAG>`, off by
  default. It is config only, never a stored setting, and outside every module's own config tree.
- Gate a route with the `feature:<module>.<flag>` middleware, first on the route, so nothing else runs when it is off; gate a
  menu item with the same config key. A flag hides the screen; it does not undo what the feature already wrote or switch a
  stored mode off.
- The PR that finishes the work deletes the flag and every check of it. `config/billing_sandbox.php` is a permanent
  deployment switch, not a feature flag.

### Migrations during blue-green deploys (expand, then contract)

Production deploys are blue-green (`deploy/bin/deploy`, `docs/devops/production.md`): the new release's
migrations run first, then the new version starts beside the old one, and traffic moves only when it is healthy.
The old version keeps serving, and stays ready for a rollback, against the **new** schema. So every release's
migrations must be ones the previous release can run against:

1. **Expand only** in the release that needs the change: add tables, nullable columns or columns with a default,
   indexes and foreign keys that existing rows satisfy. New code writes both the old and the new shape while it
   matters (a renamed column: write both, read the new one, backfill in a queued job, not in the migration).
2. **Contract later**, in a release after the one where no code reads or writes the old schema: drop the old
   column or table, or tighten a constraint. Tag that migration `@contract` with the release that stopped using
   it.
3. **Never in one step:** drop, rename (`renameColumn`, `Schema::rename`), change a column's type or nullability
   (`->change()`), or raw `DROP`/`RENAME` SQL. An in-place change the previous release demonstrably tolerates
   (widening a `decimal` or `varchar`) is tagged `@compatible` with the reason.
4. **Jobs too:** a queued job's payload must stay readable by both versions, because old workers drain while new
   ones start. Add properties with defaults; remove them a release later.

`php artisan deploy:check-migrations` enforces rules 1 to 3, and 5 and 6 below: `--since=origin/develop` locally
before a pull request, and `--pending` in the new image before every deploy (the deploy script stops on a finding).
It also refuses a migration timestamp that another migration already has (three merged pairs are allow-listed in
`MigrationLint`). It reads every method except `down()` (unless another method calls it), so a drop in a helper or
through `Schema::connection()` is seen, as are raw `ALTER COLUMN … TYPE`, `SET NOT NULL`, `ADD CONSTRAINT`, a unique
index or primary key on an existing table and a foreign key dropped and added again without a
`nullOnDelete`/`cascadeOnDelete` action. A `@contract` or `@compatible` tag needs a reason and excuses the statement
it precedes or sits in; a tag on the class excuses a file that has one such statement. Rules 5 and 6 (DDL, drops and
renames of existing tables outside `LockTimeout::run` for that table, an index outside `OnlineIndex`, `OnlineIndex`
outside `$withinTransaction = false`) cannot be excused. The one exception is `OnlineIndex::create(…, allowLocking:
true)`, which needs a tag with a reason (a small table). SQL or table names built at run time are not seen. The 39
migrations merged before the checker that break these rules are listed in `database/migration-rules-baseline.txt` and
skipped; the list is frozen by a hash in `CheckMigrationsRulesTest`, so never add to it. The test runs the same checks
over every other migration, so a new one that breaks them fails CI (the checker itself is not a CI step).

Locks, for every new migration (the old release keeps serving while it runs):

5. **DDL on an existing table runs under `LockTimeout::run($table, fn () => …)`** (`App\Modules\Core\Support`).
   Any `ALTER TABLE` takes ACCESS EXCLUSIVE, even a metadata-only `ADD COLUMN`. Behind an open transaction on the
   table it would wait in the lock queue, and every later read and write of the table would wait behind it. On
   PostgreSQL the helper sets `lock_timeout` (`DB_MIGRATION_LOCK_TIMEOUT`, default 3s) with `SET LOCAL` in a
   transaction of its own, or a savepoint inside the migration's. It retries 4 times with a 1, 2, 4 s backoff, then
   fails with the table and the sessions in the way. Reads queue for at most one timeout per attempt, not for as
   long as the blocker lives. One table per call, and the closure must be safe to run again (`hasColumn` guards).
   Inside the migration's transaction, locks taken by earlier statements stay held through the backoff, so on a
   large table prefer `public $withinTransaction = false;` with one call per table. On SQLite it just runs the DDL.
   Migrations merged before this rule are not rewritten.
6. **Indexes go through `OnlineIndex`**, which applies rule 5 itself. A CONCURRENTLY build cannot run in a
   transaction, so it sets `lock_timeout` for the session and restores it afterwards. Such a build waits for every
   older transaction in the database, on any table, so it gets `DB_MIGRATION_CONCURRENT_LOCK_TIMEOUT` (default
   60s) and 3 attempts.
    - **INVALID indexes.** A timed-out or failed build leaves an INVALID index. When the last attempt fails, that
      index is dropped (a ready one is maintained on every write, and a unique one rejects rows); if the drop fails,
      the error names it. A later run drops it before building.
    - **Another session's build.** An INVALID index another session is still building is never dropped: the
      progress view `pg_stat_progress_create_index` is read between two reads of `pg_index`, and such a build is
      waited for on its SHARE UPDATE EXCLUSIVE lock (which blocks no reads or writes). Only an index of the same
      table is dropped.
    - **Reuse.** An existing index of the same name is reused only when the table, columns, uniqueness and
      partial-index predicate all match. PostgreSQL compares `pg_get_expr(indpred, indrelid)` with the predicate
      rebuilt on a temporary copy of the table's columns. SQLite compares the `WHERE` of `sqlite_master.sql`,
      ignoring case, spacing, identifier quotes and enclosing parentheses, but never the case of string literals.
      Any difference throws: an index is never silently reused with another `WHERE`. Write predicate columns
      unqualified, or qualified by the table name only.
7. **One migrator at a time.** The deploy's `migrate` service runs `migrate --isolated=1` (a cache lock in
   `cache_locks`, whose own migration runs first), so a second deploy stops instead of migrating beside the first.

## Testing (Pest)

- Feature tests per behaviour in `tests/Feature/<Module>/`; unit tests for pure calculation (proration, tax).
- Helpers in `tests/Pest.php`: `actingAsStaff(['billing.*'])`, `actingAsClient()` → `[$user, $client]`.
- Each acceptance criterion in the product spec → at least one test. Always cover: authorization (staff
  permission + client scoping/IDOR), validation, the happy path, idempotency of automation, events dispatched
  (`Event::fake([...])`), money edge cases (rounding, zero, negative), `assertInertia` component & props.
- Use factories with states; `CarbonImmutable::setTestNow()` / `$this->travelTo()` for time-based automation.
- Tests must be deterministic and fast: no network, `Http::fake()`, `Storage::fake('objects')`, `Queue::fake()` where relevant.

## Quality gates (every branch, before review)

```bash
vendor/bin/pint --parallel                       # format
vendor/bin/phpstan analyse --memory-limit=1G     # Larastan level 7, zero errors, no ignores/baselines
php artisan test --compact                       # all tests green (whole suite, not just yours)
composer test:pgsql                              # same suite on PostgreSQL (docker compose up -d postgres)
php artisan wayfinder:generate --with-form && npx tsc --noEmit && npm run check
npm run build                                    # pages compile
composer deps:check                              # lockfiles, audits, 7-day dependency cooldown
```

Dependencies: exact pins, lockfiles as source of truth, nothing younger than 7 days, no new direct dependency
without CTO approval — `docs/devops/dependency-policy.md` (ADR 0007). CI design: `docs/devops/ci.md`.

## Git workflow

- Integration branch: `develop` (`main` receives tagged releases). One branch per workstream: `feature/<module>`
  in its own worktree (`scripts/new-worktree.sh <module>`); Conventional Commits, small focused commits.
- Before review: merge latest `develop`, all gates green. Changelog entries go in a fragment,
  `changelog.d/<branch-slug>.md`, never in `changelog.md` (CI fails a direct edit); a kernel API change also adds
  `docs/architecture/kernel-changelog.d/<branch-slug>.md`. The CTO assembles them with `scripts/changelog-assemble.sh`
  and maintains `docs/progress.md` (ADR 0027).
- ADR amendments are keyed by the PR that writes them: `## Amendment (#<pr>) — <date>, <title>`, cited as
  "ADR 0009 Amendment (#368)". Amendments numbered before ADR 0027 keep their numbers.
- Every branch lands through a GitHub pull request into `develop` (`gh pr create --base develop`, template in
  `.github/pull_request_template.md`). Review gates, posted on the PR as review comments: Staff Engineer review →
  QA (runs the suite, adds missing tests) → AI-slop review → fixes by the author → CTO merge with a squash
  (`gh pr merge --squash`; a merge commit when another open PR is based on the branch). Findings are labelled **blocking** or **non-blocking**; blocking ones must be fixed first.
- Never commit `.env`, `vendor/`, `node_modules/`, generated Wayfinder files, or `database/*.sqlite`.
