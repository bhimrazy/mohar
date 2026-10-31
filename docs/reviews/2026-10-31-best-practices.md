# Best-practices audit — 2026-10-31

- Reviewer: principal architect. The first whole-codebase best-practices audit, against Laravel, testing, Inertia
  and React, Tailwind and Wayfinder conventions.
- Scope: `develop` at `a5b803bf` (#562). Application code is the same as `b0d75caf` (#552); #562 changed docs only.
  1,669 PHP files in `app/`, 706 test files, 361 TSX files.
- Checklists: the Boost skills in `billing/.claude/skills/` (`laravel-best-practices`, `testing-best-practices`,
  `inertia-react-development`, `tailwindcss-development`, `fortify-development`, `wayfinder-development`,
  `infer-conventions`), `billing/docs/architecture.md`, ADRs 0001 to 0029, `docs/handoff/review-rules.md` and
  `docs/handoff/product-standards.md`.
- Earlier reviews read, so this one reports only what is new or still open: CTO 10-29, architecture 10-20 and
  10-23, security 10-29 and 10-31, infra security 10-31, database architecture 10-31. Their findings are not
  repeated here (the indexes, retention, CHECK constraints and fiscal immutability are in the database review; step-up
  and inbound mail are in the security review).
- Method: a sample per area, not a full scan. Each claim below comes from a grep or a file read at the line cited.
  Paths are relative to `billing/` unless they start with `docs/`, `deploy/`, `scripts/` or `.github/`.
- What ran:
    - `composer install` (locked) in the worktree;
    - PHPStan level 7 on `app/Modules/Billing`, `app/Modules/Orders` and `app/Support`, through `scripts/heavy.sh`.
      It took 11.6 s and found 0 real errors. The one error it reported is an artifact of analysing a partial path:
      `IdentifiesOccurrence` "used zero times", because the events that use it were outside the paths.
    - `pest tests/Feature/Orders/PlaceOrderTest.php --filter=provision`: 2 passed, 5 assertions.
    - The bundle sizes and CI times come from the `develop` CI run 41205276742 (10-31 13:22 UTC).
- Not run: the full test suite (the machine was at load 6.8), a frontend build, a browser or accessibility tool, and
  PostgreSQL. No finding below has a red probe yet. They were all found by reading the code, so each suggested fix
  starts with a test.

## Summary

**No blockers.** The codebase follows Laravel and React practice more closely than most projects of its age:

- strict models;
- attribute-based `#[Fillable]` on 105 of 106 models, and no `$guarded = []`;
- no `env()` outside `config/`;
- PHPStan level 7 with no baseline and no `@phpstan-ignore`;
- every scheduled task has overlap protection;
- every outbound HTTP client sets a timeout;
- money writes are transactional and row-locked;
- module boundaries hold;
- TypeScript is strict, with no `any`, and no internal URL is hard-coded outside the dev pages.

What is missing is mostly structure that the ADRs promise and the code has not built yet:

1. Core is a second kernel that is not versioned (H1).
2. Requests and jobs carry no correlation id (H2).
3. The after-commit convention still covers 5 of 118 events (H3).
4. Authorization is enforced in three different places, and no sweep test checks all of them (M1, M2).

| Area                          | Score | Why                                                                                                                                                  |
| ----------------------------- | ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Laravel conventions        | 82    | Strict models, Fillable, casts, scheduler, money transactions and caching are sound. Validation and authorization each follow two conventions (M1). |
| 2. Architecture               | 74    | No sibling-module imports. Core is a de facto unversioned kernel (H1). ADR 0011 has drifted (H3). One orphan capability (M4).                         |
| 3. Security (quick pass)      | 88    | Nothing new at High. Markdown is sanitised, upload rules and raw SQL are safe. No route-wide permission sweep (M2).                                   |
| 4. Testing                    | 70    | 516 feature tests and 174 Inertia assertions, with real fakes. No coverage or mutation signal on money paths (M6). Timing assertions remain (M5).     |
| 5. Frontend                   | 81    | Wayfinder everywhere, strict TypeScript, page-split bundle. Dead starter-kit code, two fetch patterns, no translation mechanism yet (M8, L1 to L5).  |
| 6. Performance and operations | 70    | Timeouts and the queue topology are documented and mostly followed. No request correlation (H2). One provisioning retry gap (M3).                    |
| 7. Developer experience       | 80    | `scripts/test.sh --changed`, `heavy.sh`, CI wall time 5.7 min, accurate devops docs. The pre-push gate recommended on 10-29 is not built (M7).        |
| **Unweighted mean**           | **78** |                                                                                                                                                     |

Verified good, to tell coverage from silence (the evidence is in the area notes at the end): Eloquent strictness, mass
assignment, `env()`, PHPStan, scheduler overlap, money transactions, cache invalidation, outbound timeouts, sibling
boundaries, raw SQL, XSS sinks, uploads, Wayfinder, TypeScript, Tailwind v4 syntax, image alt text, failed-job
retention and CI time.

## Findings

### Blocker

None.

### High

**H1. Core is a second kernel that the API snapshot and the kernel changelog do not see.**

- Evidence:
    - The other modules import 47 distinct `App\Modules\Core` classes. The most used are
      `Core\Support\ActivityLogger` (141 imports), `Core\Models\Client` (134), `Core\Support\Settings` (89),
      `Core\Models\Currency` (42), `Core\Support\OnlineIndex` (33), `Core\Support\ClientContext` (29) and
      `Core\Support\LockTimeout` (19).
    - `docs/architecture.md:40-45` allows these imports.
    - The snapshot scope in `docs/adr/0010-contract-evolution-and-versioning.md:136-137` is `app/Contracts`, `app/Data`,
      `app/Events`, `app/Enums` and `@api` classes in `app/Support`.
    - `docs/architecture/kernel-api.txt` has 0 lines for `App\Modules\Core`.
    - `app/Contracts/Core` holds only `CompanyClock`. Settings, the activity log and the client have no contract.
- Impact:
    - A change to `Settings::get()`, `ActivityLogger::log()` or a column of the `Client` model breaks every module, as
      a kernel change would. But it needs no kernel-changelog fragment and leaves no snapshot diff, so it does not
      trigger the architect gate.
    - This is the #472 failure class ("green alone, red together", CTO 10-29 §3.7), moved one namespace over.
    - For extension packages (ADR 0009, ADR 0012), the basics a gateway or report needs (settings, the audit log, the
      client) are only reachable through Core internals, which have no version promise. A package written today
      depends on an unversioned Eloquent model.
- Fix:
    - Write ADR 0030, "Core's public surface", with two options: (a) mark the Core classes other modules use with
      `@api` and extend the snapshot scope to `@api` classes under `app/Modules/Core`; (b) put `Settings`,
      `ActivityLogger` and a client read model behind kernel contracts, and keep the rest of Core internal.
    - Recommendation: (a) now, since it is mechanical and catches the next break, then (b) for the three classes
      packages need, before the first external package.
    - The CTO session makes this change (kernel).

**H2. No request or job correlation: the ADR 0014 observability decision is still not built (open since the 10-20 architecture review).**

- Evidence:
    - `docs/adr/0014-observability-and-queue-topology.md:3` says "observability sections remain Proposed".
    - §Correlation (`:24-33`) specifies a `request_id` middleware, `actor` and `client_id`, `automation_run`, and
      integration keys in `Context`.
    - Nothing in `app/` or `bootstrap/` imports `Illuminate\Support\Facades\Context`. No middleware sets or echoes
      `X-Request-Id` (`app/Http/Middleware/`, `bootstrap/app.php:26-60`).
    - The integration log contract (`:47-58`) lists a `request_id` column, which no migration adds.
    - What does exist: JSON logs to stderr in the image (`deploy/Dockerfile:201-202`), the redaction backstop and the
      health route (`bootstrap/app.php:24`).
- Impact:
    - When a payment callback, a provisioning job or the billing day fails in production, its log lines cannot be
      tied to the request, the staff user or the automation run that started it.
    - The integration log rows (`gateway_log`, `module_commands`, registrar log) cannot be joined to the application
      log.
    - Of everything in this audit, this costs the most during the first live incident.
- Fix:
    - Accept the ADR 0014 observability sections with the scope above.
    - One PR: a global middleware (a trusted `X-Request-Id` or a generated UUIDv7, echoed in the response), `actor` and
      `client_id` once resolved, `automation_run` in `automation:run`, and `integration` and `integration_key` in
      `ExecuteModuleCommand`, `RegistrarGateway` and the payment reconciler.
    - Laravel already carries `Context` into queued jobs and log records.
    - Test: one request that dispatches a job, asserting that both log records carry the same `request_id`.

**H3. The after-commit convention (ADR 0011) has not moved since 10-29 (still open).**

- Evidence:
    - 5 of 118 classes in `app/Events` implement `ShouldDispatchAfterCommit`.
    - 44 call sites still use `AfterCommit::dispatch`, `DB::afterCommit` or `ShouldHandleEventsAfterCommit`, for
      example `app/Modules/Billing/Actions/RefundTransaction.php:747,750` through the module-local helper
      `app/Modules/Billing/Support/AfterCommit.php:11-16`.
    - Every queue connection has `after_commit => false` (`config/queue.php:46,55,66,75`).
    - No arch test checks the interface. The only test that does is `tests/Unit/Billing/TaxNoteEventsTest.php:5-8`,
      for one event.
- Impact: a new event fired inside a transaction that rolls back is still delivered, unless its call site remembered
  the helper. This was CTO 10-29 change 1 and has not started.
- Fix: as the CTO proposed. Add an arch test with a frozen baseline that shrinks, migrate the events one module per
  PR, then delete `Billing\Support\AfterCommit`.

### Medium

**M1. Validation and authorization each follow two conventions.**

- Evidence:
    - `docs/architecture.md:168` says "validate (FormRequest) → call an Action/Service".
    - There are 100 Form Requests, and 47 inline `$request->validate()` or `Validator::make()` calls in 34 controllers,
      including money writes:
        - `app/Modules/Billing/Http/Controllers/Admin/InvoiceActionController.php:51` (cancel), `:113` (capture),
          `:131` (cancel lines), `:166` (bulk);
        - `app/Modules/Billing/Http/Controllers/Admin/TransactionController.php:154` (resolve refund);
        - `app/Modules/Migration/Http/Controllers/Admin/ClientImportController.php` (4 calls).
    - Authorization runs in three places:
        - `$this->authorize('<permission>')` in controllers (418 calls);
        - `FormRequest::authorize()` (for example `app/Modules/Domains/Http/Requests/DomainAreaRequest.php:14`);
        - `ClientContext::authorize()` in the client area (`app/Modules/Billing/Http/Controllers/Client/InvoiceController.php:57`).
    - All of them resolve through one `Gate::before` (`app/Providers/AppServiceProvider.php:166`).
    - No route uses a `can:` middleware.
- Impact:
    - Each convention works on its own, but a reviewer cannot tell a missing check from one made elsewhere.
    - In `DomainActionController` 2 of 9 actions call `authorize()` and the other 7 rely on their Form Request, so a
      new action added there with a plain `Request` would have no check.
    - The `laravel-best-practices` "consistency first" rule cuts both ways: the rule has to be written down for the
      next agent to follow it.
- Fix:
    - Write the rule in `architecture.md`: writes use a Form Request, which owns `authorize()`; reads may validate
      filter input inline; controllers call `authorize()` only for actions without a request body.
    - Move the money controllers above first. Add the sweep test from M2.

**M2. No sweep proves that every admin route refuses a staff user without the permission.**

- Evidence:
    - `tests/Feature/Security/SensitiveRouteStepUpTest.php:366` sweeps step-up, but only on routes that already
      check a sensitive permission.
    - `tests/Extensions/ModuleRoutesResolveTest.php:16` checks that middleware resolves.
    - No test walks `admin.*` as a zero-permission staff user.
- Impact: with three authorization sites (M1), a forgotten check passes every existing test. The per-controller
  tests only cover the routes their authors thought of.
- Fix: one sweep test. Log in a staff user with no role, request every `admin.*` route with route-model stubs, and
  expect 403 or a redirect to the step-up page, with an explicit allowlist (dashboard, profile). It fails once per new
  route that has no check.

**M3. A retried order-provisioning job returns success instead of retrying when the item lock is still held.**

- Evidence:
    - `app/Modules/Orders/Jobs/ProvisionOrderItem.php:15-35` has `$tries = 3` and `$backoff = [60, 300]`, and no
      `onQueue` or `$timeout`.
    - `app/Modules/Orders/Services/ItemProvisioner.php:30` takes `Cache::lock("orders.provision.item.{id}", 300)->get(...)`
      and returns `null` when the lock is not acquired.
    - The job ignores that `null` (`:34`).
    - ADR 0014 (`docs/adr/0014-observability-and-queue-topology.md:116`) puts order provisioning on `default`, worked
      with `--timeout=150` (`docs/devops/production.md:23`).
    - The synchronous create is capped at `sync_timeout_seconds` (60 s, `app/Modules/Services/Config/services.php:62`;
      `ModuleCommandDispatcher.php:41-51`), so a normal run fits.
- Impact:
    - If the worker dies during the create (OOM, a deploy past the stop grace, a node loss), the 300 s lock outlives
      it. Attempt 2 arrives 60 s later, finds the lock, and ends as a success.
    - Attempt 3 is never queued, and the order item stays `Pending`.
    - Recovery then depends on `services:verify-unknown` and `TrackItemActivation` noticing the service, which happens
      only if the create reached the panel.
    - Not reproduced.
    - `PlaceOrderTest.php:143-179` asserts only that the job is dispatched, so `handle()` is untested.
- Fix:
    - When the lock is not acquired, `$this->release(60)` instead of returning.
    - Set `$timeout` explicitly.
    - Use `Queues::PROVISIONING`, so the job runs in the pool sized for provider calls.
    - Add a test of `handle()` with the lock held.

**M4. A documented security capability is a no-op: `DeclaresSensitiveKeys` has no implementer and no host reads it.**

- Evidence:
    - `app/Contracts/Integrations/DeclaresSensitiveKeys.php:3-17` is specified by ADR 0023 §5 ("names exact keys that
      are secret in one operation's payloads").
    - No class implements it and no `instanceof` check reads it.
    - `app/Modules/Domains/Services/RegistrarGateway.php:235-251` hard-codes `['code']` for `epp` and `transfer`.
    - `app/Modules/Services/Actions/ExecuteModuleCommand.php:229-230` passes no extra keys to `Redactor::redact()`.
- Impact:
    - Today nothing leaks, because the built-in drivers are covered by the host's hard-coded keys and the name rule.
    - But a package driver that follows ADR 0023 and implements the capability for a new operation (a panel SSO `url`,
      a registrar's `authInfo`) gets nothing back, and its secret is written to the module or registrar log row in
      clear text.
    - It is also the only unreferenced class in `app/`.
- Fix: in both hosts, `if ($driver instanceof DeclaresSensitiveKeys)`, merge `sensitiveKeys($operation)` into the keys
  passed. Test it with a fake driver. Or delete the contract and amend ADR 0023. The first option is recommended,
  because the extension model needs it.

**M5. Test-suite hygiene: probes named after PRs, wall-clock budgets, stray HTTP.**

- Evidence:
    - 144 test files are named after a PR or review round (`*Probe*`, `Pr<n>`, `Rr<n>`, `Review<n>`), up from 112 on
      10-29, for example `tests/Feature/ReviewProbes/Pr538Rr2ProbesTest.php` and
      `tests/Feature/Security/Review1031/StepUpPasskeyProbesTest.php`.
    - Wall-clock assertions remain: `tests/Unit/Support/Integrations/RedactionSetTest.php:204-240` and
      `RedactorShapesBudgetTest.php:36` (`toBeLessThan(2.0)` seconds), the flake class behind the only `develop`
      failure in the 10-29 window.
    - 19 test files use `Http::fake` without `preventStrayRequests()`, and `tests/TestCase.php` has no global guard.
    - `tests/Unit/ExampleTest.php:11` is `assertTrue(true)`.
- Impact:
    - `testing-best-practices` (naming) asks for files named by behaviour. A probe named `Pr538Rr2` tells the next
      engineer nothing about what breaks when it fails, and the count keeps growing.
    - Time budgets fail on a loaded machine.
    - A missing fake reaches the network.
    - #558 (open) removes real sleeps, but none of these.
- Fix:
    - Call `Http::preventStrayRequests()` in `TestCase::setUp()`.
    - Turn the time budgets into operation-count budgets, or move them to a `perf` group outside the CI shards.
    - Delete `ExampleTest`.
    - From now on, a probe that becomes a permanent test moves into the behaviour file of its module (add the rule to
      `review-rules.md` "Probes become tests").

**M6. No coverage or mutation signal on money paths.**

- Evidence:
    - Every CI PHP job sets `coverage: none` (`.github/workflows/ci.yml:184,352,414,480,594`). No coverage or mutation
      job exists.
    - CTO 10-29 §3.2: the money defects in #505 and #464 were found only by deleting a branch by hand.
    - Example from this audit: `ProvisionOrderItem::handle()` (M3) has no test.
    - 23 Billing actions, services and support classes are named by no test (for example `GatewayCharger`,
      `RefundReservations`, `FiscalVatSplit`, `FiscalSnapshotWriter`). Many are covered through HTTP, which is why a
      coverage signal is needed to tell which.
- Impact: whether a money branch is tested is only known when a reviewer runs a manual mutation probe.
- Fix:
    - A nightly or `workflow_dispatch` job (not on every PR) with pcov.
    - Run `pest --coverage` on `app/Modules/Billing/Actions`, `app/Modules/Payments*/Drivers` and
      `app/Modules/Orders/Actions` to report uncovered lines.
    - Then run Pest's built-in `--mutate` on the five refund and settlement classes.
    - Start with reporting only. Add a `--min` later.
    - Needs the owner's approval for the CI change (CI policy) and a pinned coverage driver in the image.

**M7. The pre-push gate (CTO 10-29 change 3) is not built.**

- Evidence: there is no hook under `scripts/`, `.githooks` or `.husky`, and "pre-push" appears only in
  `docs/reviews/2026-10-29-cto.md`.
- Impact: CTO 10-29 found that about 20 of 75 CI failures came from Pint, PHPStan, `vp check` and fragment checks,
  all catchable locally in a minute. The fix was agreed and not done.
- Fix: add `scripts/pre-push.sh` (Pint `--test` on changed files, PHPStan on changed paths, `vp check`, the fragment
  check, `tests/Arch`), installed by `scripts/new-worktree.sh` through `core.hooksPath`.

**M8. The translation mechanism (ADR 0028, accepted today) does not exist, and every new page adds strings.**

- Evidence: 0 of 159 pages under `resources/js/pages` call a translation function. ADR 0028 was accepted on 10-31 as
  option A, "English only for now; build the mechanism" (#562).
- Impact: each page merged before the mechanism lands is a page to migrate later. The cost grows with the parallel
  waves.
- Fix: schedule the mechanism PR ahead of the next wave of UI work. Until then, new pages put their copy in one
  object per page, so the later extraction is mechanical.

### Low

**L1. Dead Laravel starter-kit code.**

- `resources/js/components/app-header.tsx` links to `github.com/laravel/react-starter-kit` (`:50-58`) and has the only
  two icon buttons without an accessible name (`:80`, `:181`).
- It is used only by `resources/js/layouts/app/app-header-layout.tsx`, which nothing imports.
- Also unreferenced: `components/nav-footer.tsx`, `components/ui/icon.tsx`, `layouts/auth/auth-card-layout.tsx`,
  `layouts/auth/auth-split-layout.tsx`, and `app/Support/Billing/Prorata.php`, which only tests use.
- Fix: delete them. Check whether `Prorata` is the intended home of the first-period calculation (its docblock says
  Orders and Services use it, and neither does).

**L2. Queue names as string literals beside `App\Support\Queues`.**

- `app/Modules/Notifications/Jobs/DeliverWebhook.php:27`, `SendEmailMessage.php:47`,
  `app/Modules/Services/Jobs/RunModuleCommand.php:51`, `ImportReviewedAccounts.php:35`, `FetchServerAccounts.php:39`.
- `Queues` is the documented single source, and it is tested against `config/queue.php` and the install hint.
- Fix: use the constants.

**L3. Two ways to call a JSON endpoint from React.**

- 9 raw `fetch()` callers, 4 of them building CSRF headers by hand: `components/billing/card-form.tsx:39`,
  `components/services/use-credentials-reveal.ts:14`, `components/app/set-password-button.tsx:24`,
  `components/app/password-confirmation-gate.tsx:170`.
- Inertia v3 `useHttp` is used in 12 places.
- Fix: standardise on `useHttp` (`inertia-react-development`, "HTTP Requests"). Keep raw `fetch` only for streamed
  downloads (`step-up-download-link.tsx`).

**L4. Minor-to-major conversion re-implemented with a literal 100.**

- `resources/js/components/orders/decimal-amount.ts:14` and `components/billing/types.ts:267,281`, beside
  `lib/money.ts:41-44` (`MINOR_UNIT_SCALE`, `fromMinor`).
- ADR 0002 fixes the scale at 100, so the code is correct today. But it is three copies of one rule.
- Fix: use `fromMinor` or `formatMinor`.

**L5. Raw palette colours with no dark variant on starter pages.**

- `text-green-600` at `pages/auth/login.tsx:107`, `auth/forgot-password.tsx:19`, `auth/verify-email.tsx:16` and
  `settings/profile.tsx:121`.
- The rest of the app uses theme tokens (`resources/css/app.css:16`, `@theme inline`).
- Fix: use the success token.

**L6. Six outbound clients each set up timeouts their own way.**

- `PaymentsStripe/Support/StripeClient.php:146-181`, `PaymentsRazorpay/Support/RazorpayClient.php`,
  `PaymentsNepal/Support/ProviderHttp.php`, `PaymentsCybersource/Support/CybersourceRest.php`,
  `Domains/.../NamecheapClient.php`, `Services/.../Cpanel/WhmClient.php`.
- All six set timeouts and use `Endpoints::for`. They differ in how they read the settings (clamped with `max`/`min`,
  checked with `is_numeric`, or a constant).
- Fix: when the next driver lands, a kernel helper (`Endpoint::request(int $connect, int $timeout)`), so a package
  driver gets the same policy without copying it. Not worth a PR on its own.

**L7. No bundle-size budget.**

- The page-split build has 411 JS chunks, and the entry is 207 kB, 58 kB gzip, which is healthy.
- The two largest chunks are `chart` (353 kB, 104 kB gzip) and `dist` (327 kB, 103 kB gzip).
- Nothing in CI fails when either grows.
- Fix: a size check in the Build job (entry 80 kB gzip, any chunk 150 kB gzip), reported first and enforced later.

**L8. Oversized files.**

- `app/Support/Integrations/Redactor.php` (1,331 lines, as in CTO 10-29).
- `resources/js/pages/admin/billing/invoices/show.tsx` (1,381), `admin/services/show.tsx` (1,247),
  `admin/storefront/website.tsx` (1,244).
- `app/Modules/Notifications/Listeners/SendEventEmails.php` (869 lines, 46 handlers).
- Fix: split them when they are next touched. `SendEventEmails` should become a registry, so that a package event can
  map to a template (extension model, below).

## Quick wins

Each is under an hour and needs no design:

1. Call `Http::preventStrayRequests()` in `TestCase::setUp()` (M5).
2. Release or retry when the lock is held in `ProvisionOrderItem`, plus `Queues::PROVISIONING` and `$timeout` (M3).
3. Read `DeclaresSensitiveKeys` in `RegistrarGateway` and `ExecuteModuleCommand` (M4).
4. Use `Queues::*` constants in the five jobs (L2).
5. Delete the starter-kit files and `tests/Unit/ExampleTest.php` (L1, M5).
6. Use `fromMinor` in the two money helpers (L4) and the success token on four starter pages (L5).
7. Write the validation and authorization rule into `architecture.md` (M1, text only).

## Proposed fix PRs

One reviewable workstream each, in priority order. Kernel ones are made by the CTO session.

| #   | PR                                                                                          | Findings      | Owner and gates                                                         | Size   |
| --- | ------------------------------------------------------------------------------------------- | ------------- | ----------------------------------------------------------------------- | ------ |
| 1   | `feat(core): request and job correlation` (ADR 0014 observability accepted)                 | H2            | CTO session (kernel middleware); architect review                       | M      |
| 2   | `docs(adr): 0030 Core public surface` + `feat(kernel): snapshot covers @api Core classes`   | H1            | Architect drafts, CTO decides; kernel fragment                          | S + M  |
| 3   | `fix(orders,integrations): provisioning retry and sensitive-key capability`                 | M3, M4, L2    | Module engineer; combined review (integrations, redaction)              | S      |
| 4   | `test(security): admin permission sweep` + Form Request rule and money-controller move      | M1, M2        | Module engineer; security checklist in the combined review              | M      |
| 5   | `refactor(kernel): ADR 0011 after-commit, arch baseline` then one PR per module             | H3            | CTO session; waves of small PRs                                         | S, then S each |
| 6   | `test(harness): stray-HTTP guard, operation budgets, probe naming rule` (after #558)        | M5            | Module engineer; CTO verifies from the diff                             | S      |
| 7   | `ci: nightly coverage and mutation report on money paths`                                   | M6            | DevOps engineer; owner approval (CI policy, coverage driver pin)        | S      |
| 8   | `chore(dx): pre-push gate installed by new-worktree.sh`                                     | M7            | DevOps engineer                                                         | S      |
| 9   | `chore(frontend): starter-kit removal, useHttp, money helpers, tokens, bundle budget`       | L1, L3–L5, L7 | Module engineer; product reviewer for the auth pages                    | S      |
| —   | ADR 0028 mechanism                                                                          | M8            | Already accepted; schedule ahead of the next UI wave                    | M      |

PRs 1, 3, 6, 8 and 9 do not depend on each other and can run in one wave. PR 2's ADR goes first, and its snapshot
change waits for the CTO decision. PR 5 is the CTO 10-29 item, now with a baseline.

## ADRs to write

- **0030 Core's public surface** (H1): which Core classes are `@api`, whether `Settings`, `ActivityLogger` and the
  client get kernel contracts, and the snapshot scope.
- **ADR 0014 amendment**: accept the observability sections as scoped in H2. Move order provisioning to
  `provisioning` (M3), which reverses the line in `:116`.
- **ADR 0023 amendment**, only if `DeclaresSensitiveKeys` is deleted rather than honoured (M4).

## Area notes (what was checked and found sound)

**1. Laravel conventions.**

- Eloquent:
    - `Model::shouldBeStrict(! app()->isProduction())` (`app/Providers/AppServiceProvider.php:162`), so lazy loading,
      silently discarded attributes and missing attributes fail in tests;
    - `#[Fillable]` on 105 of 106 models, no `$guarded = []`;
    - `Model::unguarded()` only in two seeders;
    - `$request->all()` only in the Fortify `CreateNewUser` hand-off (`app/Http/Controllers/Auth/RegisteredUserController.php:30`),
      which validates.
- `env()`: none outside `config/`.
- Jobs: of the 17 queued jobs, 11 have `failed()` handlers. The provider jobs follow ADR 0014 (unique or locked,
  tries, backoff), except M3.
- Scheduler: every module task has `withoutOverlapping()->onOneServer()`, for example
  `app/Modules/Billing/BillingServiceProvider.php:229,231`, `Core/CoreServiceProvider.php:143-147` and
  `Domains/DomainsServiceProvider.php:117`. The heartbeat is idempotent.
- Money writes: 27 of 29 Billing actions open a transaction and take `lockForUpdate` or a cache lock. The two that do
  not (`UpdateInvoiceDetails`, `CreateAddFundsInvoice`) make a single save or delegate to `IssueInvoice`.
- Caching:
    - `Cache::flexible` on the dashboards;
    - memoised settings with `forget` on write (`app/Modules/Core/Support/Settings.php:55-92`);
    - currency cache cleared on `saved` and `deleted` (`Core/Models/Currency.php:65-82`).
- PHPStan: level 7, no baseline, 0 `@phpstan-ignore`.

**2. Architecture.**

- A scan of `use App\Modules\X` across `app/Modules` found 0 sibling-module imports. Every cross-module reference is
  to Core, which rule 1 allows.
- The kernel imports Core in two places only: `ReportExporter` (`Settings`, `ActivityLogger`, which rule 1 names) and
  `CertificateBundle` (`DateFormats`).
- One unreferenced class (M4).
- Oversized files in L8.

**3. Security (quick pass).**

- XSS sinks: the three `dangerouslySetInnerHTML` calls are safe. Two render server-rendered SVG or chart CSS, and the
  third is support markdown rendered with `html_input => strip` and `allow_unsafe_links => false`
  (`app/Modules/Support/Services/MarkdownRenderer.php:18-20`).
- Raw SQL: every interpolation is a constant or a column name (`Catalog/CatalogServiceProvider.php:74-81`,
  `Core/Support/Like.php:38`).
- Uploads: `File::types()`, `mimetypes:` and size caps on KYC, brand and certificate uploads.
- No `unserialize` without `allowed_classes`, no redirect taken from user input.

**4. Testing.**

- 516 feature tests, 60 unit tests and 174 files with `assertInertia`.
- Mocks are rare (14 mock calls, 10 `shouldReceive` or `partialMock`), and the drivers are tested against simulators (ADR 0016).
- Skips are environmental (Fortify features, PostgreSQL-only race tests, `jq`).
- 13 frontend test files.

**5. Frontend.**

- `tsconfig.json:86-87` sets `strict` and `noImplicitAny`. There is no `any` outside the generated Wayfinder code, and
  only 2 `eslint-disable` comments.
- Wayfinder: 192 files import `@/actions` or `@/routes`. No internal URL is hard-coded outside `pages/dev/`. 12 URLs
  are built on the server with `route()` and passed as props.
- Tailwind v4: `@import 'tailwindcss'`, `@theme inline`, a `dark` custom variant, no deprecated v3 utilities.
  `gap-*` (1,161 uses) is preferred over `space-*` (144).
- Accessibility: 0 `<img>` without `alt`, 0 clickable `div`/`span`, 54 of 56 icon buttons named (the 2 are in the dead
  header, L1).
- Inertia: `<Form>` (45 files) and `useForm` (46), deferred props with `Inertia::defer` (4), `<Link>` for internal
  navigation, with one exception (`pages/admin/orders/show.tsx:435`, `<a href={invoice.url}>`).

**6. Performance and operations.**

- Every one of the 17 outbound callers sets `timeout` and `connectTimeout`, directly or through a shared client.
  Interactive calls use shorter limits (`StripeClient.php:153-156`).
- SMTP goes through `Platform/Support/DeadlineSmtpTransport.php`.
- Failed jobs are pruned (`Platform/Actions/Housekeeping/PruneSystemTables.php:44-49`, 30 days).
- Production pools and their timeouts are documented in `docs/devops/production.md:21-24`, and the deploy scripts
  match them.
- The sizing numbers (`docs/devops/sizing.md:38,100`) are covered by the database review and #543.

**7. Developer experience.**

- `scripts/test.sh --changed` selects tests by module and widens on shared code.
- `scripts/heavy.sh` limits concurrency.
- The `composer ci` script mirrors CI.
- CI wall time on `develop` is 5.7 min (checks 50 s, the slowest PostgreSQL shard 4 min 43 s).
- `composer setup` runs `npm install` rather than `npm ci` (`composer.json:57`). It is a local bootstrap, but under
  ADR 0007 it should install from the lockfile. It is folded into PR 8.
