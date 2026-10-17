# Architecture & Engineering Conventions

Read this before writing code. It is the contract between modules and between engineers.
Product behaviour lives in `docs/product/<module>.md`; UI rules in `docs/design/DESIGN_SYSTEM.md`.

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
  Modules/<Module>/
    <Module>ServiceProvider.php   extends App\Support\Modules\ModuleServiceProvider
    Config/<module>.php           defaults, merged as config('<module>.*')
    Models/  Actions/  Services/  Drivers/  Jobs/  Listeners/  Policies/
    Console/Commands/             auto-registered artisan commands
    Database/Migrations|Factories|Seeders/<Module>Seeder.php (auto-run by DatabaseSeeder)
    Http/Controllers/{Admin,Client,Public}/  Http/Requests/  Http/Resources/
    routes/web.php                auto-loaded in the "web" middleware group
resources/js/pages/{admin,client,public}/<module>/...   Inertia pages
tests/Feature/<Module>/  tests/Unit/<Module>/            Pest tests
```

Modules are discovered automatically (`App\Providers\ModulesServiceProvider`). `MODULES=Billing php artisan test`
loads only Core + Billing.

### Dependency rules (enforced in review)

1. A module may import: its own namespace, `App\Modules\Core\*`, `App\Contracts|Data|Events|Enums|Support\*`,
   `App\Models\User`, framework/vendor code.
2. A module must NOT import another module's classes (models, services, controllers). Cross-module calls go
   through a contract in `app/Contracts`; cross-module reactions go through events in `app/Events`.
3. Exceptions: **Reports** (read model) may read other modules' tables via their models.
4. Enforced by Pest architecture tests (`tests/Arch/ArchitectureTest.php`, run in CI): sibling-module imports,
   kernel → module imports, non-interface contracts, non-final DTOs/events, debug calls, and `DB` facade use in
   controllers all fail the build. (Pest only resolves existing classes, so violations surface once branches meet in `develop`.)
5. Need a new contract/event/DTO? Ask the CTO — shared kernel changes are reviewed centrally.
6. Each module binds its contract implementations in `registerModule()`. In tests of a consuming module,
   bind a fake (`$this->app->instance(PriceBook::class, new FakePriceBook)`), never the real sibling.

### Contracts (implementer → consumers)

| Contract                                                            | Implemented by   | Used by                                               |
| ------------------------------------------------------------------- | ---------------- | ----------------------------------------------------- |
| `Billing\Invoicer`, `Billing\TaxCalculator`, `Billing\CreditLedger` | Billing          | Orders, Services, Domains, Affiliates                 |
| `Catalog\PriceBook`                                                 | Catalog          | Orders, Services                                      |
| `Services\ServiceRegistry`                                          | Services         | Orders                                                |
| `Domains\DomainRegistry`, `Domains\DomainPricing`                   | Domains          | Orders                                                |
| `Notifications\MergeFieldRegistry`                                  | Core (in-memory) | every module registers resolvers; Notifications reads |

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
Display formatting is the frontend's job (`formatMoney`).

**Dates** — `CarbonImmutable` everywhere (`Date::use` is set). Store dates (`date`) for billing anchors
(`next_due_date`), timestamps for events. Advance cycles with `BillingCycle::advance($date, anchorDay: $model->billing_anchor_day)` — store the
original day-of-month as an anchor on recurring items to avoid drift. Query `date` columns with
`whereDate()` (Eloquent stores date casts as `Y-m-d H:i:s`; exact matches break across databases).

**Automation** — daily jobs are tasks in the central pipeline, never ad-hoc scheduler entries:
`app(Automation::class)->task('services.suspensions', Automation::SUSPENSIONS, fn (CarbonImmutable $today) => $count)`.
Stages run in order RENEWALS → LATE_FEES → AUTO_CHARGE → REMINDERS → CANCELLATIONS → SUSPENSIONS →
TERMINATIONS → SYNC → HOUSEKEEPING via `php artisan automation:run [--date=] [--only=]`; each task must be
idempotent for its run date (recorded in `automation_runs`). Renewal invoicing must call
`Invoicer::hasInvoiceForPeriod()` before issuing.

**Statuses** — PHP backed enums in the module (`Enums/InvoiceStatus.php`, TitleCase cases, snake values);
state changes happen in Actions/Services, not controllers, and dispatch the matching event.

**Business logic** — Controllers are thin: validate (FormRequest) → call an Action/Service → redirect/render.
Actions are single-purpose classes (`Actions/ApplyPayment.php` with `handle()`), reused by controllers,
jobs, commands and other actions. Wrap multi-write operations in `DB::transaction`. Make automation idempotent.

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
app(Dashboard::class)->card('admin', 'billing.overdue', fn ($user) => ['label' => ..., 'value' => ..., 'href' => ..., 'icon' => 'Receipt'], 'billing.invoices.view');
```

Icon names must exist in `resources/js/lib/nav-icons.ts` (add one line if needed).

**Permissions, settings, search** — also in `bootModule()` (all registries live in `app/Support`):

```php
app(PermissionRegistry::class)->register('Billing', ['billing.invoices.view' => 'View invoices', 'billing.invoices.manage' => 'Manage invoices']);
app(SettingsRegistry::class)->section('billing', 'Billing', [new SettingDefinition('billing.invoice_prefix', 'Invoice prefix'), ...], 'billing.settings.manage');
app(GlobalSearch::class)->provider('Invoices', fn (string $term) => [...['title','subtitle','href']], 'billing.invoices.view');
```

Every permission you check must be registered; every tunable setting must be registered with a definition.
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
backoff; automation registered in the provider's `schedule()` with `->withoutOverlapping()`.

**Database** — portable migrations (no SQLite/MySQL-only types), foreign keys with explicit on-delete,
indexes on foreign keys and status/date columns used by automation. Migration filenames:
`2026_10_17_<module-prefix-number>_...` so modules don't collide (Core 0001xx, Catalog 1xxxxx, Orders 2xxxxx,
Billing 3xxxxx, Services 4xxxxx, Domains 5xxxxx, Support 6xxxxx, Notifications 7xxxxx, Affiliates 8xxxxx, Platform 9xxxxx).
Models use `#[Fillable]`, `#[UseFactory(XFactory::class)]`, `casts()` method, PHPDoc `@property` blocks.

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
php artisan wayfinder:generate --with-form && npx tsc --noEmit && npm run check
npm run build                                    # pages compile
```

## Git workflow

- Integration branch: `develop` (`main` receives tagged releases). One branch per workstream: `feature/<module>`
  in its own worktree (`scripts/new-worktree.sh <module>`); Conventional Commits, small focused commits.
- Before review: merge latest `develop`, all gates green; report CHANGELOG bullets and screenshots to the CTO
  (the CTO maintains `CHANGELOG.md` and `docs/PROGRESS.md` to avoid merge conflicts).
- Every branch lands through a GitHub pull request into `develop` (`gh pr create --base develop`, template in
  `.github/pull_request_template.md`). Review gates, posted on the PR as review comments: Staff Engineer review →
  QA (runs the suite, adds missing tests) → AI-slop review → fixes by the author → CTO merge with a merge commit
  (`gh pr merge --merge`). Findings are labelled **blocking** or **non-blocking**; blocking ones must be fixed first.
- Never commit `.env`, `vendor/`, `node_modules/`, generated Wayfinder files, or `database/*.sqlite`.
