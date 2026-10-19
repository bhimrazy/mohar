# Operating model

How the build is run. The main Claude Code session acts as CTO: it plans, writes briefs, launches
subagents, integrates their branches and merges. Subagents do the module work and the reviews.

## Roles

Definitions live in `.claude/agents/`. Pass the role's file name as `subagent_type` when launching.

| Role                 | Agent file            | Model   | Writes code  | Used for                                                               |
| -------------------- | --------------------- | ------- | ------------ | ---------------------------------------------------------------------- |
| CTO                  | main session          | —       | kernel only  | plans, briefs, integration, shared kernel, merges, owner updates       |
| Product manager      | `product-manager`     | inherit | no (docs)    | module specs with acceptance criteria, from public docs                |
| Principal designer   | `principal-designer`  | opus    | yes (UI)     | design system, themes, brand, UI reviews                               |
| Module engineer      | `module-engineer`     | inherit | yes          | one module per worktree, end to end, draft PR                          |
| DevOps engineer      | `devops-engineer`     | inherit | yes          | CI, Docker, Postgres, dependency policy, observability                 |
| Review board         | `review-board`        | opus    | no           | correctness, contracts, portability, AI-slop on every code PR          |
| Security reviewer    | `security-reviewer`   | opus    | no           | PRs touching auth, money, uploads, webhooks, external input            |
| QA + design reviewer | `qa-design-reviewer`  | opus    | no           | acceptance-criteria coverage, exploratory run, UI quality              |
| Re-reviewer          | `re-reviewer`         | opus    | no           | verifies every round-1 blocking/medium item after fixes                |
| AI-slop reviewer     | `slop-reviewer`       | opus    | no           | docs/infra PRs and handoff docs (folded into the board for code)       |
| Product reviewer     | `product-reviewer`    | opus    | small fixes  | the owner's eyes: product standards, directions, WHMCS parity          |
| Database reviewer    | `database-reviewer`   | opus    | small fixes  | schema, indexes, queries, locking, migrations, PostgreSQL parity       |
| Principal architect  | `principal-architect` | opus    | ADRs, kernel | boundaries, shared kernel, extension model, cross-cutting design, ADRs |

Reviewers are read-only. All agents run as the same GitHub account, so reviews are posted with
`gh pr review <n> --comment` (approval is not possible); the verdict line in the comment is the gate.

## Workstream lifecycle

1. **Spec.** `billing/docs/product/<module>.md` exists with acceptance criteria (MUST/SHOULD).
2. **Worktree.** `scripts/new-worktree.sh <slug>` → `.worktrees/<slug>/billing` on `feature/<slug>`.
3. **Build.** Launch `module-engineer` with the brief template in the `run-workstream` skill. The
   engineer commits in small steps, pushes, and opens a draft PR into `develop` with gates green.
4. **Round 1 review, in parallel:** `review-board` + `qa-design-reviewer` + `product-reviewer`
   (every user-facing PR), `database-reviewer` (every PR with migrations, models or
   non-trivial queries), `principal-architect` (every PR that changes the shared kernel), plus `security-reviewer`
   when the module handles auth, money, files, webhooks or third-party input.
5. **Fix.** Resume the same engineer (SendMessage to its agent id) with the review comments.
   Send all requested changes in one message, and start a review only after the engineer reports a pushed,
   clean head. Reviewing a head that is still changing wastes the review.
6. **Re-review.** `re-reviewer` checks each blocking and medium item against the new head.
7. **Merge (CTO).** Merge `origin/develop` into the branch, resolve conflicts, run all gates locally,
   mark the PR ready (`gh pr ready <n>`; CI skips drafts), wait for CI, post a sign-off comment, `gh pr merge <n> --merge`. Remove the worktree and branch.
8. **Before reporting to the owner:** run a `product-reviewer` sweep of `develop` after user-facing
   merges, act on its owner-blocking findings, and only then send the owner an update. When the owner
   corrects something, add it to `docs/handoff/product-standards.md` in the same session.
9. **Record.** Update `docs/handoff/state.md`, `billing/docs/progress.md`, `billing/changelog.md`.
   Non-blocking review items become GitHub issues.

Waves: modules with no dependency on each other run in parallel. With the current machine, at most two
engineers build at once; reviewers are lighter and can overlap.

## Gates by PR type (from the 2026-10-18 Fable review)

Use the smallest set that fits; more reviewers on a small PR waste time without catching more.

| PR type                            | Reviews before merge                                                                                                                   |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Docs or process only               | CI; `slop-reviewer` only for new handoff docs                                                                                          |
| UI polish, no migrations or money  | `review-board` (with the product-standards checklist) + CI                                                                             |
| Feature with migrations            | `review-board` + `database-reviewer` (+ `security-reviewer` for auth, money or external input) → one batched fix round → `re-reviewer` |
| Shared kernel or ADR               | `principal-architect` + `review-board`                                                                                                 |
| Driver (gateway, panel, registrar) | `review-board` + `security-reviewer` + contract-test dataset + a recorded real sandbox run in the PR body                              |

- `qa-design-reviewer` and `product-reviewer` overlap. Use `product-reviewer` (it includes QA) for user-facing
  PRs, and sweep `develop` once per wave rather than every PR.
- The architect reviews the brief and the kernel diff **before** the build, not only the finished PR.
- Specs are written before the build starts, and briefs cite `product-standards.md`.
- PR size cap is about 3,000 changed lines; split larger work before review.
- Every review verdict states "PostgreSQL run: yes/no". "No" means the review is incomplete. Draft PRs get the
  `ci` label so PostgreSQL CI runs before review.
  The label takes effect on the next push (adding the label from the codespace token does not start a run), and
  GitHub runs no PR workflow while a PR has merge conflicts with its base: merge `develop` first.
- Batch handoff and standards doc edits per session instead of one commit per line.

## Gates

Run from the worktree's `billing/` directory with `export XDEBUG_MODE=off`:

```bash
H=/workspaces/whmcs/scripts/heavy.sh
vendor/bin/pint --dirty --format agent
$H vendor/bin/phpstan analyse --memory-limit=1G     # Larastan level 7
php artisan wayfinder:generate --with-form
npx tsc --noEmit
npx vp check                                         # Vite+ lint + format
npm test                                             # Vitest
$H npm run build
$H php artisan test --compact                        # full Pest suite, SQLite
```

PostgreSQL runs in CI on every non-draft PR. To run it locally, use the one shared server with a database per worktree:
`eval "$(/workspaces/whmcs/scripts/pg.sh <slug>)" && composer test:pgsql` (server `mohar-pg` on 127.0.0.1:55432,
in-memory data; `scripts/pg.sh --stop` stops it; `scripts/pg.sh --prune <active slugs...>` drops idle test databases of finished work). Don't start private PostgreSQL containers. CI is a single job
(`billing/docs/devops/ci.md`) that took 3.5–5.5 minutes per run on 2026-10-18; keep it a single job.

## Integration rules

- Modules talk only through the shared kernel: contracts in `app/Contracts`, DTOs in `app/Data`, events
  in `app/Events/<Area>` (ADR 0008). `tests/Arch` enforces this.
- Registries (navigation, dashboard, permissions, settings, search, automation, themes, client merge)
  are how a module plugs into the app; a module must work when sibling modules are absent
  (`MODULES=` filter).
- PostgreSQL portability rules in `architecture.md` apply to all queries.
- Security baseline: `billing/docs/security/standards.md` (sensitive-permission tier, step-up with
  `password.recent`, CSP with nonce, secrets encrypted at rest and never sent to the browser,
  append-only audit log, client-area scoping through `ClientContext`). Payments settle only through
  server-side `lookup()`.

## Machine limits (GitHub Codespace)

- 4 vCPU, 15 GB RAM, 32 GB disk. No swap. The 32 GB disk is shared by `/`, `/workspaces` and Docker; about
  9 GB of it is the base image. `/vscode` (VS Code server) is a separate disk. `/tmp` is a separate large
  disk (about 100 GB free) that a codespace restart wipes: use it for disposable caches only (the npm
  cache lives in `/tmp/npm-cache`), never for work.
- `scripts/heavy.sh` allows 3 heavy commands machine-wide (`HEAVY_SLOTS`), at low CPU/IO priority.
- `scripts/resource-monitor.sh <log>` logs load, memory, disk and top processes every 30 s. Start it in
  the background at the start of each session; slow down (fewer agents) when load stays above 4.
- Disk: each worktree costs about 1 GB (`vendor` + `node_modules`). `scripts/storage-cleanup.sh` (dry run by
  default, `--apply` to act) removes worktrees whose PR is merged and that are clean and pushed, clears stale
  Laravel caches, prunes stopped containers and dangling images, and clears the npm cache only when free
  space is low. Run it in a background loop every 30 minutes during long sessions.
- Dev servers: give each agent its own port range (8100–8299) and stop servers by port PID
  (`ss -ltnp | grep :<port>`). Never `pkill -f`; it can match the calling shell.
- `/tmp` scratch space is wiped when the session restarts. Keep anything reusable in the repo or in
  `.worktrees/_review/` (local only, not committed).

## Conventions

- Branches: `feature/<slug>` from `develop`; `develop` is integration; `main` is release.
- Commit messages: Conventional Commits, ending with the attribution trailer the harness specifies.
- PR bodies: summary, spec coverage, shared-kernel changes, security notes, tests, screenshots.
- Screenshots: run `npm run images:optimize -- <paths>` (in `billing/`) before committing images; CI fails
  on any image over 500 KB (`npm run images:check`). Screenshots: headless Playwright against a running instance, saved under
  `billing/docs/screenshots/<module>/` and linked from progress.md.
- Tests: Pest feature tests per capability, reasonable not exhaustive. Browser E2E will use the Pest
  browser plugin (Playwright) for critical flows only.

## Periodic reviews (owner request, 2026-10-19)

Every 12 hours, and after a restart if the last round is older than that, the CTO session runs one review
round in parallel, read-only, each writing to `docs/reviews/<date>-<kind>.md`:

| Kind         | Agent                 | Model | Scope                                                                              |
| ------------ | --------------------- | ----- | ---------------------------------------------------------------------------------- |
| CEO          | `ceo-advisor`         | Fable | progress score, priorities, business risks, first-week experience of a host        |
| CTO          | `cto-advisor`         | Fable | velocity, recurring defects, gates, CTO decisions, debt                            |
| Security     | `security-reviewer`   | Opus  | whole `develop`: auth, permissions, money, secrets, outbound calls, webhooks, mail |
| Architecture | `principal-architect` | Opus  | kernel surface, module boundaries, ADR drift, scalability                          |

Rules: reviewers do not modify product code; the CTO merges the review files (docs-only PR), turns findings
into issues or workstream briefs, records owner-relevant items in `state.md`, and reports a short summary to
the owner. Skip a kind when nothing relevant changed since its last review. Heavy test runs go through
`scripts/heavy.sh`; when the machine is loaded, security runs targeted tests only. The schedule lives in the
session (CronCreate, every 12 hours) and is recreated by the `resume-session` skill after a restart.

## Session restarts

Background agents and shell jobs stop when the Claude Code process exits, but agent transcripts survive
on the same machine. On restart: audit worktrees (commits ahead, dirty files), check open PRs and CI,
then resume each agent with SendMessage to its id, telling it what state its worktree is in. On a new
device the transcripts are not available: relaunch from the agent definitions and the brief template,
pointing the agent at the existing branch and PR. The `resume-session` skill covers both.
