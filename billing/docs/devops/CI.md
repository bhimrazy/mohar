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
| Only `billing/docs/screenshots/**` / `*.png`  | no (`paths-ignore`)                                         |
| Only Markdown / `billing/docs/**` (PR)        | the job runs `vp check` (Markdown formatting) only          |
| Manual                                        | `workflow_dispatch`                                         |

## One job

Every job pays its own runner start, checkout, PHP/Node setup, dependency install (~25 s) and, for the
PostgreSQL suite, the service container start (~20 s), and each job is rounded up to a whole minute. One job
therefore costs less than several parallel ones; steps run cheapest first so a failure stops early:

| #   | Step                                                                             | ≈ s (2-core runner) |
| --- | -------------------------------------------------------------------------------- | ------------------- |
| 0   | runner + PostgreSQL service container (`postgres:18.6-alpine@sha256…`)           | 22                  |
| 1   | checkout (depth 2) · docs-only detection                                         | 2                   |
| 2   | setup: Composer + npm from lockfiles (cached), `.env`, Wayfinder                 | 26                  |
| 3   | `vp check` (format + lint) — the only step on docs-only PRs                      | 5                   |
| 4   | postgres digest drift (ci.yml = compose.yaml)                                    | 0                   |
| 5   | `composer validate` · `composer audit --locked` · `npm audit --audit-level=high` | 5                   |
| 6   | dependency cooldown (`scripts/check-dependency-age.mjs --base=HEAD^1`)           | 2                   |
| 7   | Pint · tsc                                                                       | 11                  |
| 8   | PHPStan (result cache restored)                                                  | 4–8                 |
| 9   | Pest on SQLite (`--parallel`)                                                    | 6                   |
| 10  | `composer test:pgsql` (migrate:fresh --seed + Pest on PostgreSQL)                | 13                  |
| 11  | `npm run build` · cache save · container stop                                    | 14                  |

`timeout-minutes: 15` caps a hung run. Shared setup lives in `.github/actions/setup-app` (composite action).

Caches (keys derived from lockfiles): Composer downloads (`composer.lock`), npm (`package-lock.json`, via
`setup-node`), PHPStan result cache (`storage/framework/cache/phpstan`, keyed on `composer.lock` +
`phpstan.neon` and restored from the latest run). Vite/vp builds have no persistent cache to reuse.

`pest --parallel` uses one process per core (2 on the standard runner, and on the 2-core Codespace where it
is exercised by `composer ci`); every process gets its own SQLite in-memory database or its own
`billing_test_test_<n>` PostgreSQL database, so it is reliable.

## Billable minutes per PR run

| Design                                     | Jobs | Job durations                                                                        | Billable                 |
| ------------------------------------------ | ---- | ------------------------------------------------------------------------------------ | ------------------------ |
| Before (PR #1 layout; PRs #3–#5, develop)  | 5    | measured: lint 43–52 s, static 23–34 s, sqlite 20–29 s, pgsql 39–59 s, build 34–43 s | **5 min**                |
| Considered: `quality` + `tests`            | 2    | estimated ≈ 75 s + ≈ 61 s                                                            | 3–4 min                  |
| After: one job                             | 1    | estimated ≈ 110–120 s (sum of the measured step times above)                         | **2 min** (3 if > 120 s) |
| After, docs-only PR                        | 1    | ≈ 50 s                                                                               | **1 min**                |
| Draft PR / feature push / screenshots only | 0    | —                                                                                    | **0**                    |

Each merge additionally runs once on `develop`. Measure real runs with `gh run view <id> --json jobs`
(job `startedAt`/`completedAt`); if the job regularly exceeds 120 s, the PHPStan cache and step order are the
first levers. Trade-off: feedback arrives after ~2 min instead of ~1 min, and there is one status check.

## Required checks

Mark `CI (policy, lint, types, static, tests, build)` as the required status check on `develop` and `main`.
Docs-only PRs still report it (green), so branch protection never waits for a skipped job.

## Changing the workflow

- Pin actions by SHA with the version comment on the same line; the dependency check enforces it
  (`DEPENDENCY_POLICY.md`).
- Validate with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 from the repository root.
- Keep `timeout-minutes` on every job so a hung run cannot burn minutes.
