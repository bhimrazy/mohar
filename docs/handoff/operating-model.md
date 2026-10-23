# Operating model

How the build is run. The main Claude Code session acts as CTO: it plans, writes briefs, launches subagents,
integrates their branches and merges. Subagents do the module work and the reviews. This file states the rules in
force; the decisions that produced them are in the table at the end and in `history.md`.

## Roles

Definitions live in `.claude/agents/`. Pass the file name as `subagent_type` when launching. Reviewers follow
`docs/handoff/review-rules.md`.

| Role                | Agent file                   | Writes code  | Used for                                                                 |
| ------------------- | ---------------------------- | ------------ | ------------------------------------------------------------------------ |
| CTO                 | main session                 | kernel only  | plans, briefs, integration, shared kernel, merges, owner updates         |
| Product manager     | `product-manager`            | no (docs)    | module specs with acceptance criteria, from public docs; area research   |
| Principal designer  | `principal-designer`         | yes (UI)     | design system, themes, brand, UI directions                              |
| Module engineer     | `module-engineer`            | yes          | one module, driver or importer per worktree, end to end, draft PR        |
| DevOps engineer     | `devops-engineer`            | yes          | CI, Docker, deploy, load test, dependency policy, observability          |
| Review board        | `review-board`               | no           | the one review every code PR gets; "combined" adds security and database |
| Security reviewer   | `security-reviewer`          | no           | periodic security round; security re-checks; workflow safety             |
| Database reviewer   | `database-reviewer`          | small fixes  | database audits and performance work                                     |
| Re-reviewer         | `re-reviewer`                | no           | the re-check after the fix round on money, auth, permission, migrations  |
| AI-slop reviewer    | `slop-reviewer`              | no           | docs, infrastructure and process PRs (the board covers code)             |
| Product reviewer    | `product-reviewer`           | small fixes  | the owner's eyes: product standards, directions, WHMCS parity; sweeps    |
| Principal architect | `principal-architect`        | ADRs, kernel | kernel PRs (brief and diff), ADRs, periodic architecture round           |
| CEO / CTO advisors  | `ceo-advisor`, `cto-advisor` | no           | the periodic review round                                                |

Model: chosen at launch with the Agent tool's `model` parameter, not pinned in the definitions, because the owner
steers which model family has usage headroom. Choose by effort: Haiku for mechanical work (renames, lookups,
formatting), Sonnet for ordinary work (docs, slop reviews, ordinary features and fixes, research), Opus for design,
security and money (kernel, auth, architecture, payments, re-checks).
`module-engineer` is the exception: it is pinned to `sonnet` (the newest Sonnet) in its definition and launched
without a `model` override, payments and kernel work included (owner, 2026-10-22, after /usage showed it at 37%).
Opus stays on the reviewers (review-board on money/security PRs, re-reviewer, principal-architect). A stopped agent
keeps its model on resume, so switching family means a fresh agent with a full brief. All agents share one GitHub
account, so reviews are posted as comments and the verdict line is the gate.

## Workstream lifecycle

1. **Spec.** `billing/docs/product/<module>.md` exists with MUST/SHOULD acceptance criteria. Before a module that
   encodes local practice (registries, payment enrolment, tax filing, documents), the assumed workflow is put to the
   owner in one line and the answer waited for.
2. **Worktree.** `scripts/new-worktree.sh <slug>` → `.worktrees/<slug>/billing` on `feature/<slug>` from a freshly
   fetched `origin/develop`.
3. **Build.** Launch `module-engineer` with the brief template in the `run-workstream` skill. The engineer commits in
   small steps, pushes WIP as `[skip ci]`, verifies locally, and opens a draft PR into `develop` with gates green and
   a changelog entry. The architect reviews the brief and any kernel diff before the build, not only the PR.
4. **Review.** One review per the policy table below, launched only after the engineer reports a pushed, clean head.
5. **Fix.** Resume the same engineer (SendMessage to its id) with all requested changes in one message. Reviewer
   probes are adopted as tests in the fix push. At most two fix rounds per PR: if the second re-review finds a
   defect the fixes introduced, the PR stops and the architect redesigns it (invariant, input space, budget)
   before more code. A confirmed secret leak is never Low.
6. **Re-check.** `re-reviewer` for money, auth, permission and migration PRs, always; the CTO verifies from the diff
   for every other PR, when every adopted probe passes at
   the head.
7. **Merge (CTO).** Merge `origin/develop` into the branch, run the gates, mark ready, wait for CI, post the sign-off
   naming each blocking item and its evidence, `gh pr merge --merge`. Remove the worktree and branch.
8. **Record.** `state.md`, `billing/docs/progress.md`, `billing/changelog.md`. Non-blocking items become issues.
9. **Before an owner update** after user-facing merges: a `product-reviewer` sweep of `develop`; act on its
   owner-blocking findings first. Owner corrections go into `product-standards.md` the same session.

## Review policy

| PR type                                   | Before merge                                                                                         |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Docs or process only                      | CI fast path; `slop-reviewer` for new handoff or process docs                                        |
| UI, no migrations or money                | `review-board` (product-standards checklist) + `product-reviewer`; CTO verifies fixes from the diff  |
| Money, auth, permissions, migrations      | `review-board` combined (code, security, database) → fix round (cap: two) → `re-reviewer`            |
| Shared kernel or ADR                      | `principal-architect` on the brief, then `principal-architect` + `review-board` on the PR            |
| Driver (gateway, panel, registrar)        | `review-board` combined + the integration test matrix column + a recorded sandbox run in the PR body |
| Infrastructure (`deploy/`, workflows, CI) | `review-board` with the DevOps focus (workflow safety while public, `tests/Unit/Deploy` probes)      |

- No merge path without verification: a PR whose latest verdict is "Changes requested" is not marked ready. The
  sign-off names each blocking item and the evidence (the re-review for money, auth, permission and migration PRs; a diff read plus the adopted probe green for the rest).
- Flaky tests are fixed the same day (freeze time, serialise), filed as an issue and re-run until green. A flaky
  failure is not waved through, and quarantining a test is not a fix.
- Lows and non-money mediums become issues, not new rounds. Security re-checks stay mandatory after any security
  finding.
- Size cap: 2,000 changed lines, adopted probes excluded. An exception is named in the PR body with its reason, and
  engineers flag the size before building, not after.
- Reviews only on a pushed, clean head; the final head of a code PR must not carry `[skip ci]`.
- Code conflicts in money or auth PRs go back to the engineer; the CTO resolves docs, changelog and lockfile
  conflicts, and the resolved head gets CI either way.

## Agent budget

Up to 5 agents at once by default, periodic reviews included (owner approved 2026-10-22: more than 5 is fine when
needed, which overrides the default of 5 for Mohar); more when there is independent work, bounded by
machine load (on the 10-core, 24 GB Mac about 7 while the 1-minute load stays near the core count), at least 2 GB of
memory free and the disk below 85%. Never two agents on the same files: one owner per hot area (KYC images, the
Redactor, a registrar driver), and no two open PRs change the same pipeline. Order when a slot frees:
re-checks of PRs awaiting sign-off, then fix rounds, then new builds. No new builds while eight or more PRs await
review. `state.md` "Open pull requests and running work" lists the running work and the queue.

## Gates

Run from the worktree's `billing/` directory with `export XDEBUG_MODE=off`:

```bash
H=$MOHAR_ROOT/scripts/heavy.sh
vendor/bin/pint --dirty --format agent
$H vendor/bin/phpstan analyse --memory-limit=1G     # Larastan level 7
php artisan wayfinder:generate --with-form
npx tsc --noEmit
npx vp check                                         # Vite+ lint + format
npm test                                             # Vitest
$H npm run build
$H php artisan test --compact tests/Arch <touched suites>
```

On macOS, tests that need Linux tools (the simulators in `tools/simulators/tests` use `setsid`) run through
`$MOHAR_ROOT/scripts/linux.sh <command>` from the worktree's `billing/`, in the production base image.

CI is the full-suite runner on SQLite and PostgreSQL. Locally, engineers and reviewers run the tests they touched
plus `tests/Arch`, on PostgreSQL too for queries and migrations: `eval "$($MOHAR_ROOT/scripts/pg.sh <slug>)"`
gives a database on the shared server `mohar-pg` (in-memory, recreated on demand; `--stop` and `--prune` are for the CTO only). No private
PostgreSQL containers. The full suite runs locally only to diagnose a CI failure.

## Repository visibility and CI

The owner switches the repository (`bhimrazy/mohar`) between public and private as needed.

- **Public (current, since 2026-10-20 evening):** Actions minutes are free. CI (`.github/workflows/ci.yml`, one job,
  about 10 minutes) runs on every non-draft PR and builds `develop` on every push (#319); the E2E simulator job
  runs only on pushes to `main`; drafts run only with the `ci`
  label; the docs fast path runs only `vp check`. Deploy smoke (`deploy-smoke` label), load test (`load-test` label, once
  #108 merges) and the E2E simulator job (`e2e` label) run only on an explicit CTO decision. Before each switch to public,
  re-run the secret and workflow check: no secrets in history, no `pull_request_target` with a PR-head checkout,
  least `permissions:`, label-triggered jobs startable only by maintainers.
- **Security audits (owner decision 2026-10-21):** `composer audit` and `npm audit` block a PR or push only when it
  changes a manifest, lockfile, `.npmrc` or workflow file. No nightly audit while `main` is the default branch and a
  landing page (owner, 2026-10-21): run `composer deps:check` before cutting a release.
- **Private:** minutes are capped (3,000 a month, reached 90% on 2026-10-20). Merges are then gated locally by
  `scripts/merge-gate.sh <pr> <head-sha>` (#188, the same steps as CI on the PR merged into `develop`). The gate's
  summary is posted as a PR comment, the PR merges with `gh pr merge --match-head-commit <gated head>` (no extra
  commit), and smoke, load and E2E workflows stay off.
- Mechanics: a PR with merge conflicts gets no workflow run; a `[skip ci]` head also blocks label-triggered runs;
  adding a label from the codespace token does not start a run until the next push.
- `main` is a readme-and-licence landing page (all rights reserved) until the first release is cut.

## Integration rules

- Modules talk only through the shared kernel: contracts in `app/Contracts`, DTOs in `app/Data`, events in
  `app/Events/<Area>` (ADR 0008). `tests/Arch` enforces this. Registries (navigation, dashboard, permissions,
  settings, search, automation, themes, client merge) are how a module plugs in; a module works when siblings are
  absent (`MODULES=` filter).
- PostgreSQL portability rules in `architecture.md` apply to all queries. Money in integer minor units (ADR 0002).
- Security baseline: `billing/docs/security/standards.md`. Payments settle only through server-side `lookup()`.
- Integration PRs update `billing/docs/testing/integration-test-matrix.md`.
- Shared-kernel changes are made centrally by the CTO unless the PR body calls them out.

## Machine limits

The local machine is the owner's Mac (since 2026-10-22: 10 cores, 24 GB, Docker Desktop with 8 CPUs and 3.6 GB). It
is also the owner's own machine: the agent budget above applies, `/tmp` survives reboots but is still not for work, and
`storage-cleanup.sh` leaves Docker alone unless `STORAGE_DOCKER=1` (other projects share it). The rules below apply on both.

- Codespace: 4 vCPU, 15 GB RAM, 32 GB disk shared by `/`, `/workspaces` and Docker (about 9 GB is the base image). `/tmp` is a
  separate disk wiped on restart: disposable caches only (the npm cache lives there), never work.
- `scripts/heavy.sh` allows three heavy commands machine-wide at low priority. `scripts/resource-monitor.sh <log>`
  logs load, memory, disk and top processes every 30 s; start it each session.
- Each worktree costs about 1 GB. `scripts/storage-cleanup.sh --apply` removes worktrees whose PR is merged and that
  are clean and pushed, clears stale caches, and (on a codespace or with `STORAGE_DOCKER=1`) dangling images; run it every 30 minutes in long sessions.
- Browser checks use Playwright only (CPU-heavy runs go through `scripts/heavy.sh`), never the owner's Chrome (no Chrome DevTools MCP for agents). Scratch files
  get a unique name per agent (`$SCRATCH/<agent-slug>/`), so agents never overwrite each other.
- No `npx` for a tool outside the lockfile (`vp`, `tsc` and `playwright` are in it): it runs unvetted code.
- Never stop, kill or remove a Docker container you didn't start (`docker kill`, `stop`, `rm`, `compose down`):
  the shared test database `mohar-pg` and Mailpit serve every agent. Restarting
  `mohar-pg` is the CTO's call. Its data is on the Docker volume `mohar-pg-data` with a small write-ahead log (#375,
  #394); each agent drops its own databases when done.
- Never use `git stash`: the stash stack is shared by every worktree, so one agent can pop another's work. Set work
  aside with a temporary commit instead.
- `.claude/settings.json` denies these Docker commands, `git stash` (all forms, including `push -m`), `npx -y`
  and Chrome, so an agent cannot run them by mistake.
- Dev servers: each agent gets its own port range (8100–8299); port 8000 is the owner's preview and is never touched
  by agents. Stop servers by port PID; never `pkill -f`. Chain `cd` with `&&`; never run commands in the main
  checkout from an agent.

## Conventions

- Branches `feature/<slug>` from `develop`; `develop` is integration; `main` is release. Review probes on
  `review/pr-<n>-probes`, archived as tags after adoption. Snapshot branches `wip/<branch>-snapshot` are deleted once
  the work lands.
- Changelog fragments (`billing/changelog.d/<pr>.md`, assembled at release) are proposed and await the owner's
  decision; until then the single `billing/changelog.md` stands.
- Conventional Commits with no attribution trailer or footer (no `Co-Authored-By: Claude`; owner rule, 2026-10-22). PR bodies: summary, spec coverage,
  shared-kernel changes, security notes, tests, screenshots.
- Screenshots: headless Playwright, under `billing/docs/screenshots/<module>/`, through `npm run images:optimize`
  (CI fails on images over 500 KB).
- Markdown docs use kebab-case names, except `CLAUDE.md`. Docs are factual and plain.

## Periodic reviews

Every 12 hours, and after a restart if the last round is older, the CTO runs the `review-round` skill in two waves
(within the 5-agent limit): `ceo-advisor` and `cto-advisor`, then `security-reviewer` (whole `develop`) and
`principal-architect`, read-only, each writing
`docs/reviews/<date>-<kind>.md`. The owner's progress number is the rubric sum in `docs/reviews/readme.md`.

## Session restarts

The codespace reboots without warning. Engineers commit and push at least every 30 minutes (`wip(<scope>): … [skip
ci]`). Background agents and shell jobs stop with the Claude Code process; transcripts survive on the same machine.
The `resume-session` skill audits worktrees, snapshots uncommitted work to `wip/<branch>-snapshot`, restarts the
preview and monitors, and relaunches interrupted work.

## Decisions behind the rules

| Date       | Decision (owner unless noted)                                                                                                                                                                                                                                                                                                                        |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-18 | Fable review: smallest review set per PR type; probes as tests; architect before the build                                                                                                                                                                                                                                                           |
| 2026-10-19 | Periodic review round every 12 hours; WIP pushes `[skip ci]`                                                                                                                                                                                                                                                                                         |
| 2026-10-20 | Speed: combined review + one re-check; 2,000-line cap; agents to machine limits; `qa-design-reviewer` retired                                                                                                                                                                                                                                        |
| 2026-10-20 | CI is a budget while private (local merge gate, #188); then public with CI on PRs; `main` minimal, all rights reserved                                                                                                                                                                                                                               |
| 2026-10-20 | Nepal is the main market; `.np` is registered by clients themselves; confirm local practice before building                                                                                                                                                                                                                                          |
| 2026-10-21 | Fable for subagents, then Opus/Sonnet by task after Fable's weekly limit; repository renamed `bhimrazy/mohar`; shared `review-rules.md`; `agent-log.md` retired                                                                                                                                                                                      |
| 2026-10-22 | CTO review adopted: two fix rounds then redesign; 5 agents by default, more for independent work within machine load; model by effort; two-wave review round; one owner per hot area; same-day flaky fixes; `develop` built on push, E2E on `main`; Playwright only; no `npx` outside the lockfile. Changelog fragments proposed, awaiting the owner |
