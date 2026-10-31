# CI design (GitHub Actions)

One workflow, `.github/workflows/ci.yml` at the repository root, all steps in `billing/`. While the repository is
public, runner minutes are free and wall time is what counts, so the work runs in parallel jobs behind one required
check. While it is private, Linux minutes are billed **per job, rounded up to the next whole minute**; see
"Billable minutes" for what the parallel layout costs then.

## When it runs

| Event                                                        | Runs?                                                                                                                                                                                                                                                                   |
| ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PR to `develop`/`main` (opened, push, reopen)                | yes; superseded runs of the same PR are cancelled                                                                                                                                                                                                                       |
| Draft PR                                                     | only when labelled `ci`; otherwise runs when marked ready for review                                                                                                                                                                                                    |
| Push to a feature branch                                     | no (its PR run covers it — no duplicate push + PR runs)                                                                                                                                                                                                                 |
| Push to `develop`/`main` (merges)                            | yes, never cancelled                                                                                                                                                                                                                                                    |
| Only Markdown / `*.png` / `billing/docs/**` / `docs-site/**` | in-job fast path: only `vp check` (Markdown formatting); PRs and pushes to `develop`/`main`. The docs site is never built in CI. A change to `docs-site/` dependency files (`package.json`, lockfile, `.npmrc`, `.nvmrc`) also runs the cooldown check (and the audits) |
| Manual                                                       | `workflow_dispatch`                                                                                                                                                                                                                                                     |

The **E2E simulators (hosting lifecycle)** job (ADR 0016 §4) is opt-in.

- **When it runs:** on `workflow_dispatch`, on pushes to `main`, and on PRs labelled `e2e` (the `labeled` event for `e2e`, and later pushes while the label stays).
- **When it never runs:** on ordinary PR events, `ci`-labelled ones included, or on docs-only changes without the label.
- **What it does:** `php artisan simulate:e2e --connection=pgsql` on its own PostgreSQL service, with `timeout-minutes: 10`.
- **Cost:** setup (about 45 s) plus the scenario (25–40 s), about **2 billable minutes** a run.

## Jobs

```
checks ──┬── Pest SQLite (1/2), (2/2)
         ├── Pest PostgreSQL (1/3), (2/3), (3/3)   ──►  CI (policy, lint, types, static, tests, build)
         └── Build
```

**`checks`** (policy, lint, types, static) runs the cheap steps and the dependency policy, cheapest first, so a lint or
policy failure fails the run in about a minute. The policy runs **before** anything is installed (no package code,
e.g. Composer's `package:discover` hook, executes before the lockfiles pass), and the test and build jobs start only
after `checks` passed, so they never install a lockfile the policy has not approved:

| #   | Step                                                                                                                                                                                                                                                                                                                                                                         |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | checkout (depth 2 for PRs, full history for pushes) · docs-only detection · dependency diff base · the base branch's policy scripts · changelog fragments (PRs only, also on the fast path)                                                                                                                                                                                  |
| 2   | setup-php · setup-node (toolchain only, no project dependencies)                                                                                                                                                                                                                                                                                                             |
| 3   | postgres digest drift (ci.yml = compose.yaml = compose.prod.yaml)                                                                                                                                                                                                                                                                                                            |
| 4   | dependency cooldown + binding (`check-dependency-age.mjs`, PR base or `github.event.before`), waivers from `security-exceptions.sh`, audit-setting allowlist; then the policy copy is removed                                                                                                                                                                                |
| 5   | `composer validate --no-scripts --no-plugins` on every code or dependency change · `composer audit --locked --abandoned=report --no-scripts --no-plugins` · `npm audit` on a copy of each lockfile (`--registry=https://registry.npmjs.org/ --include=dev --strict-ssl=true --noproxy=registry.npmjs.org`) only when a manifest, lockfile, `.npmrc` or workflow file changed |
| 6   | install: Composer + npm from lockfiles (cached), `.env`, Wayfinder                                                                                                                                                                                                                                                                                                           |
| 7   | `vp check` (format + lint) and image sizes — the only steps on docs-only PRs and pushes                                                                                                                                                                                                                                                                                      |
| 8   | restore tool caches · Pint · tsc · Vitest (`npm test`) · PHPStan                                                                                                                                                                                                                                                                                                             |

On a PR, steps 1 and 4 run the **base branch's** `changelog-check.sh`, `changelog-assemble.sh`,
`security-exceptions.sh`, `check-dependency-age.mjs` and `dependency-policy.mjs` (extracted from `HEAD^1` into
`billing/.ci-policy/`, removed before the install), against the PR's tree, as the local merge gate does: a PR cannot
weaken the checks that judge it, and a change to them takes effect once merged. A script the base does not have yet
comes from the PR, with a warning; on a PR into `main` (no `billing/` until the first release) that is all of them.
The checker runs before any tool that reads the PR's config (`composer validate`, the audits), and its copy is gone
before they start. It never uses a dependency-age cache from the PR tree, and it fails a PR that changes a
`composer.json` or `.npmrc` setting outside its allowlist without the `security-exception` label. Composer then runs
with `--no-scripts --no-plugins`, and npm audits a copy of each lockfile away from any `.npmrc`
(dependency-policy.md). A push run uses the label and waivers of the PR it merged into that branch, so an approved
change does not turn the branch red. `ci.yml` itself still comes from the PR (inherent to `pull_request`), so a change
to it is a visible workflow diff; `.github/CODEOWNERS` requests the owner's review of it.

**Test jobs.** `pest --shard=<n>/<total>` (in the installed Pest 5.3) gives each job a share of the test files, round-robin in file
order, and each job runs its share with `--parallel`: SQLite in 2 shards (one worker per vCPU: 4 on the public-repository runner, every suite), PostgreSQL in
3 (4 workers each; the counts are `SQLITE_SHARDS` and `PGSQL_SHARDS` at the top of the workflow, the checks job turns
them into the matrix lists and each job runs `--shard=<n>/<strategy.job-total>`, so a list cannot drift from its
total; Feature, Extensions and the simulator framework; `composer test:pgsql -- --shard=…`), each with
its own PostgreSQL service. Each job prints its slowest files and test cases (`scripts/slowest-tests.mjs`). A
time-balanced split is available (`--update-shards` writes `tests/.pest/shards.json`, which `--shard` then uses), not
adopted: the file must be refreshed as tests are added. **`Build`** runs `npm run build`, then the check that the app
runs without the dev-only simulators package (last: it removes the dev dependencies).

**`CI (policy, lint, types, static, tests, build)`** is the required check. It `needs` every other job and runs with
`always()`, so a failed, cancelled or timed-out job turns it red instead of leaving a skipped (passing) check: it
passes when `checks` passed and every test and build job passed, or, on a docs-only change, was skipped. Each job
has `timeout-minutes` (10, the final job 5). Shared setup lives in `.github/actions/setup-app` (composite action).

Measured on PR #448 (see "Billable minutes"): about half the wall time of the one job, which took 529–567 s
(created to finished, runs 40009444592 and 40008036890).

Caches (keys derived from lockfiles): Composer downloads (`composer.lock`), npm (`package-lock.json`, via
`setup-node`), and one tool cache restored from the latest run and saved again by every run: PHPStan's result cache
(`storage/framework/cache/phpstan`), Pint's (`--cache-file=storage/framework/cache/pint.cache`) and tsc's
(`--incremental --tsBuildInfoFile storage/framework/cache/tsc.tsbuildinfo`). Each tool checks file contents, its
own version and its configuration against the cache, so an older entry is safe; warm, Pint takes 1–4 s instead
of 33–42 s and tsc 4–7 s instead of 17–19 s. Vite/vp builds have no persistent cache to reuse.

`pest --parallel` uses one process per core on SQLite (4 on the standard runner of a public repository, 2 on a private
one's; 4 in the Codespace). Every process gets its own SQLite in-memory database or its own
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

| Design                                    | Jobs | Job durations                                                                                              | Billable      |
| ----------------------------------------- | ---- | ---------------------------------------------------------------------------------------------------------- | ------------- |
| Before (PR #1 layout; PRs #3–#5, develop) | 5    | measured: lint 43–52 s, static 23–34 s, sqlite 20–29 s, pgsql 39–59 s, build 34–43 s                       | **5 min**     |
| Considered: `quality` + `tests`           | 2    | estimated ≈ 75 s + ≈ 61 s                                                                                  | 3–4 min       |
| After: one job                            | 1    | measured 92 s (PR #9, run 39065947655, before Vitest)                                                      | **2 min**     |
| One job on 2026-10-18 (wave 1 merged)     | 1    | measured 202–319 s (PRs #8, #22; full suite on SQLite + PostgreSQL, Vitest)                                | **4–6 min**   |
| One job on 2026-10-19, before PR #91      | 1    | measured 829–874 s (PRs #69, #72, #73; PostgreSQL step 544–550 s)                                          | **14–15 min** |
| One job after PR #91                      | 1    | measured 579–622 s (runs 39279853998, 39383275452; PostgreSQL step 212–309 s, SQLite 132–194 s)            | **10 min**    |
| Parallel jobs (PR #448, run 40010815961)  | 8    | measured: checks 56 s, SQLite 153 + 138 s, PostgreSQL 176 + 190 + 118 s, build 63 s, final 3 s; 258 s wall | **19 min**    |
| After, docs/png-only PR                   | 1    | ≈ 50 s                                                                                                     | **1 min**     |
| Draft PR / feature-branch push            | 0    | —                                                                                                          | **0**         |
| E2E simulators, when opted in (`e2e`)     | +1   | ≈ 45 s setup + 25–40 s scenario (`simulate:e2e`, local runs 22–34 s)                                       | **+2 min**    |

Each merge additionally runs once on `develop`. Measure real runs with `gh run view <id> --json jobs`
(job `startedAt`/`completedAt`). The parallel layout roughly doubles the billable minutes of a run (19 against 10): free
while the repository is public. Before switching it to private, either accept that or fold the test jobs back into
one (the shard steps run unchanged in one job without `--shard`); the local merge gate already runs everything in
one sequence.

## Required checks

Mark `CI (policy, lint, types, static, tests, build)` as the required status check on `develop` and `main`.
`E2E simulators (hosting lifecycle)` is never required: it is opt-in. ADR 0016 CTO decision 5 counts its 10 consecutive green runs before anyone reconsiders that.
It is the last job of the workflow, not a step, and passes only when every other job did (see "Jobs"); the jobs it
waits for are not required checks themselves, so renaming or adding a shard needs no branch-protection change.
There is no workflow-level `paths-ignore`, so every PR — including docs- or image-only ones — reports the
check (green via the fast path, with the test and build jobs skipped) and branch protection never waits for a check
that was not created.

## Changing the workflow

- Pin actions by SHA with the version comment on the same line; the dependency check enforces it
  (`dependency-policy.md`).
- Validate with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 from the repository root.
- Keep `timeout-minutes` on every job so a hung run cannot burn minutes.

## Scheduled dependency audit

`dependency-audit.yml` runs on Mondays at 03:17 UTC and on demand: `composer audit --locked`, both `npm audit
--package-lock-only` and `check-dependency-age.mjs --all --min-days=0` on develop, with nothing installed and
`contents: read` only. It is the signal for an advisory against an unchanged lockfile, which CI no longer raises
(audits run only on dependency changes, owner decision 2026-10-21). GitHub runs `schedule` and `workflow_dispatch`
only for a file on the default branch, so it is dormant until `main` carries it (owner decision).

## Manual workflows

Two more workflows build and run the production stack on a runner. Neither is part of CI. Each runs only when
started by hand, through Actions → Run workflow or by adding a label to a pull request. In `load-test.yml`, adding
any other label never cancels a running test.

| Workflow                               | Label          | Runs                                                                                    | Minutes                     |
| -------------------------------------- | -------------- | --------------------------------------------------------------------------------------- | --------------------------- |
| `deploy-smoke.yml`                     | `deploy-smoke` | install on fresh PostgreSQL and RustFS, check `/up`, sign-in, a queued job, `db:seed`   | see `production.md`         |
| `load-test.yml` (`loadtest/README.md`) | `load-test`    | small profile, large volume; any profile or volume from Actions; results as an artifact | see `docs/devops/sizing.md` |

Both use the standard Linux runner: 4 CPUs and 16 GB for a public repository (2 CPUs and 8 GB for a private one).
The repository is public, so the runner fits the small and medium profiles; `load-test.yml` skips the large profile
(8 vCPU) there unless asked, because the runner is smaller than it is. Results and the guidance drawn from them are in
`docs/devops/sizing.md`.

## Changelog fragments

On every PR, docs-only ones included, `scripts/changelog-check.sh` (ADR 0027) fails a direct edit of
`billing/changelog.md` or `billing/docs/architecture/kernel-changelog.md` and points to the fragment folder
(`billing/changelog.d/`, `billing/docs/architecture/kernel-changelog.d/`), and fails a malformed fragment
(`scripts/changelog-assemble.sh --check`). It only warns when app code (`billing/{app,bootstrap,config,database,lang,resources,routes}/`)
changes with no changelog fragment, or the kernel surface changes with no kernel-changelog fragment.

- The assemble commit is allowed to edit the changelogs: the PR head (`HEAD^2` of the merge commit) carries the trailer
  `Changelog-Assemble: yes`, and the diff only edits the two changelogs and deletes fragments. Keep that commit as the
  PR's last one; a later commit or a merge of `develop` drops the marker.
- PRs into `main` are not checked (a release PR carries `develop`'s assembled changelog), nor back-merges from this
  repository's `main` (a fork's branch named `main` is checked: CI passes the head repository). PRs up to #396, opened before
  fragments, keep their direct edits with a warning (`LEGACY_MAX_PR` in the script; remove it once they have merged).
- The local merge gate runs the same check with `develop`'s copy of both scripts.

## Local merge gate

When Actions is unavailable (private repository, minutes used up), `scripts/merge-gate.sh <pr> <head-sha>` stands in
for the CI jobs, run in one sequence, on macOS or Linux. It fetches exactly `<head-sha>` (refusing if the PR head differs), merges it
into `origin/develop` in a throwaway worktree and applies CI's change detection (docs-only fast path, audits only on
dependency or workflow changes). As in CI, the policy checks (postgres pin, lockfiles, audits, cooldown) run before
any dependency is installed; the cooldown checker is develop's copy, run against the PR's lockfiles. Then it installs
and runs the remaining steps in CI's order through `scripts/heavy.sh` (at most 2 PHP processes), and prints a
PASS/FAIL/SKIP table with test counts, times and the gated head. A copy goes to `$GATE_ROOT/gate-<pr>.log`. The
worktree and the gate's own PostgreSQL databases are removed on exit; it never runs `pg.sh --prune`. A run fails if
the PR head moves while it runs, and only one run per PR is allowed at a time.

- After the policy checks it runs the PR's code as you (environment, gh token, SSH agent): read the diff first and
  pass its head sha. Fork PRs need `GATE_ALLOW_FORK=1`.
- On macOS the Linux-only tests (the `Simulator framework` suite needs `setsid` and `/proc`; tests that run a `deploy/` or `loadtest/` script
  need GNU `realpath -m`) move to a "Linux only" suite in a gate-only `phpunit.gate.xml`, which runs once through
  `scripts/linux.sh` in the production base image, on SQLite. The E2E simulators job and the PostgreSQL tuning step are not reproduced (the
  shared `mohar-pg` server is not reconfigured).
- `vendor/` is copied from the main checkout when `composer.lock` matches and `vendor/composer/installed.json`
  lists the same packages and versions (`node_modules/`: its `.package-lock.json` must agree with the lock). Otherwise the gate runs `composer install`. A symlinked `vendor/` breaks
  Pest and the arch tests. `node_modules/` is linked when `package-lock.json` matches, otherwise `npm ci`.
- Settings: `GATE_CONTINUE=1` runs every step after a failure (still exits non-zero); `GATE_PROCESSES`, `GATE_ROOT`,
  `MAIN_CHECKOUT`, `GATE_REPO` and `GATE_SCRIPTS` (where `heavy.sh`, `pg.sh` and `linux.sh` live) override the defaults.
- To merge: post the summary with `gh pr comment <pr> --body-file $GATE_ROOT/gate-<pr>.log`, then `gh pr ready <pr>`
  and the `gh pr merge ... --match-head-commit <head>` line the summary prints.
- Known flake: the simulator `ProcessesTest` can fail with `Address already in use` when other runs share the
  machine (#182).
