# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Progress, status and screenshots: [docs/PROGRESS.md](docs/PROGRESS.md).

## [Unreleased]

### Catalog

- Product groups (slug, visibility, ordering, allowed payment gateways with a warning when visible groups share none),
  products (shared/reseller/server/other; hidden, retired, featured; stock; quantity modes; auto-setup policy;
  welcome/upgrade/fixed-term email keys; recurring-cycle limit; fixed term; prorata settings; free domain;
  suspend/terminate overrides; provisioning driver, server group and driver config stored for Services).
- Prices per currency × billing cycle with setup fees in minor units (polymorphic `prices` for products, addons and
  option values); no silent conversion; price edits never touch existing services; optional daily
  `catalog.convert-prices` automation task / `catalog:convert-prices` command (off by default).
- Configurable option groups (dropdown, radio, yes/no, quantity with min/max/step), hidden options/values that keep
  pricing existing selections, and a blocking check that every option value is priced for each cycle a product sells.
- Product custom fields (with timeout-safe regex validation), product addons (own cycles, applicable products,
  visibility matrix), upgrade/downgrade paths, duplicate product.
- `PriceBook` implementation: quotes with options and quantity pricing, addon cycle fallback, available cycles,
  upgrade eligibility, storefront, order-form data, atomic row-locked stock reservation; stock returned on
  `OrderCancelled`/`OrderMarkedFraud` before activation.
- Admin "Setup" pages: products (filters, pricing matrix editor and tabbed editor), product groups (reorder),
  configurable options, addons. Permissions `catalog.{products,options,addons}.{view,manage}`, `catalog.settings.manage`;
  catalog settings; product global search; `product` merge fields; demo `CatalogSeeder` (USD/EUR/GBP).

### Platform foundation

- Laravel 13 app on the official React starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui),
  Fortify auth (2FA, passkeys), Wayfinder, Pest 5, Larastan level 7, Laravel Boost AI guidelines & skills.
- Modular monolith: auto-discovered `app/Modules/*` with conventions for config, migrations, routes, views,
  commands and scheduled tasks; `MODULES=` filter for isolated test runs (ADR 0001).
- Shared kernel: `Money` (minor units, ADR 0002), `BillingCycle`, `BillableType`, cross-module contracts
  (`Invoicer`, `TaxCalculator`, `CreditLedger`, `PriceBook`, `ServiceRegistry`, `DomainRegistry`,
  `DomainPricing`, `MergeFieldRegistry`), DTOs and 37 domain events.
- Server-driven sidebar navigation and dashboard-card registries; admin (`/admin`) and client (`/client`) areas.
- Object storage: `objects` disk backed by RustFS via `compose.yaml` + `objects:ensure-bucket` command (ADR 0004).

### Core

- Clients, client groups, contacts, multi-user client accounts (owner + per-area permissions), staff flag,
  roles with wildcard permissions, currencies, settings store, activity log.
- Admin: client list/search/filter, create, view, edit. Client area: dashboard, account details, account switcher.
- Registration creates an owned client account.
- Seeders: currencies (USD/EUR/GBP), roles, `admin@example.com` / `demo@example.com` (password: `password`).

### DevOps

- PostgreSQL 18.6 (alpine, digest-pinned) in `compose.yaml` next to RustFS, with a `billing_test` database;
  `.env.example` documents switching `DB_CONNECTION=pgsql`. SQLite stays the default for dev and tests.
- CI moved to the repository root (`.github/workflows/ci.yml`, the kit's `billing/.github` workflow was
  ignored by GitHub): `lint` (postgres image drift check, Pint, vp check, tsc), `static` (Larastan),
  `tests-sqlite`, `tests-pgsql` (migrate:fresh --seed + full suite on a PostgreSQL service), `build`; cached
  Composer/npm, SHA-pinned actions, least-privilege permissions, superseded PR runs cancelled. Dependabot
  (target `develop`, 14-day cooldown) for actions, compose, Composer and npm.
- `composer ci` runs every CI gate locally, including `composer test:pgsql` (migrate:fresh --seed + suite on
  PostgreSQL; `DB_*` overridable per worktree). `.nvmrc` pins Node 24.21.0; `composer.json` requires PHP ^8.4.
  RustFS and PostgreSQL ports bind to 127.0.0.1. Local setup guide: `docs/devops/LOCAL_SETUP.md`.
- `vite-plus` 0.3.0 → 0.3.3 (fixes the critical `tinypool` advisory GHSA-85c8-ppgw-ccpr; `npm audit` clean);
  `vite` is now aliased to `@voidzero-dev/vite-plus-core@0.3.3` (dependency + override), as 0.3.3 requires.
- Fixed: admin client search was case-sensitive on PostgreSQL (`whereLike` now renders `ilike`); client,
  contact and new-user emails are stored lowercase.
