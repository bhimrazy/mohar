# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Progress, status and screenshots: [docs/PROGRESS.md](docs/PROGRESS.md).

## [Unreleased]

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

### Themes & brand

- Themes module: theme token schema (`billing-theme` v1, `ThemeSchema`) and `ThemeRegistry` kernel for
  presets from modules or theme packs. Four presets ship: Calm Fintech (default), Operator Pro, Hosting Warm and
  Enterprise Slate. CSS variables are generated per theme version and injected with the CSP nonce.
- Admin → Settings → Appearance (`themes.manage`): theme per area (admin, client area, storefront), default
  density, optional per-staff theme/density. Custom themes come from an editor with a light/dark live preview
  and a WCAG AA contrast guard that blocks saving. JSON export/import is strictly validated. Rename and delete
  are supported, and a theme that is in use cannot be deleted.
- Brand (Mohar by default): name, tagline, logos (light/dark), mark and favicon, with uploads on the objects
  disk (SVG rejected) and reset to default. `BrandProvider` contract for pages, mail layout and invoice PDFs.
  Default SVG logos, favicon.ico and apple-touch icon replace the starter-kit logo.
- UI: shadcn Chart (Recharts 3.10.1) with themed area/bar charts and KPI sparklines; sidebar with collapsible
  groups, badges, ⌘K command palette and quick-create; admin dashboard renders kernel widgets, deferred widgets
  and "Needs attention" todos.

### Core

- Clients, client groups, contacts, multi-user client accounts (owner + per-area permissions), staff flag,
  roles with wildcard permissions, currencies, settings store, activity log.
- Admin: client list/search/filter, create, view, edit. Client area: dashboard, account details, account switcher.
- Registration creates an owned client account.
- Seeders: currencies (USD/EUR/GBP), roles, `admin@example.com` / `demo@example.com` (password: `password`).

### Platform admin

- Staff: invite by email (7-day link, resend/cancel) or create with a temporary password (forced change on first
  sign-in); edit, assign role, deactivate (sessions revoked, next request 401), reset 2FA; last sign-in time/IP.
- Staff 2FA enforcement (`security.staff_2fa_required`, default on) before any admin route.
- Roles CRUD with a permission picker built from `PermissionRegistry` (module/area wildcards); privilege-escalation
  guard (only grant what you hold) and last-full-administrator protection; system roles undeletable.
- Currencies CRUD with decimals/format, exchange rates, default switch (rebases rates; blocked once invoices exist),
  `Currency::convertTo()` (round half up across decimal places). Client groups CRUD (discount, suspension/invoice flags).
- Generic settings UI over `SettingsRegistry` (per-section permission, typed validation, secret write-only values,
  activity-logged diffs); Company and Security sections registered.
- Activity log viewer with filters; activity entries now record actor type, event key, impersonator and user agent.
- Automation run log with per-task status and "run now".
- Admin ⌘K global search (`GET /admin/search`, permission-filtered groups, max 5 per group); Contacts and Staff providers.
- Client profile: contacts CRUD, client logins (invite, per-area permissions, ownership transfer, removal),
  close/reopen (`ClientClosed`), merge with preview (same currency, one transaction, tombstone redirect).
- Login as client: signed single-use link, 60-minute cap, banner with "Return to admin", account-security routes blocked,
  audit trail in `impersonation_sessions` and `activity_log.impersonator_id`.
- Real UI for the admin dashboard, clients index/show/form, client dashboard and account details.
- Permission catalog: `core.clients.create|update` replace `core.clients.manage`.

### Domains

- TLD catalog with per-term register/transfer/renew prices per currency and client-group slab (no silent currency
  conversion), grace/redemption periods and fees, renew windows, EPP requirement and registrar assignment.
- `DomainPricing` (`price`, `terms`, `split` for multi-level TLDs such as `.co.uk`, renewal terms within the
  10-year registry horizon) and `DomainRegistry` (`createPending`, `process`, `cancelPending`) implementations.
- Domain lifecycle state machine (pending → active → grace → redemption → expired, transfers, cancellations) with
  activity log and events; IDN/punycode-safe name normalisation and validation; one live record per name.
- Registrar driver contract, driver registry and deterministic `log` demo registrar; every call goes through a
  gateway that checks capabilities and writes a redacted `registrar_command_log`.
- Queued register/transfer/renew jobs with retries; timeouts never re-register; renewals are never repeated.
- Automation: renewal invoicing (idempotent per cycle), grace/redemption invoice swap and expiry cancellation,
  ICANN-style reminders with per-client digest, registrar and transfer sync (hourly `domains:sync`), log pruning.
- Admin: domain list (status/registrar/TLD/expiry/client filters) and detail (dates, amounts, forced status,
  registrar commands, nameservers, lock, EPP, contacts, command log, move, delete), TLD pricing grid, registrars.
- Client area: my domains, domain management (nameservers, auto-renew, lock, EPP code, contacts, transfer retry).
- Public domain search with availability, suggestions and prices; JSON lookup endpoint (rate limited, cached).
- Navigation, dashboard cards, permissions, settings, global search and `domain` merge fields; demo seeder.
- Review fixes: exactly-once registrar submissions/renewals (row-lock claims, unique non-overlapping jobs, unknown
  outcomes flagged `needs_review` and verified, never re-sent), disabled registrars refused, zero-total renewal
  invoices handled, renewal chunking fixed, lookups cached as arrays, strict pricing-grid input, "Renew now" for
  clients and staff, needs-attention todos, `DomainPricing` v2, domain events promoted to `app/Events/Domains`,
  USD/INR/NPR demo prices.

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
- Dependency policy (ADR 0007, `docs/devops/DEPENDENCY_POLICY.md`): exact versions for every direct Composer/npm
  dependency, `.npmrc` `save-exact` + native `min-release-age=7`, Composer `config.policy` (block advisories and
  malware), RustFS image digest-pinned. `scripts/check-dependency-age.mjs` fails CI and `composer ci` when a
  changed package, action, image or tool is younger than 7 days, an action SHA does not match its tag or
  anything is unpinned; `security-exception` label + CTO approval waives only the cooldown.
- Lockfiles brought into compliance: 94 packages younger than 7 days moved back to the newest older release
  (e.g. `laravel/framework` 13.37.0 → 13.34.0, Pest 5.3.2 → 5.3.0 with PHPUnit 13.3.6, symfony 8.1.9 → 8.1.8,
  `react` 19.3.1 → 19.3.0, `radix-ui` 1.8.1 → 1.7.0 and the `@radix-ui/*` family). Framework 13.35+ needs
  Wayfinder ≥ 0.1.22 (QUERY verb) and PHPUnit 13.4 needs framework ≥ 13.35, so both wait for the cooldown.
- New gates: `composer validate`, `composer audit --locked`, `npm audit --audit-level=high` (`composer deps:check`).
- CI minutes: one job instead of five (≈ 2 billed minutes per PR run instead of 5), no runs for drafts,
  feature-branch pushes or screenshot-only changes, docs-only PRs run only `vp check`, PHPStan result cache,
  15-minute timeout (`docs/devops/CI.md`).
- Dependency check hardening (PR #9 review): image digests must be the tag's registry digest (index or platform),
  npm `resolved`/`integrity` and Composer `dist`/`source` must match the registry for the checked name@version;
  offline unit tests for these rules run in `npm test`. Policy checks run before `composer install`/`npm ci`;
  push runs diff against `github.event.before`; `security-exception` waives only `Security-Exception:
name@version` items listed in the PR body (job summary lists them). Vitest (`npm test`) in CI and `composer ci`.
  No workflow `paths-ignore` (image/docs-only PRs take the in-job fast path). Follow-up #11: framework 13.36.x +
  Wayfinder 0.1.22 after 2026-10-22.
