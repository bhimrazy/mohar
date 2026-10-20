# CI design (GitHub Actions)

One workflow, `.github/workflows/ci.yml` at the repository root, all steps in `billing/`. The repository is
private, so Linux minutes are billed **per job, rounded up to the next whole minute** — the design minimises
jobs, setup work and pointless runs.

## When it runs

| Event                                                        | Runs?                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PR to `develop`/`main` (opened, push, reopen)                | yes; superseded runs of the same PR are cancelled                                                                                                                                                                                                                     |
| Draft PR                                                     | only when labelled `ci`; otherwise runs when marked ready for review                                                                                                                                                                                                  |
| Push to a feature branch                                     | no (its PR run covers it — no duplicate push + PR runs)                                                                                                                                                                                                               |
| Push to `develop`/`main` (merges)                            | yes, never cancelled                                                                                                                                                                                                                                                  |
| Only Markdown / `*.png` / `billing/docs/**` / `docs-site/**` | in-job fast path: only `vp check` (Markdown formatting); PRs and pushes to `develop`/`main`. The docs site is never built in CI. A change to `docs-site/` dependency files (`package.json`, lockfile, `.npmrc`, `.nvmrc`) also runs the audits and the cooldown check |
| Manual                                                       | `workflow_dispatch`                                                                                                                                                                                                                                                   |

The **E2E simulators (hosting lifecycle)** job (ADR 0016 §4) is opt-in.

- **When it runs:** on `workflow_dispatch`, on pushes to `main`, and on PRs labelled `e2e` (the `labeled` event for `e2e`, and later pushes while the label stays).
- **When it never runs:** on ordinary PR events, `ci`-labelled ones included, or on docs-only changes without the label.
- **What it does:** `php artisan simulate:e2e --connection=pgsql` on its own PostgreSQL service, with `timeout-minutes: 10`.
- **Cost:** setup (about 45 s) plus the scenario (25–40 s), about **2 billable minutes** a run.

## One job

Every job pays its own runner start, checkout, PHP/Node setup, dependency install (~25 s) and, for the
PostgreSQL suite, the service container start (~12–20 s), and each job is rounded up to a whole minute. One
job therefore costs less than several parallel ones. Steps run cheapest first so a failure stops early, and
the dependency policy runs **before** anything is installed (no package code — e.g. Composer's
`package:discover` hook — executes before the lockfiles pass):

| #   | Step                                                                                                 | s (run 39279853998) |
| --- | ---------------------------------------------------------------------------------------------------- | ------------------- |
| 0   | runner + PostgreSQL service container (`postgres:18.6-alpine@sha256…`)                               | 20                  |
| 1   | checkout (depth 2 for PRs, full history for pushes) · docs-only detection · dependency diff base     | 3                   |
| 2   | setup-php · setup-node (toolchain only, no project dependencies)                                     | 6                   |
| 3   | postgres digest drift (ci.yml = compose.yaml)                                                        | 0                   |
| 4   | `composer validate` · `composer audit --locked` · `npm audit --audit-level=high --package-lock-only` | 2                   |
| 5   | dependency cooldown + binding (`check-dependency-age.mjs`, PR base or `github.event.before`)         | 1                   |
| 6   | install: Composer + npm from lockfiles (cached), `.env`, Wayfinder                                   | 17                  |
| 7   | `vp check` (format + lint) — the only step on docs-only PRs and pushes                               | 10                  |
| 8   | restore tool caches · Pint · tsc · Vitest (`npm test`)                                               | 1 + 1 + 3 + 4       |
| 9   | PHPStan (result cache restored)                                                                      | 2                   |
| 10  | Pest on SQLite (`--parallel`, 2 workers, every suite)                                                | 132                 |
| 11  | tune PostgreSQL · `composer test:pgsql` (Pest on PostgreSQL, 4 workers, Feature and Extensions)      | 212                 |
| 12  | `npm run build` · app without dev dependencies · cache save · container stop                         | 21 + 2 + 2          |

`timeout-minutes: 15` caps a hung run. The job took 579–622 s on the last two runs (39279853998, 39383275452);
the last step warns (`::warning::`) once the job passes 12 minutes, the point to act before runs are cancelled. Shared setup lives in `.github/actions/setup-app` (composite action).

Caches (keys derived from lockfiles): Composer downloads (`composer.lock`), npm (`package-lock.json`, via
`setup-node`), and one tool cache restored from the latest run and saved again by every run: PHPStan's result cache
(`storage/framework/cache/phpstan`), Pint's (`--cache-file=storage/framework/cache/pint.cache`) and tsc's
(`--incremental --tsBuildInfoFile storage/framework/cache/tsc.tsbuildinfo`). Each tool checks file contents, its
own version and its configuration against the cache, so an older entry is safe; warm, Pint takes 1–4 s instead
of 33–42 s and tsc 4–7 s instead of 17–19 s. Vite/vp builds have no persistent cache to reuse.

`pest --parallel` uses one process per core on SQLite (2 on the standard private-repository runner, which has 2
vCPUs; 4 in the Codespace). Every process gets its own SQLite in-memory database or its own
`billing_test_test_<n>` PostgreSQL database, migrates it once and runs each test in a transaction, so it is
reliable and nothing migrates or seeds the base database first.

The Unit and Arch suites never touch the database, so they run only in the SQLite step: `composer test:pgsql`
passes `--exclude-testsuite=Unit,Arch` (explicit paths still run whatever they name). Most tests there extend
`PHPUnit\Framework\TestCase`, which boots no application and cannot reach the database. For the few bound to
`Tests\TestCase` (one file today), the test case fails a test in `tests/Unit` or `tests/Arch` that uses a database
trait or runs a query (`tests/Unit/TestHarness/DatabaseFreeSuiteGuardTest.php`); such a test belongs in
`tests/Feature`.

A seed that takes tens of seconds is seeded once per worker, not in every test:
`$this->useSeededDatabase('<name>', fn () => $this->seed(...))` in `beforeEach` creates, migrates and seeds a
separate database the first time a worker asks for it (`<database>_<name>` on PostgreSQL, a temporary file on
SQLite), then runs each test in a transaction on it that is rolled back in `tearDown()`. BillingSeederTest uses it
for the 730-day demo billing history. A failing seed restores the test's own default connection, and the seeded
connection has a test-aware transaction manager, so after-commit callbacks behave as under RefreshDatabase
(`tests/Feature/TestHarness`). PostgreSQL sequences are not rolled back: a test on a seeded database must not
assert ids or sequence values.

Each worker is one PHP process that runs about half the suite, so memory a test file leaves behind (static
caches, singletons) adds up against the 1 GB `memory_limit` in `phpunit.xml`. The largest such cache is the arch
plugin's: it keeps the parsed source of every namespace a rule touches and never frees it (about 0.7 MB per file
in `app/`). Every file in `tests/Arch` therefore ends with `afterAll(fn () => releaseArchCache());` (checked by
`tests/Unit/ArchCacheReleaseTest.php`), and the rules are split across files so no single file parses everything.
When a worker crashes with "Allowed memory size exhausted", the line in the message is only where the limit was
reached: log `memory_get_usage()` per test from `TestCase::tearDown()` to find the file that grows.

## PostgreSQL step

On 2026-10-19 the PostgreSQL step took 544–550 s against 154 s for SQLite, and the job 830–874 s, near the
15-minute timeout. Measured causes and fixes, in the order they were applied on PR #91 (each row's step time is
the measured PostgreSQL step after that change):

| Cause                                                                                                                                                                                                                                                                                                    | Fix                                                                                                                                      | Step  |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| (before)                                                                                                                                                                                                                                                                                                 |                                                                                                                                          | 550 s |
| `test:pgsql` ran `migrate:fresh --seed` on the base database first (110 s, the demo history seed). No test uses that database: each worker migrates its own copy, and the demo seed runs in the suite.                                                                                                   | removed                                                                                                                                  |       |
| `localhost:5432` goes through docker-proxy; 2 workers left the CPUs waiting on the database; durability work for a database that is thrown away.                                                                                                                                                         | connect to the container's IP on the job network; 4 workers (`PEST_PROCESSES`); `fsync`, `synchronous_commit`, `full_page_writes` off    | 341 s |
| Laravel never releases a nested transaction's savepoint, so inside the test transaction every `DB::transaction()` stays on PostgreSQL's subtransaction stack and each row check walks it. Seeding tests slowed down quadratically (one `billing_sequences` update went from 0.2 to 42 ms during a seed). | `Tests\TestCase` releases the savepoint when a nested transaction commits, on both databases. BillingSeederTest: 275 → 181 s worker time | 294 s |
| A SCRAM handshake for each test's new connection.                                                                                                                                                                                                                                                        | `POSTGRES_HOST_AUTH_METHOD=trust` on the service                                                                                         | small |
| The Unit and Arch suites ran again on PostgreSQL without touching it; BillingSeederTest seeded the demo history in each of its tests.                                                                                                                                                                    | Unit and Arch only on SQLite; `useSeededDatabase()` seeds once per worker                                                                | 212 s |

Sending each query in one round trip (`Pdo\Pgsql::ATTR_DISABLE_PREPARES`) saved a further 37 s (341 → 304 s in runs
39262008137 and 39268842996) but was dropped: production uses native prepared statements, and the PostgreSQL gate
(ADR 0006) must exercise the same path. The suite also grew from 2,479 to 2,691 tests during these runs. Memory per worker stays under the 1 GB
`memory_limit` in `phpunit.xml`.

Both suites print their ten slowest files from a JUnit report (`scripts/slowest-tests.mjs`), and the PostgreSQL step
prints how busy the CPUs were. With both CPUs saturated, more workers do not help: the remaining time is CPU spent
running the tests. JIT (`opcache.jit=tracing`) was tried and made no measurable difference (run 39273012764).

The largest remaining item is InstallCommandTest, which runs the whole `DatabaseSeeder` (with the demo history) in
one test to prove the demo seed installs the same reference data as `app:install`: about 18 % of PostgreSQL test
time. It cannot share a pre-seeded database because it changes the registry and deletes reference data first.

## Billable minutes per PR run

| Design                                    | Jobs | Job durations                                                                                   | Billable      |
| ----------------------------------------- | ---- | ----------------------------------------------------------------------------------------------- | ------------- |
| Before (PR #1 layout; PRs #3–#5, develop) | 5    | measured: lint 43–52 s, static 23–34 s, sqlite 20–29 s, pgsql 39–59 s, build 34–43 s            | **5 min**     |
| Considered: `quality` + `tests`           | 2    | estimated ≈ 75 s + ≈ 61 s                                                                       | 3–4 min       |
| After: one job                            | 1    | measured 92 s (PR #9, run 39065947655, before Vitest)                                           | **2 min**     |
| One job on 2026-10-18 (wave 1 merged)     | 1    | measured 202–319 s (PRs #8, #22; full suite on SQLite + PostgreSQL, Vitest)                     | **4–6 min**   |
| One job on 2026-10-19, before PR #91      | 1    | measured 829–874 s (PRs #69, #72, #73; PostgreSQL step 544–550 s)                               | **14–15 min** |
| One job after PR #91                      | 1    | measured 579–622 s (runs 39279853998, 39383275452; PostgreSQL step 212–309 s, SQLite 132–194 s) | **10 min**    |
| After, docs/png-only PR                   | 1    | ≈ 50 s                                                                                          | **1 min**     |
| Draft PR / feature-branch push            | 0    | —                                                                                               | **0**         |
| E2E simulators, when opted in (`e2e`)     | +1   | ≈ 45 s setup + 25–40 s scenario (`simulate:e2e`, local runs 22–34 s)                            | **+2 min**    |

Each merge additionally runs once on `develop`. Measure real runs with `gh run view <id> --json jobs`
(job `startedAt`/`completedAt`); if the job regularly exceeds 120 s, the PHPStan cache and step order are the
first levers. Trade-off: feedback arrives after ~2 min instead of ~1 min, and there is one status check.

## Required checks

Mark `CI (policy, lint, types, static, tests, build)` as the required status check on `develop` and `main`.
`E2E simulators (hosting lifecycle)` is never required: it is opt-in. ADR 0016 CTO decision 5 counts its 10 consecutive green runs before anyone reconsiders that.
There is no workflow-level `paths-ignore`, so every PR — including docs- or image-only ones — reports the
check (green via the in-job fast path) and branch protection never waits for a check that was not created.

## Changing the workflow

- Pin actions by SHA with the version comment on the same line; the dependency check enforces it
  (`dependency-policy.md`).
- Validate with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 from the repository root.
- Keep `timeout-minutes` on every job so a hung run cannot burn minutes.

## Manual workflows

Two more workflows build and run the production stack on a runner. Neither is part of CI. Each runs only when
started by hand, through Actions → Run workflow or by adding a label to a pull request. In `load-test.yml`, adding
any other label never cancels a running test.

| Workflow                              | Label          | Runs                                                                                     | Minutes                     |
| ------------------------------------- | -------------- | ---------------------------------------------------------------------------------------- | --------------------------- |
| `deploy-smoke.yml`                    | `deploy-smoke` | install on fresh PostgreSQL and RustFS, check `/up`, sign-in and a queued job            | see `production.md`         |
| `load-test.yml` (`loadtest/README.md`) | `load-test`    | small profile, large volume; any profile or volume from Actions; results as an artifact | see `docs/devops/sizing.md` |

Both use a standard Linux runner, which has 2 CPUs and 8 GB in a private repository. `load-test.yml` skips the
medium and large profiles there unless asked, because the runner is smaller than they are.
