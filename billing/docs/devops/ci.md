# CI design (GitHub Actions)

One workflow, `.github/workflows/ci.yml` at the repository root, all steps in `billing/`. The repository is
private, so Linux minutes are billed **per job, rounded up to the next whole minute** — the design minimises
jobs, setup work and pointless runs.

## When it runs

| Event                                         | Runs?                                                       |
| --------------------------------------------- | ----------------------------------------------------------- |
| PR to `develop`/`main` (opened, push, reopen) | yes; superseded runs of the same PR are cancelled           |
| Draft PR                                      | no — runs when marked ready for review (`ready_for_review`) |
| Push to a feature branch                      | no (its PR run covers it — no duplicate push + PR runs)     |
| Push to `develop`/`main` (merges)             | yes, never cancelled                                        |
| Only Markdown / `*.png` / `billing/docs/**`   | in-job fast path: only `vp check` (Markdown formatting)     |
| Manual                                        | `workflow_dispatch`                                         |

## One job

Every job pays its own runner start, checkout, PHP/Node setup, dependency install (~25 s) and, for the
PostgreSQL suite, the service container start (~12–20 s), and each job is rounded up to a whole minute. One
job therefore costs less than several parallel ones. Steps run cheapest first so a failure stops early, and
the dependency policy runs **before** anything is installed (no package code — e.g. Composer's
`package:discover` hook — executes before the lockfiles pass):

| #   | Step                                                                                                 | s (run 39065947655) |
| --- | ---------------------------------------------------------------------------------------------------- | ------------------- |
| 0   | runner + PostgreSQL service container (`postgres:18.6-alpine@sha256…`)                               | 12                  |
| 1   | checkout (depth 2 for PRs, full history for pushes) · docs-only detection · dependency diff base     | 2                   |
| 2   | setup-php · setup-node (toolchain only, no project dependencies)                                     | ~8                  |
| 3   | postgres digest drift (ci.yml = compose.yaml)                                                        | 0                   |
| 4   | `composer validate` · `composer audit --locked` · `npm audit --audit-level=high --package-lock-only` | 2                   |
| 5   | dependency cooldown + binding (`check-dependency-age.mjs`, PR base or `github.event.before`)         | 2                   |
| 6   | install: Composer + npm from lockfiles (cached), `.env`, Wayfinder                                   | ~19                 |
| 7   | `vp check` (format + lint) — the only step on docs-only PRs                                          | 6                   |
| 8   | Pint · tsc · Vitest (`npm test`)                                                                     | 8 + ~3              |
| 9   | PHPStan (result cache restored)                                                                      | 6                   |
| 10  | Pest on SQLite (`--parallel`)                                                                        | 3                   |
| 11  | `composer test:pgsql` (migrate:fresh --seed + Pest on PostgreSQL)                                    | 6                   |
| 12  | `npm run build` · cache save · container stop                                                        | 6 + 7               |

`timeout-minutes: 15` caps a hung run. Shared setup lives in `.github/actions/setup-app` (composite action).

Caches (keys derived from lockfiles): Composer downloads (`composer.lock`), npm (`package-lock.json`, via
`setup-node`), PHPStan result cache (`storage/framework/cache/phpstan`, keyed on `composer.lock` +
`phpstan.neon` and restored from the latest run). Vite/vp builds have no persistent cache to reuse.

`pest --parallel` uses one process per core (2 on the standard runner, and on the 2-core Codespace where it
is exercised by `composer ci`); every process gets its own SQLite in-memory database or its own
`billing_test_test_<n>` PostgreSQL database, so it is reliable.

## Billable minutes per PR run

| Design                                    | Jobs | Job durations                                                                        | Billable    |
| ----------------------------------------- | ---- | ------------------------------------------------------------------------------------ | ----------- |
| Before (PR #1 layout; PRs #3–#5, develop) | 5    | measured: lint 43–52 s, static 23–34 s, sqlite 20–29 s, pgsql 39–59 s, build 34–43 s | **5 min**   |
| Considered: `quality` + `tests`           | 2    | estimated ≈ 75 s + ≈ 61 s                                                            | 3–4 min     |
| After: one job                            | 1    | measured 92 s (PR #9, run 39065947655, before Vitest)                                | **2 min**   |
| One job on 2026-10-18 (wave 1 merged)     | 1    | measured 202–319 s (PRs #8, #22; full suite on SQLite + PostgreSQL, Vitest)          | **4–6 min** |
| After, docs/png-only PR                   | 1    | ≈ 50 s                                                                               | **1 min**   |
| Draft PR / feature-branch push            | 0    | —                                                                                    | **0**       |

Each merge additionally runs once on `develop`. Measure real runs with `gh run view <id> --json jobs`
(job `startedAt`/`completedAt`); if the job regularly exceeds 120 s, the PHPStan cache and step order are the
first levers. Trade-off: feedback arrives after ~2 min instead of ~1 min, and there is one status check.

## Required checks

Mark `CI (policy, lint, types, static, tests, build)` as the required status check on `develop` and `main`.
There is no workflow-level `paths-ignore`, so every PR — including docs- or image-only ones — reports the
check (green via the in-job fast path) and branch protection never waits for a check that was not created.

## Changing the workflow

- Pin actions by SHA with the version comment on the same line; the dependency check enforces it
  (`dependency-policy.md`).
- Validate with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 from the repository root.
- Keep `timeout-minutes` on every job so a hung run cannot burn minutes.
