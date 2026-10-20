# Architecture review — 2026-10-20

- Reviewer: Principal architect. Baseline: `docs/reviews/2026-10-19-architecture.md` (at `685da6f`).
- Scope: `develop` at `b945ce26` (#152 merged). In-flight PRs #112, #128, #160–#164, #166 and #168 were read for their kernel
  file lists and their fit. #160 has its own architecture review and is not re-reviewed here.
- Method: read only, scratch worktree. `tests/Arch` passes (29 tests, 464 assertions; 25 at baseline). Module
  isolation was probed by booting with `MODULES=<module>` and resolving every route middleware alias. Paths are
  relative to `billing/`.

## Summary

Since the baseline the kernel grew very little: +1 DTO and +2 Support classes (`Endpoint`, `Endpoints`, `Queues`), and
3 Stripe DTO fields. The arch rules still hold. The risk has moved from "what lands in the kernel" to "what is built
around it". Three accepted or agreed sequencing decisions were skipped:

- the snapshot before S1 and PR C;
- registrar PR C before the prerequisites;
- ADR 0013 before Nepal-first date work.

The work built since then follows the old patterns.

**What the baseline asked for, and what happened**

| Baseline item | Status at `b945ce26` |
| --- | --- |
| 1. Core `password.recent` and a per-module boot step (B1, #81) | **Not done.** Worse: see F3. #112 is stale since 10-19. |
| 2. #67 snapshot and `API_VERSION`, before S1 and PR C (K1, #83) | **Not done.** S1 (#92) merged first. 2 of the 4 kernel-touching merges since then are not in the changelog (F1). |
| 3. Accept 0015, 0013, the 0014 queue section; review 0017 | 0013 and the 0014 queue section accepted. 0015 still Proposed, though in force. 0017 still Proposed, though partly built (#122). |
| 4. `Queues`, the `registrar` queue, `after_commit`, the install hint, the automation queue | `Queues` and the automation connection are done (#122) and the hint is generated. Not done: the `registrar` queue, `after_commit`, and constants in jobs (F4). |
| 5. Scheduler sweeps dispatch jobs (S1) | **Not done.** Two more inline provider sweeps were added (F4). |
| 6. ADR 0011 adoption PR | Not scheduled. References to the old after-commit helpers went from 55 to 39. `occurredAt` is still missing. |
| 7. ADR 0013 steps 1–2 | **Not started** (#85). New Nepal code repeats the drift (F6). |
| 8. PR C brief (K6) | No PR C. The prerequisites were built on the module SPI instead (F2). |
| 9. Gateway family on ADR 0012 (K5) | Not done. Stripe and late payments extended the flag-based shape (F7). |
| 10–11. `SensitiveValue`, seller contract, docs drift | Not done. Low priority, except the seller contract, which Nepal VAT needs (F7). |

## Findings

| # | Finding | Action | Blocks |
| - | ------- | ------ | ------ |
| F1 | **Kernel changes still land unrecorded.** There is no `kernel:api-snapshot` and no `API_VERSION`. `kernel-changelog.md` lists neither #58 (`CallbackEvent::ATTEMPT_UPDATED` + `$reference`, `LookupResult::PROCESSING`, `RefundRequest::$reference`) nor #122 (`App\Support\Queues`, whose constants packages will use). The #28/#72 backfill is still missing. About 35 more kernel symbols are queued: #160 (12 files), #112 (6), spec #128 K1 (a field type and an event), PR C (about 15). | Build #67 as one small Core PR **now**: the command, `docs/architecture/kernel-api.txt`, and a CI diff that needs a changelog line. Backfill #28, #72, #58 and #122 in the same PR. Make it a merge precondition for #160 and PR C. | **Yes**: #160, PR C, #112 |
| F2 | **Registrars are being built on the module SPI, the reverse of ADR 0009.** ADR 0009 §PR C says the prerequisites rebase on PR C. #164 instead: adds `findSubmission()` to `Modules/Domains/Drivers/Registrars/Registrar.php` (a new method on an SPI, which ADR 0010 forbids); adds a string capability `reconcile` (ADR 0012 asks for an interface); takes the Eloquent `Domain`; and adds a module `SubmissionLookup` with its own statuses (`absent`, and no `conflict`). Meanwhile the kernel `App\Enums\ReconcileStatus` docblock says registrars share it. #168 adds a second real implementer. A third-party registrar package cannot be written today, because `ExtensionsTest` forbids module imports. | (1) In #164, before merge: `SubmissionLookup::$status` becomes `ReconcileStatus` (`absent` maps to `NotExecuted`, and "registered, not in our account" maps to `Conflict`). The change is small, and PR C then moves the class without renaming anything. (2) PR C (kernel `RegistrarDriver`, `RegistrarConfig`, a `DomainRef` snapshot, `ReconcilesSubmissions`, `RegistrarDriverRegistry`) is a **hard gate before ResellerClub**. It migrates `log`, `np_manual` and the fake. #164 and #168 may merge on the module SPI. | **Yes**: ResellerClub, third-party registrars. No for #164/#168 after fix (1). |
| F3 | **Step-up still belongs to Platform, and the gap grew.** The probe found `password.recent` unresolved on 14 Services routes (7 at baseline), 11 Notifications routes and 2 Domains routes (new). With `MODULES=` they fail closed with a 500. Billing still carries its private fallback (`BillingServiceProvider.php:100`). ADR 0018 §4 is Proposed, and #112 is stale. #168's follow-up screens will add more sensitive Domains routes. | Cut #112 down to ADR 0018 §4 alone: `password.recent` aliased in Core, Billing's copy deleted, and the kernel test that boots each module alone and resolves every alias. Accept ADR 0018 §4 on its own; the rest stays Proposed with social sign-in. | No feature is blocked, but every new sensitive route widens the gap. Do it before #168's screens. |
| F4 | **Background work has two competing patterns, and ADR 0017 is half built.** #122 delivered §3 (the `automation` connection) well. Since then: <br>• #152 added `billing:reconcile-payments --only=late`, hourly, inline in the scheduler, up to 300 s plus the HTTP timeout. <br>• #164 puts registrar `findSubmission` calls into hourly `domains:sync`. <br>That makes four inline provider sweeps. None uses `runInBackground()`, and the events due in a minute run one after another, so at hh:00 the late sweep, `domains:sync` and `automation:run` (default "03:00") queue behind each other. The `payments` and `registrar` queues still have no jobs; the Domains jobs are on `default`. Queue names are string literals in 6 jobs or providers, against ADR 0014's "constants only" rule, and Services and Notifications still register dev workers. `after_commit` is `false` on every connection. #160's `imports` queue on the default pool is fine, but its "never delays other work" claim is wrong: priority applies only when a worker picks the next job, so a 120 s import job holds that worker. | Amend and **accept ADR 0017 §2**: a sweep may call providers inline only with `->runInBackground()`, a wall-clock deadline below its interval, a per-provider breaker and a cap (which is what #152 already has, minus the first). Otherwise it dispatches one unique job per item. Then one PR: <br>• `runInBackground()` on the four sweeps; <br>• Domains jobs on `Queues::REGISTRAR`; <br>• an Arch rule that forbids queue-name literals; <br>• one Core dev worker; <br>• `after_commit => true` with a full SQLite and PostgreSQL run. <br>Close #82, which #122 fixed. | **Yes**: ResellerClub (registrar queue, sweep). Redis is blocked by `after_commit`. |
| F5 | **Hot paths cost what they do for structural reasons, and #166 treats the symptoms.** #166 is right: aggregates in SQL, trigram indexes, query budgets. The causes it leaves are these. <br>(a) Notifications does its work in the producer's process. `SendEventEmails` is a synchronous catch-all listener: template, translations, preferences, dedupe, pull-based `MergeFieldRegistry::resolve()` per entity, the `email_messages` insert and the job insert. `PublishEventWebhooks` reads the endpoints per event. That is about 10 of the 43 queries per renewal, inside the automation run. <br>(b) There is no per-job identity cache. The same client is read 6 times per renewal across Billing (3) and Notifications (3, including `MergeContext::client()` doing `Client::find` and then the registry resolver for the same id). ADR 0011's "ids, not models" is correct, but it makes every consumer re-read. <br>(c) The `Dashboard` and `GlobalSearch` registries are bare closures, with no cache, budget or per-provider limit. Each module invents its own caching (MRR for 60 s in #166). | Draft ADR 0020 (below). <br>• Event-driven mail and webhooks run as queued, after-commit listeners on `notifications`, so the producer pays one job insert. <br>• Kernel read services for entities that are read on hot paths (client summary first) are `scoped` and memoised per request or job, as `CachedCurrencyDirectory` already is. <br>• `Dashboard::widget()` gains a central `cacheSeconds`, and `GlobalSearch` a per-provider limit and a minimum term length. <br>• A per-renewal query budget test (target ≤ 20). <br>The renewal PR crosses Billing and Notifications, so the architect reviews the brief first. | **Yes**: go-live (load test). |
| F6 | **ADR 0013 (Accepted 10-19) is not started, and Nepal-first code keeps adding to it.** <br>• Three clocks still read the unregistered `system.timezone`. There are 82 UTC date reads in modules (81 at baseline). <br>• New: `np_manual` (#168) uses `CarbonImmutable::today()` and a business-day rule hard-coded in the driver ("Saturdays are skipped"). <br>• `PublishInvoice` takes the issue date from UTC `today()`, so an invoice issued between 00:00 and 05:45 in Kathmandu carries yesterday's date. <br>• `DocumentNumbers` resets on the Gregorian `Y` of a UTC date. It cannot express a fiscal year (India: April; Nepal: 1 Shrawan, which falls in mid-July and moves by a day). <br>• The WHMCS importer needs the "imported instants" rule. | ADR 0013 steps 1–2 now: the `CompanyClock` contract, `core.timezone` with the migration, automation in company time and the Kathmandu test. **Amend ADR 0013** with `CompanyClock::isBusinessDay()`/`addBusinessDays()` (working week and holidays as Core settings) and a fiscal-year strategy (`FiscalCalendar`: Gregorian with a start month, or Bikram Sambat) that `DocumentNumbers` uses. **Owner decision**: do Nepali tax invoices need BS dates and numbering per BS fiscal year? Check with an accountant before VAT work. | **Yes**: Nepal launch, WHMCS importer dates. Not #168 (it migrates later). |
| F7 | **Nepal payments and VAT would land on the old gateway and tax shapes.** <br>• `GatewayDriver` still has 11 string capability flags. `settingsFields()` returns arrays, not `SettingField`. `GatewayRegistry` is bound by Billing (K5). Stripe and #152 extended this shape. <br>• Spec #128 K1 adds a `certificate` field type (to both shapes) and `App\Events\Billing\GatewayCredentialExpiring`, although registrar and panel credentials expire too. <br>• PaymentsNepal has not adopted `Endpoints` (ADR 0016 S5), so connectIPS and Fonepay simulators have nothing to build on. <br>• `TaxEngine` is an EU-plus-India engine run by flags (`VatNumber` reverse charge, GSTIN). Nepal VAT at 13 % with buyer and seller PAN would be a third inline branch. <br>• The seller identity is still read as `billing.seller.*` keys from Notifications (B2). | Order: <br>1. Gateway alignment (K5: interfaces, kernel `GatewayDriverRegistry`, `SettingField`). <br>2. S5 (`Endpoints` in PaymentsNepal). <br>3. #128 K1, made family-neutral: `FieldType::Certificate`, a capability `ReportsCredentialExpiry`, `App\Events\Integrations\IntegrationCredentialExpiring`, and the ADR 0014 `HealthChecks` registry instead of another dashboard closure. <br>4. connectIPS. <br>For tax, a Billing-internal `TaxRegime` strategy keyed by the seller's home country (EU VAT, India GST, Nepal VAT) before Nepal VAT. Not kernel: no third party needs it yet. A Core seller-identity contract (PAN or VAT number, legal name) replaces the cross-module keys. | **Yes**: connectIPS (gateway alignment), Nepal VAT. Fonepay waits on its spec anyway. |

## Module boundaries

- `ArchitectureTest`, `ExtensionsTest` and `HygieneTest` hold. No sibling imports. The kernel imports no module.
- The isolation probe (`MODULES=<m>`, HTTP kernel booted, every route alias resolved) fails only on `password.recent`
  (F3). Migration alone is clean.
- The couplings the `use` checks cannot see are unchanged since the baseline: settings keys read across modules (B2),
  Core's hard-coded role permissions (B3), and links guarded by `Route::has()` (B4, acceptable).
- Registries now come in three shapes: kernel `final` classes (ADR 0012), Contracts interfaces implemented by a module
  (`GatewayRegistry`, `MergeFieldRegistry`, and the proposed `ImportRegistry` in #160) and closure lists (`Dashboard`,
  `GlobalSearch`). #160's review already asks for the ADR 0012 shape. The other two move when their family is aligned
  (F7, F5).

## ADR status

| ADR | Status | Drift and decision |
| --- | ------ | ------------------ |
| 0009 extension contracts | Accepted | PR C not started; prerequisites inverted (F2) |
| 0010 evolution | Accepted | Not enforced (F1); #164 adds a method to an SPI (F2) |
| 0011 events | Accepted | Adoption PR not scheduled. 39 old-helper references, plus `ShouldHandleEventsAfterCommit` on `PublishEventWebhooks` as a fifth mechanism. Schedule it after F4's `after_commit` change. |
| 0012 capabilities and registries | Accepted | Gateways (F7), registrars (F2), importer registry (#160 review) |
| 0013 company clock | Accepted | Not built. Amend for the working week and fiscal year (F6). |
| 0014 queues and observability | Queue section accepted | Amended for `automation` (#122) and `imports` (#160). Drift: registrar jobs on `default`, literals, module dev workers (F4). Observability is still Proposed with nothing built (no `Context`, `single` plain-text logs): give it a date before the first real host. |
| 0015 outbound policy | Proposed | In force since S0; **accept** it as is. |
| 0016 simulators | Accepted | S4 is in, with an accepted one-runner deviation. **Drift**: the E2E job runs only on `workflow_dispatch`, on pushes to `main` (not yet cut) and on the `e2e` label, so in practice it never runs, and CTO decision 5 ("required after 10 consecutive green runs") cannot be met. Amend: a nightly `schedule:` run on `develop` (about 2 billable minutes, about 60 a month within the 3,000 cap) counts toward the 10. S5 is still open (F7). |
| 0017 background work | Proposed | §3 built (#122). §1, §2 and §4 not built, and a competing pattern exists. **Amend §2 and accept** (F4). |
| 0018 sign-in | Proposed | Accept §4 alone now (F3). |

## Fit of the upcoming work

| Work | Fit | Decision needed before it lands |
| ---- | --- | ------------------------------- |
| Client importer kernel #160 (A0–A3) | Good shape: `RecordImporter` in the kernel because every owning module implements it, and connectors outside it. Its own review asks for the ADR 0012 registry, provider-contract wording, dry-run semantics and one name for the source. | F1 snapshot first. Keep the connector SPI inside Migration until a connector ships outside it (the K3 rule: a kernel symbol lands with its first producer and consumer). |
| Registrars (#164, #168, ResellerClub) | #164's harness, stale-claim sweep and remote ids are good. The `np_manual` workflow needs no API and fits the SPI. | F2 (1) in #164; PR C before ResellerClub; registrar queue and background sweep (F4). |
| WHMCS API simulator (planned) | Fits ADR 0016 as a `SimulatorRegistry` entry in `tools/simulators`. WHMCS is a staff-supplied host, not a fixed-host provider, so use the cPanel trust-flow pattern (`OutboundAddressGuard`, loopback only in `local`/`testing`), **not** `Endpoints`. | Clean-room rule: build responses from the public API reference in our own words and fixtures, with no verbatim sample payloads. Any recording from a real install is anonymised and owner-approved. The simulator serves a non-UTC source time zone and paginates, so the importer proves the ADR 0013 import-instant rule and resumable paging. |
| Nepal-first (connectIPS, Fonepay, `.np`, NPR, VAT) | NPR money is fine (minor units, Indian grouping, whole rounding). The `.np` manual registrar fits. connectIPS fits `HostedPaymentDriver` plus lookup without contract change, apart from the certificate. | F6 (company clock, working week, fiscal calendar, BS question to the owner) and F7 (gateway alignment, family-neutral credential expiry, tax regime, seller identity). |

## Follow-ups in priority order

1. **#67 snapshot PR**, with the changelog backfill (#28, #72, #58, #122); a precondition for #160 and PR C (F1).
2. **#164 fix**: `SubmissionLookup` on `ReconcileStatus` before merge (F2 (1)).
3. **Cut #112** to Core `password.recent` plus the per-module alias test; accept ADR 0018 §4 (F3).
4. **ADR 0013 steps 1–2**, plus the amendment for the working week and fiscal calendar; ask the owner the BS
   question (F6).
5. **ADR 0017 amendment and acceptance**, then the background-work PR: `runInBackground()`, the registrar queue,
   the literals rule, one dev worker, `after_commit` (F4). Close #82.
6. **Renewal performance PR** under draft ADR 0020: queued Notifications listeners, scoped client reads, and a
   per-renewal query budget. The architect reviews the brief (F5).
7. **Gateway alignment (K5), then S5, then #128 K1** (family-neutral), then connectIPS (F7).
8. **Registrar PR C**, a hard gate before ResellerClub (F2 (2)).
9. ADR 0016 amendment: a nightly E2E run on `develop`. Accept ADR 0015.
10. ADR 0011 adoption PR, after item 5. A date for the ADR 0014 observability PR (`Context`, JSON logs,
    `HealthChecks`).

## ADRs

- Drafted: **0020 Hot-path reads and event side effects** (Proposed). The CTO commits it as
  `billing/docs/adr/0020-hot-path-reads-and-event-side-effects.md`. It covers F5.
- Amend: **0013** (working week and holidays, fiscal calendar, imported instants: F6), **0016** (E2E cadence),
  **0017** §2 (bounded inline sweeps) and accept it, **0014** (correct the `imports` "never delays" wording in #160).
- Accept: **0015** as is; **0018** §4 alone.
