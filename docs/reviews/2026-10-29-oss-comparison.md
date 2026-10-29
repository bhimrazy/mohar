# Open-source comparison, 2026-10-29

Asked by the owner: how Mohar is built compared with the two maintained open-source hosting billing projects, where we
are better and what they do that we should learn from. Principal architect review.

## Scope and method

| Project     | Source                                       | Licence (read from `LICENSE` in the clone) | Stack                                   |
| ----------- | -------------------------------------------- | ------------------------------------------ | --------------------------------------- |
| FOSSBilling | github.com/FOSSBilling/fossbilling, default branch, shallow clone 2026-10-29 | Apache-2.0                                 | Custom PHP framework, Pimple DI, Doctrine ORM, Twig |
| Paymenter   | github.com/Paymenter/Paymenter, default branch, shallow clone 2026-10-29     | MIT                                        | Laravel 13, Filament 5 admin, Livewire 4 client area |
| Mohar       | `origin/develop` at a90717a3                 | (ours)                                     | Laravel, Inertia + React, PostgreSQL    |

No third project was added. The other candidates are not a fit: BoxBilling is the abandoned codebase FOSSBilling forked,
Invoice Ninja is general invoicing under a source-available licence with no provisioning, and Blesta, HostBill and
WHMCS are closed.

Both clones were read as untrusted data in the session scratchpad. Nothing from them was installed or run, and nothing
was copied: the ideas below are described in our own words, credited to the project they come from. File paths for
the other projects are relative to their repository root. Mohar paths are relative to `billing/`.

Not verified: runtime behaviour of either project, their test pass rates, their documentation sites (only the repos
were read), and whether a pattern seen in one file is used everywhere in that project.

## Summary

Mohar is the most carefully engineered of the three on everything that touches money, external providers and module
boundaries: integer minor units, a typed kernel of contracts and capability interfaces, after-commit events, a
confirm-then-apply rule for provider calls, an outcome-unknown path for every driver family, log redaction, SSRF
guards and an expand-contract migration lint. Neither project has an equivalent of most of these.

Both projects are ahead on the things that make a platform usable by other people: a third-party extension
lifecycle (install, upgrade, uninstall, scaffold), a public API, UI extension slots, translations with a community
pipeline, an in-place upgrade path, browser end-to-end tests, and a scheduler heartbeat. These are mostly on our
roadmap (waves 3.1 and 4); the comparison gives concrete shapes for them.

## Area by area

Verdict key: **Mohar** = we are better built, keep ours. **FOSSBilling** / **Paymenter** = they do it better, adopt
the idea. **Mixed** = each has a part worth keeping.

### 1. Module boundaries and extension model — Mixed

| Aspect | Mohar | FOSSBilling | Paymenter |
| ------ | ----- | ----------- | --------- |
| Module shape | `app/Modules/<Name>` with a service provider, its own migrations, routes and config; depends only on the kernel (`app/Contracts`, `app/Data`, `app/Events`, `app/Support`); enforced by `tests/Arch/ArchitectureTest.php`; siblings can be absent (`tests/Extensions/*AloneTest.php`, `MODULES=`). | `src/modules/<Name>` with `manifest.json`, `Service.php`, `Api/{Admin,Client,Guest}.php`, Twig templates. Modules call each other through the DI container (`$this->di['mod_service']('invoice')`); no boundary test. | No module split. One `app/` tree; extensions live in `extensions/{Gateways,Servers,Others}`. |
| Driver contract | Interfaces plus capability interfaces (`Contracts/Billing/GatewayDriver.php`, `Contracts/Services/Provisioning/*` with 12 optional capabilities, `Contracts/Domains/Registrar/*` with 16), DTOs in, DTOs out, contract test per family (`tests/Support/Contracts/ProvisioningDriverContract.php`). | Abstract base classes with every method abstract (`src/library/Registrar/AdapterAbstract.php`, `src/library/Server/Manager.php`); unsupported operations throw. Payment adapters are loosely typed (`src/library/Payment/AdapterAbstract.php`). | Abstract base class with `supportsX(): bool` flags and methods that throw "Not implemented" (`app/Classes/Extension/Gateway.php`); calls are dispatched by name through `ExtensionHelper::call()` and `hasFunction()` (`app/Helpers/ExtensionHelper.php`). Drivers receive Eloquent models (`Invoice`, `InvoiceTransaction`). |
| Discovery | Kernel registries (`Support/Integrations/DriverRegistry.php` and family subclasses) with `register()` called from a package's `boot()`. No metadata file, no lifecycle hooks. | Folder scan plus `manifest.json`. | Composer classmap scan for a namespace convention plus a folder scan (`ExtensionHelper::getAvailableExtensions()`); metadata from a PHP attribute (`app/Attributes/ExtensionMeta.php`). |
| Lifecycle | None. A package that needs tables or a data change on upgrade has no host hook. | Module install/uninstall in `src/library/FOSSBilling/ExtensionManager.php`. | `installed()`, `uninstalled()`, `upgraded($oldVersion)` on the base class (`app/Classes/Extension/Extension.php`), per-extension migrations run and rolled back by the host (`ExtensionHelper::runMigrations()`, `rollbackMigrations()`), and `app/Console/Commands/Extension/{Create,Install,Upgrade,Disable}.php` with stubs for scaffolding. |
| Hooks | Past-tense domain events after commit (ADR 0011, 0020); no veto or modify hooks yet (roadmap wave 4, "Extension depth"). | A before and an after event for most admin actions (`src/modules/Invoice/Event/Before*Event.php`, `After*Event.php`); a listener on a before event can stop the action by throwing. | Laravel events plus `EventHelper::itemEvent()` and `renderEvent()` (`app/Helpers/EventHelper.php`): an event whose listeners return items (navigation entries, view fragments) that are merged and sorted by priority. That is how extensions add UI. |

Mohar's contracts are the better design: capability interfaces are checkable at registration, the UI lists
capabilities from the interfaces a driver implements, and drivers never see models, so a schema change cannot break
a third-party driver. Paymenter's call-by-name and FOSSBilling's throw-if-unsupported both turn a missing capability
into a runtime error.

What they have that we lack is everything around the contract: a lifecycle and a way to start a package.

- **Adopt (Paymenter):** an extension lifecycle in the kernel — a package declares its version, the host records the
  installed version, and calls an install, upgrade-from-version and uninstall hook; package migrations run through
  the host so `deploy:check-migrations` lints them like ours. Plus a scaffold command that generates a driver package
  skeleton that already passes the family contract test. ADR needed (see "ADRs to write").
- **Adopt the idea, not the shape (Paymenter):** UI extension slots. Their slot returns arbitrary view fragments; ours
  should return a typed schema (rows, meters, buttons, links) rendered by our React components, as
  `parity/developer-architecture.md` §3 already proposes. Names: a `UiSlotRegistry` in `Support/` with a small fixed
  set of slots (client service page, admin service tab, client nav, admin dashboard widget).
- **Adopt narrowly (FOSSBilling):** veto hooks. Do not copy the blanket before/after pair on every action; it doubles
  the event catalogue and makes every action's failure mode depend on unknown listeners. Instead add a small number
  of named, typed decision points (checkout validation, price adjustment, before-send of a notification) as
  pipeline contracts returning a result, matching the roadmap's wave-4 list.
- **Keep ours:** capability interfaces, DTO-only driver inputs, registries, contract tests, boundary tests.

### 2. Money — Mohar

| | Mohar | FOSSBilling | Paymenter |
| - | ----- | ----------- | --------- |
| Storage | Integer minor units (ADR 0002), `Support/Money.php` (immutable, currency-checked, `allocate()` for splits). | `DECIMAL(18,2)` columns (`src/modules/Invoice/Entity/Invoice.php`), arithmetic in PHP floats with `round(..., 2)` (`src/modules/Invoice/Service.php`, `ServiceInvoiceItem.php`). | `DECIMAL(17,2)` columns (`database/migrations/*`), floats and `number_format()` strings in `app/Classes/Price.php`; refunds typed `float`. |
| Currency exponent | Per currency; Stripe amounts converted by `Modules/PaymentsStripe/Support/StripeAmount.php`. | Two decimals assumed. | Two decimals assumed: the Stripe webhook divides by 100 for every currency (`extensions/Gateways/Stripe/Stripe.php`, `payment_intent.succeeded`), wrong for zero-decimal currencies. |
| Webhook verification | Signature and timestamp tolerance (`Config/payments_stripe.php`, `webhook_tolerance_seconds`), then the object is fetched from the provider before it is trusted (`Drivers/StripeDriver.php`, `parseCallback`). | `JsonWebhookAuthenticatorInterface` lets a gateway authenticate the raw callback before anything is stored (`src/library/FOSSBilling/Payment/`). | HMAC compared with `hash_equals` but the timestamp is never checked, so a captured webhook can be replayed (`Stripe.php`, `isValidSignature`). Amount and currency are not compared with the invoice. |
| Idempotency | Settlement under row locks with a unique provider reference (`Modules/Billing/Actions/SettlePaymentAttempt.php`, migration `..._add_provider_ref_unique_to_payment_attempts.php`), credit-ledger idempotency keys, outcome-unknown verification (ADR 0026, `VerifyUnknownOutcomesCommand`). | Store first, process later: a callback is saved as a received transaction, then claimed by one worker with a recovery threshold for stuck claims (`src/modules/Invoice/ServiceTransaction.php`, `claimForProcessing()`); duplicate gateway transaction ids rejected early. Processed transactions are immutable (`assertNoProcessedHistoryChange()`). | `updateOrCreate` on `transaction_id` under an invoice row lock (`ExtensionHelper::addPayment()`). |
| Refunds | Confirm-then-apply (ADR 0021): no provider call inside a database transaction; indeterminate outcomes go to review. | Credit-note style, float math. | The gateway's refund HTTP call runs inside `DB::transaction` holding a row lock (`ExtensionHelper::refund()`); a slow provider holds the lock, and a rollback after the provider refunded leaves money moved with no record. |

- **Keep ours.** This is the area where Mohar is clearly ahead.
- **Adopt (FOSSBilling), small:** the "received, then claimed" inbox for callbacks with a stuck-claim recovery
  threshold is worth comparing with how our callbacks are processed today. We have no generic table that stores the
  raw callback before it is parsed (`git grep` finds no webhook inbox), so a parse bug drops the event and depends on
  the provider's retries. Recommendation: a follow-up spike to decide whether a raw-callback inbox (store verified
  body, process from a queue, keep for replay) belongs in the gateway host. Not urgent; provider retries and
  `ReconcilePayments` cover most cases.
- **Adopt (FOSSBilling):** their explicit guard that a processed transaction cannot have amount, currency or provider
  id edited is a rule we follow by convention; check that our admin transaction edit paths refuse the same fields and
  add a test if one is missing.

### 3. Invoices and tax — Mohar, one idea from Paymenter

Mohar: numbered fiscal documents, credit and debit notes as the only way to change an issued invoice (ADR 0024),
fiscal snapshot on the invoice (`..._add_fiscal_snapshot_to_invoices.php`), Nepal VAT classification, `TaxCalculator`
and `TaxIdRules` contracts. FOSSBilling: one tax rate per invoice, float rounding per line
(`src/modules/Invoice/ServiceTax.php`, `Service.php`). Paymenter: `app/Models/InvoiceSnapshot.php` freezes the
seller's name, tax name, rate, country and bill-to address at issue; adjustment notes for refunds.

- **Keep ours.** Both projects added correction documents and snapshots recently; we designed them in.
- **Not relevant:** Paymenter's snapshot is the same idea as our fiscal snapshot.

### 4. Data model and migrations — Mohar

Mohar: Laravel migrations per module (`app/Modules/*/Database/Migrations`), PostgreSQL required (ADR 0006),
expand-then-contract rules with a lint that fails a destructive step without a `@contract` or `@compatible` reason
(`Modules/Core/Console/Commands/CheckMigrationsCommand.php`). FOSSBilling: no migrations; Doctrine entities are diffed
against the live schema at upgrade (`src/library/FOSSBilling/Doctrine/SchemaSynchronizer.php`) plus numbered patches
in `UpdatePatcher.php`. Paymenter: ordinary Laravel migrations, MySQL in CI (`.github/workflows/test.yaml`).

- **Keep ours.** Schema diffing cannot express data moves or a safe rename, and gives no review point.
- **Adopt (FOSSBilling), small:** `src/library/FOSSBilling/Doctrine/NamedLock.php` takes a database advisory lock so
  only one upgrade or cron runs at a time. Our scheduler locks (ADR 0017) cover jobs; the install and upgrade commands
  should take the same kind of lock when the upgrade command is built.

### 5. Security — Mohar, two ideas from FOSSBilling

| | Mohar | FOSSBilling | Paymenter |
| - | ----- | ----------- | --------- |
| Auth and 2FA | Fortify with TOTP, passkeys and step-up for sensitive actions (ADR 0018, `Http/Middleware/RequireRecentPasswordConfirmation.php`). | No TOTP in core (`git grep` for totp, two-factor and 2fa finds nothing). Session fingerprinting (`src/library/FOSSBilling/Fingerprint.php`). | TOTP via `robthree/twofactorauth` (`app/Livewire/Auth/Tfa.php`), social login (Discord and others), OAuth server via Passport. |
| Permissions | Permission strings checked by `User::hasPermission()` against a role, declared through `Support/Permissions/PermissionRegistry.php` so modules add their own. | Per-module permission lists with constraints (`src/modules/Staff/Service.php`, `hasPermission($member, $module, $key, $constraint)`), cached per member. | One static nested array (`config/permissions.php`) plus a policy per model (`app/Policies/*`). |
| Secrets | Encrypted storage with key rotation and re-encrypt command (ADR 0022, `ReencryptCommand`), sensitive keys declared by drivers (`Contracts/Integrations/DeclaresSensitiveKeys.php`), log redaction everywhere (ADR 0023). | `getSecretFields()` on each adapter (`src/library/Payment/AdapterAbstract.php`, `Server/Manager.php`). | `encrypted` flag per setting (`app/Models/Setting.php`); no log redaction beyond `app/Redactors/RightRedactor.php` for audits. |
| Outbound calls | SSRF guard pinning the checked IP (ADR 0015, `Support/Integrations/OutboundAddressGuard.php`). | Symfony HTTP client, no address policy seen. | No address policy seen. |
| Timing | Not found. | `src/library/FOSSBilling/Security/RandomizedTimeFloor.php`: sensitive endpoints (login, password reset) take at least a random 75 to 125 ms so response time does not reveal whether an account exists. | Not found. |
| Audit | Activity log in Core (`Modules/Core/Models/ActivityLog.php`). | Activity module. | `owen-it/laravel-auditing` on models. |

- **Keep ours** for auth, step-up, secrets, redaction and SSRF.
- **Adopt (FOSSBilling):** a minimum randomised response time on login, password reset, magic-link and invite
  endpoints. Hand to the next security round to probe whether our responses differ in time today; adopt only if they
  do.
- **Adopt (FOSSBilling), as an idea to evaluate:** constraint-scoped permissions (a permission limited to, for example,
  one client group or one department). Our permission strings are flat; departments in Support and multi-brand later
  will need scoping. Note it in the permission model when client-user permissions (roadmap 3.7) are built.
- **Not relevant:** session fingerprint scoring; it adds false logouts for little gain over our session handling.

### 6. Tests and CI — Mohar on volume and kind, FOSSBilling on browser tests

| | Mohar | FOSSBilling | Paymenter |
| - | ----- | ----------- | --------- |
| Test files | 565 (`tests/`: 484 Feature, 56 Unit, 10 Arch, 10 Extensions, 5 Simulators) | 314 (`tests/` and `src/modules/*/tests`) plus 11 Playwright specs | 17 (`tests/Feature`, `tests/Unit`) |
| Kinds | Pest feature and unit, architecture scans, sibling-absent extension tests, provider simulators (ADR 0016), load test workflow | Pest unit, Playwright E2E per module (`src/modules/Invoice/tests/E2E`, `playwright.config.ts`), live tests | PHPUnit feature, SQLite |
| CI | Sharded `ci.yml`, PHPStan, Pint, `vp check`, fragments, deploy smoke, load test | CodeQL, code-quality, POT freshness check (`i18n-pot-check.yml`), Playwright, Docker build, Sentry release | Lint, test on PHP 8.3 and 8.4, Docker, API docs build |

- **Keep ours.** Paymenter's suite would not catch the money defects our reviews catch.
- **Adopt (FOSSBilling):** browser end-to-end tests that live beside each module and run in CI on the critical
  paths (checkout, pay, renew, refund, sign-in with 2FA). Already planned as "Pest browser E2E"; their per-module
  layout is a good model so a module's E2E runs with its `MODULES=` filter.
- **Adopt (FOSSBilling):** CodeQL (or equivalent static security scan) as a CI job. Needs owner approval as a new
  action; it adds no dependency to the app.

### 7. i18n — FOSSBilling and Paymenter

Mohar: 1,394 `__()` or `trans()` calls in `app/`, but no `lang/` directory, and no file under `resources/js` uses a
translation function (`git grep` for `useTranslation`, `i18next` and `__(` returns none across 159 page files). Every
React string is hard-coded English today. FOSSBilling: gettext with a POT file checked for freshness in CI and
translations shipped from a separate locale repository (`src/locale/readme.md`). Paymenter: 24 locales in `lang/`
managed through Crowdin (`crowdin.yml`).

- **They are ahead.** Roadmap wave 4 lists translations (Nepali and Hindi first). Every React page added before a
  mechanism exists is a page to retrofit.
- **Adopt (both):** decide the mechanism now: one source of strings shared by PHP and React (Laravel JSON lang
  files exposed to Inertia), a CI check that fails on a string key used but not in the source catalogue (their POT
  check, our shape), and a translation-platform export. ADR needed; the retrofit can follow, but new pages should
  use it from the day it lands.

### 8. Themes and client area — Mixed

Mohar: Themes module with a `Support/Themes/ThemeRegistry.php`, Inertia + React, design tokens. FOSSBilling: Twig
themes that can override any module template (`src/themes/`). Paymenter: Blade themes in `themes/<name>` with their
own Vite build and settings (`themes/default/theme.php`), a `MakeTheme` command, and the slot events from §1.

- **Keep ours** for the rendering stack: a React component library is easier to keep consistent and accessible than
  full template overrides, and template overrides make every upgrade a merge for the theme author.
- **Adopt (Paymenter):** theme settings declared by the theme (colours, logo, layout options) and a scaffold command.
  Check `ThemeRegistry` against this when the theme work resumes.

### 9. API — FOSSBilling and Paymenter

Mohar has no public API (`parity/developer-architecture.md` §2; the only API route is inbound email). FOSSBilling
builds its own admin, client and guest UI on the same API (`src/modules/*/Api/{Admin,Client,Guest}.php`,
`src/library/FOSSBilling/Api/Dispatcher.php`), so every UI action is also an API action. Paymenter has a Passport
OAuth2 API with scoped API keys (`app/Models/ApiKey.php`, `permissions` column), admin controllers in
`app/Http/Controllers/Api/Admin`, and generated OpenAPI docs (`config/scramble.php`, `build-api-docs.yaml`).

- **They are ahead.** Roadmap wave 4 already specifies Sanctum tokens with permission-string abilities, `/api/v1`,
  problem details, cursor paging, `Idempotency-Key` and OpenAPI.
- **Adopt (FOSSBilling), as a rule not a framework:** every staff or client action has exactly one Action class that
  both the controller and the future API endpoint call. We mostly do this already (`Modules/*/Actions`); make it a
  rule in `architecture.md` so the API is a thin layer when it is built.
- **Adopt (Paymenter):** generated OpenAPI from the route and request classes, published by CI, instead of a
  hand-written spec. Tool choice needs approval under ADR 0007.

### 10. Install and upgrade — Mixed

Mohar: `mohar:install` with checks (`Modules/Core/Console/Commands/InstallCommand.php`, `Modules/Core/Install/*`),
reference data installers (`Contracts/Install/InstallsReferenceData.php`), Docker image build, blue-green
migration rules. No upgrade command (roadmap wave 4, "Safe upgrades"). FOSSBilling: web installer
(`src/install/`), `UpdateReadinessCheck.php` (writable paths, removable files) before an update, then patches and
schema sync. Paymenter: `update.sh` that refuses to upgrade when installed extensions are not compatible, plus an
in-app updater streaming the console output (`app/Http/Controllers/Admin/UpdateController.php`).

- **Adopt (FOSSBilling and Paymenter):** an upgrade preflight that runs before anything changes: PHP and extension
  versions, writable storage, queue drained, migrations lint clean, and every installed extension package declares
  a compatible kernel contract version (ADR 0010). Refuse with a list, as Paymenter does for extensions.
- **Not relevant:** an in-app updater that runs `artisan` from a web request. It conflicts with our container
  deploys and blue-green rules.

### 11. Performance and operations — Mohar, one idea from Paymenter

Mohar: hot-path read rules (ADR 0020), query budgets in tests, queue topology (ADR 0014), scheduler locks (ADR 0017),
load-test workflow and sizing numbers. FOSSBilling: cache layer, Sentry. Paymenter: `app/Listeners/FlushCacheListener.php`,
cron stats.

- **Keep ours.**
- **Adopt (Paymenter):** a scheduler heartbeat. `app/Console/Commands/ScheduleHeartbeatCommand.php` writes the
  time of the last scheduler run every minute, and the admin shows a warning when it is stale. It is the cheapest
  part of our wave-4 "automation watchdog" and catches the most common install fault (cron not set up). Store it in
  cache or a settings row, show it on the dashboard and in the health check.

### 12. Code quality signals — Mohar

Mohar: strict types, PHPStan, Pint, final readonly DTOs, interfaces for every cross-module call, hard-pinned
dependencies with a cooldown (ADR 0007). FOSSBilling: PHPStan and Rector configured, global-namespace legacy
classes in `src/library` beside namespaced `FOSSBilling\*`, large service classes (`src/modules/Invoice/Service.php`
is about 5,500 lines and makes 82 cross-module `mod_service` calls). Paymenter: PHPStan and Pint, a central 807-line static helper (`app/Helpers/ExtensionHelper.php`)
that most money and provisioning flows go through, caret version ranges in `composer.json`, `debug_backtrace()` to
work out an extension's type in `Extension::config()`.

- **Keep ours.**

### 13. Provisioning resilience — Mixed

Mohar: `Contracts/Services/Provisioning/ReconcilesCommands.php` asks the panel whether a command whose outcome was
unknown ran. FOSSBilling handles a different failure: the account was created but a later step failed
(`src/library/Server/PartialProvisioningException.php` carries the next step; `Manager::prepareAccountCreation()` and
`resumeAccountProvisioning($account, $step)` let the host retry from that step instead of creating again).

- **Adopt (FOSSBilling):** an optional capability for drivers whose create is several remote calls (cPanel account
  plus package plus SSL plus DNS): the driver reports how far it got and the host offers "resume from step" rather
  than "run again". It complements `ReconcilesCommands`; the host policy (never re-run on its own) stays.

## Feature areas they have that we do not

From the repositories only; checked against `origin/develop` with `git grep`.

| Feature | Where | Mohar |
| ------- | ----- | ----- |
| Public REST API with tokens and OpenAPI | Paymenter, FOSSBilling | Missing (wave 4) |
| Third-party extension install, upgrade and scaffold | Paymenter | Missing |
| UI extension slots | Paymenter | Missing (wave 4) |
| Translations, community pipeline | Both | Missing (wave 4) |
| Upgrade command with preflight | Both | Missing (wave 4) |
| Scheduler heartbeat | Paymenter | Missing (wave 4 watchdog) |
| Browser E2E tests | FOSSBilling | Planned |
| Affiliates | Paymenter (`extensions/Others/Affiliates`) | Queued (wave 3) |
| Social sign-in | Paymenter | Planned (3.7) |
| Captcha on public forms | Paymenter (`app/Classes/Settings.php`) | Planned (3.7) |
| Web push and Discord notifications | Paymenter | Missing (WhatsApp and SMS planned) |
| Game and VPS panels: Pterodactyl, Convoy, Virtfusion, Virtualizor | Paymenter (`extensions/Servers`) | Missing |
| Hestia and CWP panels | FOSSBilling (`src/library/Server/Manager`) | Missing |
| Licence-key and downloadable products | FOSSBilling (`Servicelicense`, `Servicedownloadable`) | Missing |
| Mass mailer | FOSSBilling (`Massmailer`) | Missing |
| Custom pages, form builder, embeddable order button | FOSSBilling (`Custompages`, `Formbuilder`, `Orderbutton`, `Embed`) | Missing |
| RDAP availability lookups | FOSSBilling (`src/library/Registrar/Rdap.php`) | Missing; check with the Domains owner whether lookups without a registrar are wanted |
| Gateway billing agreements (provider-side mandates) | Paymenter | Missing (we capture saved tokens ourselves) |

## The five ideas most worth adopting

1. **Frontend i18n mechanism now** (both projects). Every page built without it is a retrofit; Nepali is a market
   requirement. ADR, then new pages use it.
2. **Extension lifecycle and scaffold** (Paymenter). Version, install, upgrade-from and uninstall hooks, package
   migrations run and linted by the host, and a generator whose output passes the family contract test. This is what
   turns our kernel contracts into something an outside author can ship.
3. **Typed UI extension slots** (Paymenter's idea, our shape). A small fixed set of slots returning a schema, not
   HTML; unblocks provisioning client-area output and dashboard widgets.
4. **Upgrade preflight with extension compatibility** (FOSSBilling readiness check, Paymenter's refuse-on-incompatible
   extensions), built with the wave-4 upgrade command and ADR 0010's contract version.
5. **Scheduler heartbeat** (Paymenter). Small, and catches the most common install fault before invoices stop going
   out.

Runner-up: resumable multi-step provisioning (FOSSBilling), randomised minimum response time on account endpoints
(FOSSBilling), per-module browser E2E (FOSSBilling).

## ADRs to write

- **Translations and string catalogue** — one catalogue for PHP and React, key style, CI check, export format,
  locale fallback, who may add a locale.
- **Extension packages and lifecycle** — package metadata (`extra.mohar` in `composer.json`), declared kernel
  contract version (ties to ADR 0010), install, upgrade and uninstall hooks, package migrations, scaffold output.
- **UI extension slots** — the slot list, the schema, permission checks, ordering, and what a slot may not do.
- Amend ADR 0009 when resumable provisioning is taken up (a new optional capability, no break).

## Follow-ups, in priority order

1. i18n ADR, before the next batch of client-area pages.
2. Extension lifecycle ADR, as part of kernel extension contracts (roadmap 3.1).
3. Scheduler heartbeat: a small Core PR (last run time, dashboard warning).
4. UI slots ADR, before provisioning client-area output (developer-architecture §3).
5. Next security round: measure response-time differences on login and password reset; adopt a time floor if found.
6. Spike: raw-callback inbox for gateway webhooks (store verified body, process from the queue, replay).
7. Upgrade preflight, with the wave-4 upgrade command.
8. Browser E2E per module, when "Pest browser E2E" is scheduled.
9. Resumable provisioning capability, when a multi-step driver (cPanel with SSL or DNS) needs it.
