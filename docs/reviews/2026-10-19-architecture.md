# Architecture review — 2026-10-19

- Reviewer: Principal architect. Previous review: `billing/docs/architecture/review-2026-10-18.md`.
- Scope: `develop` at `685da6f`. In-flight PRs (#58, #77, #78, #79) were read only for their kernel file lists.
- Method: read only. `tests/Arch` passes (25 tests). Module isolation was probed with `MODULES=<list> php artisan
  tinker`. Paths are relative to `billing/`.

## Summary

The provisioning SPI (PR A, B, B2) and the cPanel driver are well built: snapshots instead of models, four
outcomes, a host-owned verify loop, and redaction in one place. Four things need decisions this week:

1. Three Accepted ADRs (0010, 0011, 0012) are not built.
2. The hand-kept kernel changelog has already missed a whole area.
3. Step-up authentication breaks when Platform is absent.
4. The queue and scheduler topology in the deployment plan does not match the code.

## 1. Kernel surface growth

Kernel today: 40 contracts, 52 DTOs, 107 events, 8 enums and 34 support classes. Since #61, 47 kernel files were
added (15 contracts, 16 DTOs, 8 events, 4 enums, 4 support) and 160 were changed, most of them by the `DomainEvent`
adoption.

**Coherent.** One field schema (`SettingField`), one connection result (`ConnectionCheck` plus steps), one redactor,
one outbound guard, and capability interfaces in place of flags for provisioning. Every DTO addition since #61 was a
trailing defaulted field, as ADR 0010 requires.

**Findings**

| #   | Finding | Action |
| --- | ------- | ------ |
| K1  | `docs/architecture/kernel-changelog.md` lists none of the Notifications kernel additions from #28 and #72: `AuthMailer`, `SystemMailer`, `SystemMailOutcome`, `SensitiveValue`, `EmailTemplates`, `DomainEvent`, the 7 `Events\Notifications\*` and the new `Brand` fields. `App\Support\Kernel::API_VERSION` does not exist. ADR 0010 is Accepted but has no enforcement. | Build `kernel:api-snapshot` (#67) next (see below). |
| K2  | DTOs are filling up one driver at a time. `DriverMetadata` has 9 positional parameters, `ProvisioningContext` 9, `ServerConnection` 13. Seven trailing fields were added since PR B, all for cPanel. `ServerConnection::$accessHash` is a WHM-only credential slot. `DriverMetadata::$connectionFields` keys on magic strings (`username`, `api_token`, `access_hash`, ...). | ADR 0010 amendment: after 6 positional parameters a DTO takes named arguments only. Add an `App\Enums\ServerCredential` backed enum for the `connectionFields` keys (compatible: same string values). |
| K3  | `Events\Services\ServiceImported` is in the kernel, but nothing dispatches or reads it. | Keep it, but dispatch it in cPanel PR 3. From now on a kernel symbol lands only together with its first producer and its first consumer. |
| K4  | Sensitivity is declared two ways: as a type (`Data\Notifications\SensitiveValue`) and as a flag (`SystemMailer::send(..., bool $carriesSecret)`, `Contracts/Notifications/SystemMailer.php:22`, 23 references). `SensitiveValue` is a security primitive used by Notifications, Platform and Support, but it lives in a Notifications namespace. | Derive "carries a secret" from the context and deprecate the flag. Move the class to `App\Data\Security\SensitiveValue` while we are still pre-1.0. |
| K5  | ADR 0012 is not applied to gateways: `GatewayRegistry::register(GatewayDriver $instance)` is still bound by Billing, and 11 capability flags remain, 5 of them dead (`Contracts/Billing/GatewayDriver.php:22-42`). Stripe (#58) adds a third implementer of the old shape. | Align gateways (kernel `GatewayDriverRegistry`, interfaces, flag/interface consistency test) right after #58 and before Razorpay or PayPal. |
| K6  | Connection-test interfaces have three names: `ConnectionTester` (gateways), `TestsServerConnection` (provisioning) and `TestsRegistrarConnection` (planned in ADR 0009). Reconciliation is on the same path: `ReconcilesCommands` + `CommandLookup` + `ReconcileStatus` in provisioning, and `ReconcilesSubmissions` + `SubmissionLookup` planned for registrars. | PR C reuses `DriverOutcome` and `ReconcileStatus`, which already have family-neutral names. Capability names are verb phrases; alias `ConnectionTester` and deprecate it. |

**What blocks the next kernel consumers**

| Consumer | Blocker | Needed first |
| -------- | ------- | ------------ |
| PR C registrars | Snapshot missing (K1); reconcile vocabulary undecided (K6); no `registrar` queue (Q1) | #67; a PR C brief that fixes K6; `App\Support\Queues` |
| S1 simulator framework (about 2,200 lines, adds a registry) | K1; ADR 0015 still Proposed although S0 amends it | #67; accept 0015 together with S0 |
| S4/S5 E2E and the NPR scenario | Due dates and automation run on the UTC date (T1), so the Kathmandu scenario tests the wrong calendar | ADR 0013 steps 1–2 |
| WHMCS importer (3.5) | `ImportRegistry` in `migration.md` §12 is a Contracts interface implemented by Core, a second registry pattern next to ADR 0012. WHMCS timestamps are in the source server's time zone (T1). `ServiceImported` is unused (K3). | Make the registry a `final` kernel class in `App\Support\Migration` (ADR 0012 shape); ADR 0013 decides how imported instants are converted |

**Should `kernel:api-snapshot` come first?** Yes, before S1 and PR C, which are the next two large kernel additions.
It does not need to wait for S0 (#77, about 400 lines, already in review). It is small: a Core command, a checked-in
`docs/architecture/kernel-api.txt`, and a CI diff that fails when the snapshot changes without a kernel-changelog
entry. K1 shows that review alone does not catch omissions.

## 2. Module boundaries

The three Arch files (`ArchitectureTest`, `ExtensionsTest`, `HygieneTest`) hold. No module imports a sibling, the
kernel imports no module, and the module list is derived from a glob (D10 of the last review is fixed). The drift is
in couplings that `use` checks cannot see.

| #   | Finding | Action |
| --- | ------- | ------- |
| B1  | **Step-up auth is owned by a feature module.** Platform registers the `password.recent` alias (`PlatformServiceProvider.php:65`), Billing registers a copy as a fallback (`BillingServiceProvider.php:100`, `Http/Middleware/ConfirmPasswordForAction.php`), and Services and Notifications register nothing. Probe: `MODULES=Services` has 7 routes and `MODULES=Notifications` has 11 routes behind an unregistered alias, which fails closed with a 500, for example on server credentials (`Services/routes/web.php:19,40`). | Move the middleware into Core, alias it there, and delete Billing's copy. Add a CI step that boots each module alone (`MODULES=$m php artisan route:list`) and checks that every middleware alias resolves. |
| B2  | **Settings keys cross modules.** Notifications reads `billing.pay_to_text` and `billing.seller.legal_name` (`MergeContext.php:42`, `MailFooter.php:34`, `MailFramer.php:334`). Domains reads `billing.invoice_lead_days` (`DomainDates.php:22`) and PaymentsNepal reads `billing.gateways.http_timeout_seconds` (`ProviderHttp.php:63`). These keys are unversioned public API, and when Billing is absent the reads fall back silently. | Seller identity becomes a `Brand`/seller DTO through a contract. ADR 0010 lists setting keys read by another module as public surface. |
| B3  | **Core knows feature modules.** `Core/Install/SystemRoles.php:39-49` hard-codes Billing, Orders, Support, Services and Domains permissions, and `Core/Install/EnvironmentChecks.php:182` checks Platform by its provider class string. | Roles take permissions by area wildcard from the `PermissionRegistry` at install. Low priority. |
| B4  | **Route-name coupling** (`Orders/Services/InvoiceLinks.php`, `Notifications/Services/MergeContext.php:195`, `Domains/.../DomainController.php:133`) is guarded with `Route::has()`. | Acceptable. Document it as the pattern for cross-module links. |

## 3. ADR status

| ADR | Status | In code | Decision needed |
| --- | ------ | ------- | --------------- |
| 0009 extension contracts | Accepted | PR A, B, B2 done; PR C and D open | PR C brief (K6) |
| 0010 evolution | Accepted | No `API_VERSION`, no snapshot (K1) | Build #67 now |
| 0011 events | Accepted | `DomainEvent` and occurrence ids done; `occurredAt` missing; no event implements `ShouldDispatchAfterCommit`; the four after-commit mechanisms remain (55 references); `WebhookEventTypes::MAP` is still closed | Schedule the adoption PR, or amend the ADR |
| 0012 capabilities and registries | Accepted | Provisioning done; gateways not (K5); registrars in PR C | Gateway alignment after #58 |
| 0013 company clock | Proposed | Not in force, and drifting: three clocks (`Orders/Services/CompanyClock`, `Core/Support/BusinessCalendar`, `Notifications/Support/MailClock`) read the unregistered `system.timezone`. The ADR names `core.timezone`. Automation runs on the UTC date (`RunAutomation.php:41`) at a UTC time (`CoreServiceProvider.php:85`). | **Accept**, using `core.timezone`, plus a migration that copies any `system.timezone` row; steps 1–2 before S4 and the importer |
| 0014 observability and queues | Proposed | **De facto in force for queues**: `install.md` §6, the deployment plan and `services.php:56` cite its pools and invariant. The rest is not built: no `Queues` constants, no `Context`, plain-text logs, no `HealthChecks`. | **Accept the queue-topology section now**, with the corrections in §4. Keep observability Proposed, with a date. |
| 0015 outbound policy | Proposed | **In force**: `OutboundAddressGuard` is used by cPanel and described in `architecture.md` as the rule. #77 amends it. | **Accept with #77**. The DNS deadline (#67) and the webhook profile stay open items. |
| 0016 simulators | Accepted | S0 in review | — |

## 4. Scalability and operations

**Queues and pools**

- Q1. Code uses four queues: `provisioning`, `notifications`, `webhooks` and `default`. The pools in `install.md:165`
  and `deployment-options.md:93` include `payments` and `registrar`, which no job uses. Domains' registrar jobs,
  Orders' `ProvisionOrderItem` and Platform's jobs all run on `default`, so a registrar outage still starves other
  `default` work (D8 of the last review is still open). Action: add `App\Support\Queues` and move the Domains jobs
  to `registrar` before PR C.
- Q2. `RunAutomationJob` ("Run now") has `$timeout = 3600` (`Platform/Jobs/RunAutomationJob.php:21`), on `default`,
  with `retry_after = 240` (`config/queue.php:43`). After 240 s the database queue hands the still-running job to a
  second worker, which fails it as having exceeded its attempts while the first run continues. The automation lock
  prevents a double run, but the failed job is misleading and the run blocks the `default` pool for up to an hour.
  Action: give it an `automation` queue whose `retry_after` is above its timeout, or have the scheduler process
  pick up a "run requested" flag.
- Q3. The `app:install` hint prints `--timeout=85` for the payments, provisioning and registrar pool
  (`Core/Console/Commands/InstallCommand.php:217`), while `install.md:165` says 200 and the provisioning cap is
  180 s. Jobs without their own `$timeout` (Domains, Orders) are killed at 85 s.
- Q4. Jobs are made after-commit three ways: `->afterCommit()` (Services, Notifications), `DB::afterCommit` closures
  (`Domains/Listeners/HandleInvoicePaid.php:86`), and not at all (`DomainActionController.php:118`,
  `ResubmitTransfer.php:27`, `PlaceOrder.php:510`). This is safe today only because the `database` queue writes the
  job row inside the same transaction. Moving `QUEUE_CONNECTION` to Redis, which the deployment plan allows, changes
  the semantics silently. Action: set `after_commit => true` on every connection in `config/queue.php` (ADR 0017).

**Scheduler**

- S1. Three sub-daily commands call providers inline in the single scheduler process, with no `runInBackground()`:
  `services:verify-unknown` every 5 minutes, which builds contexts without a deadline
  (`VerifyUnknownOutcomes.php:195`); `billing:reconcile-payments` every 5 minutes; and `domains:sync` hourly. A hung
  WHM server or gateway delays the heartbeat, webhook retries and `automation:run`. Action: the sweeps select rows
  and dispatch one `ShouldBeUnique` job per item, on the family's queue (ADR 0017).
- S2. `support:auto-close` is both an hourly schedule entry (`SupportServiceProvider.php:53`) and a HOUSEKEEPING
  task (`:322`). `architecture.md` contradicts itself: "never ad-hoc scheduler entries" under Automation, and
  "registered in the provider's `schedule()`" under Queues. Rule: daily work is a task; sub-daily polling is a
  schedule entry with `onOneServer()` that only dispatches.

**Locks (#64)** — There are 10 `Cache::lock` sites. Besides the provisioning service lock, they include payment
capture (`Billing/Actions/CaptureInvoice.php:40`), payment batches and checkout idempotency (`PlaceOrder.php:88`).
`ShouldBeUnique`, `withoutOverlapping` and `onOneServer` all depend on the same store. `EnvironmentChecks.php:106`
checks the queue driver but not the cache store. Action: #64 covers payments too. The pre-flight fails on `array`,
and on `file` whenever more than one host or container runs the app (Docker compose has `app` plus 3 workers plus
the scheduler, so `file` fails there too, unless every container shares one volume).

**Static state (Octane later)** — Low risk. There are only two mutable statics (`DemoSeeder::$running`, and
`CachedCurrencyDirectory::$generation`, which is safe because the directory is `scoped`). Settings memoise through
`Cache::memo()`, and `ThemeManager` memoises presets only. Before Octane: `RegistrarManager::driver()` mutates a
driver per call with `configure()` (removed by PR C), and `AccountLinks` swaps the `url` instance. Add an Arch rule
against new mutable static properties outside an allow-list.

**Indexes for the verify sweep** — The sweep filters `module_queue` on `subject_type`, `kind`, `status = open` and
`updated_at`, paging by id (`VerifyUnknownOutcomes.php:72`). Only `status` and `(subject_type, subject_id)` are
indexed (`2026_10_17_400001_create_services_tables.php:205,208`). This is fine at today's volumes, because open
items are a backlog. Add `(status, kind, updated_at)` with `OnlineIndex` in cPanel PR 2. The per-item attempt lookup
is covered by `module_commands (service_id, id)`.

**Observability** — Nothing uses `Context`, logs are `single` plain text, and there is no health registry. Nothing
can correlate a failed provisioning job with the order that caused it. This is the main operational gap before the
first real host.

## 5. Top five risks for the next month

| # | Risk | Proposed action | Owner |
| - | ---- | --------------- | ----- |
| 1 | Kernel changes land unrecorded as S1, PR C, gateway alignment and the importer add an estimated 40 more kernel symbols (K1, K2) | #67 snapshot and CI diff first; ADR 0010 amendment on positional parameters; `kernel-changelog.md` backfilled for #28 and #72 | Kernel engineer, architect |
| 2 | Accepted ADRs are not enforced, so new code copies the current patterns: four after-commit mechanisms, gateway flags | One ADR 0011 adoption PR (`ShouldDispatchAfterCommit`, `occurredAt`, Arch rule, removal of `AfterCommit`/`DomainEvents`) on SQLite and PostgreSQL; gateway alignment after #58 | Kernel engineer |
| 3 | The production topology does not match the code: missing queues, the wrong timeout hint, provider I/O in the scheduler, locks that need a shared store (Q1–Q4, S1, #64) | Accept the ADR 0014 queue section; ADR 0017; `Queues` constants; pre-flight store check; fix the `InstallCommand` hint. Do this before D1 (#79) ships an image. | DevOps engineer, Services owner |
| 4 | The wrong calendar in Nepal and India, on top of three competing clocks (T1). Every new date feature adds `today()` calls (about 90 UTC `today()`/`now()` date reads in modules). | Accept ADR 0013; a Core `CompanyClock` contract plus the registered `core.timezone`; automation scheduled and dated in company time; the Kathmandu test; Orders, Notifications and Core migrate to it | Core owner |
| 5 | Isolation regressions are invisible: no CI job boots a module alone (B1) | A CI matrix step per module: boot, `route:list`, middleware aliases resolve, `tests/Feature/<Module>` with `MODULES=<Module>` | DevOps engineer |

## 6. Follow-ups in priority order

1. Core `password.recent` (B1), with the per-module boot step in CI. Small, and a security primitive.
2. #67 `kernel:api-snapshot` plus the `API_VERSION` constant; backfill the changelog (K1). Before S1 and PR C.
3. Decisions for the CTO: accept 0015 with #77; accept 0013; accept the 0014 queue section; review ADR 0017.
4. `App\Support\Queues`, the `registrar` queue, `after_commit => true`, the fixed `InstallCommand` hint and the
   `RunAutomationJob` queue (Q1–Q4). #64 widened to payments.
5. Scheduler sweeps dispatch jobs instead of calling providers inline (S1).
6. ADR 0011 adoption PR.
7. ADR 0013 steps 1–2, before S4 and the importer.
8. PR C brief: reuse `DriverOutcome` and `ReconcileStatus`, capability naming, `ServerCredential`-style enums (K2, K6).
9. Gateway family on ADR 0012, after #58 (K5).
10. `SensitiveValue` move and deprecation of `carriesSecret` (K4); the seller identity contract (B2).
11. Docs drift: the `architecture.md` contracts table (6 rows for 40 contracts; D12 of the last review) and the
    Arch file list; this review's S2 wording; `migration.md` §12 registry shape.

## ADRs

- Drafted: [0017 Background work: dispatch, scheduler and locks](../../billing/docs/adr/0017-background-work-dispatch-scheduler-locks.md)
  (Proposed). It covers Q4, S1, S2 and #64.
- Amend: ADR 0010 (named arguments after 6 parameters; settings keys read by other modules count as public surface),
  ADR 0014 (split it, so queue topology is accepted on its own).
