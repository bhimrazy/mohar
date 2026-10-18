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

PostgreSQL runs in CI on every non-draft PR. To run it locally, start a private container on a free port and use
`DB_PORT=<port> composer test:pgsql` (see `billing/docs/devops/local-setup.md`). CI is a single job
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

- 4 vCPU, 15 GB RAM, 32 GB disk. No swap.
- `scripts/heavy.sh` allows 3 heavy commands machine-wide (`HEAVY_SLOTS`), at low CPU/IO priority.
- `scripts/resource-monitor.sh <log>` logs load, memory, disk and top processes every 30 s. Start it in
  the background at the start of each session; slow down (fewer agents) when load stays above 4.
- Disk: each worktree costs about 1 GB (`vendor` + `node_modules`). Remove worktrees once merged.
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

## Session restarts

Background agents and shell jobs stop when the Claude Code process exits, but agent transcripts survive
on the same machine. On restart: audit worktrees (commits ahead, dirty files), check open PRs and CI,
then resume each agent with SendMessage to its id, telling it what state its worktree is in. On a new
device the transcripts are not available: relaunch from the agent definitions and the brief template,
pointing the agent at the existing branch and PR. The `resume-session` skill covers both.
