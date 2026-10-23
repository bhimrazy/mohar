---
name: run-workstream
description: Run one Mohar workstream from spec to merged PR — create the worktree, brief the module engineer, run the review per the policy, verify fixes, merge and record. Use when starting, reviewing or merging a module, driver or importer.
---

# Run a workstream

The rules are in `docs/handoff/operating-model.md` (lifecycle, review policy, budget). This skill gives the
commands and the briefs.

## 1. Prepare

- The spec `billing/docs/product/<module>.md` exists with MUST/SHOULD acceptance criteria. If not, launch
  `product-manager` first. If the spec has "Owner to confirm" lines (local practice), put them to the owner before
  building; a minute of the owner's time saved days on `.np`.
- Check the agent budget (load below 8, 2 GB free, disk below 85%) and the open-PR count (no new build at eight or
  more awaiting review).
- `scripts/new-worktree.sh <slug>` creates `.worktrees/<slug>/billing` on `feature/<slug>` from a fresh
  `origin/develop`. For an existing worktree: `git -C .worktrees/<slug> merge --ff-only origin/develop`; if
  `package-lock.json` changed, the engineer runs `npm ci` first.

## 2. Brief the engineer

Launch `subagent_type: module-engineer` in the background with the model the owner currently prefers. The brief
adds what the agent definition does not know:

```
Module: <name>. Spec: billing/docs/product/<module>.md (implement v1 per its acceptance criteria;
out-of-scope section stays out). Expected size: <lines>; split proposed if above 2,000.
Worktree: $MOHAR_ROOT/.worktrees/<slug>/billing, branch feature/<slug>, at develop <sha>.
Setup: <npm ci needed? migrate:fresh --seed, wayfinder:generate --with-form>.
Dev server ports: <range, e.g. 8100-8199>; screenshots to billing/docs/screenshots/<module>/.
Module-specific constraints: <contracts to use, events to consume or emit, security requirements,
integration notes with modules already merged, Nepal defaults where relevant>.
Order of work: <phases, and what "done" means for each>.
Checks before the PR: confirm-then-apply flows act on a digest-bound snapshot re-read under lock; scheduled
queries name their index and cap work per run; email ownership only from a verified login, staff or an import;
touched tests plus tests/Arch on SQLite and PostgreSQL; phpstan, pint, tsc, vp check through scripts/heavy.sh.
Draft PR, no `ci` label; `[skip ci]` on WIP commits, not on the final head.
Merge origin/develop (no rebase); squash only against a fixed SHA, never a moving origin/develop.
```

## 3. Review

When the engineer reports a pushed, clean head, launch per the policy table (background, same model rule):

- Money, auth, permissions or migrations: `review-board` with "combined" in the brief (security threats and the
  tables in scope named), PR number, worktree, domain expertise, focus areas. One review.
- Other code: `review-board`, plus `product-reviewer` for user-facing changes (pages in scope, a free port).
- Shared kernel: `principal-architect` on the brief first, then on the PR with the board.
- Infrastructure: `review-board` with the DevOps focus (workflow safety, `tests/Unit/Deploy`).

## 4. Fix and re-check

Resume the same engineer (SendMessage to its id) with every blocking and medium item in one message, naming the
probe branch or file to adopt. When it reports a pushed, clean head:

- money, auth, permissions, migrations: launch `re-reviewer` with the item list;
- everything else: verify from the diff (`gh pr diff <n>`) and the adopted probes, and record what you checked in the
  sign-off comment.

Lows and non-money mediums become issues (`gh issue create` citing the review).

## 5. Merge (CTO)

```bash
cd $MOHAR_ROOT/.worktrees/<slug>/billing && export XDEBUG_MODE=off && H=$MOHAR_ROOT/scripts/heavy.sh \
&& git fetch -q origin && git merge --no-edit origin/develop \
&& $H composer install -q && $H npm ci --silent && php artisan wayfinder:generate --with-form \
&& vendor/bin/pint --dirty --format agent && $H vendor/bin/phpstan analyse --memory-limit=1G \
&& npx tsc --noEmit && npx vp check && npm test && $H npm run build \
&& $H php artisan test --compact tests/Arch <touched suites> \
&& git push origin feature/<slug>
gh pr ready <n>                       # only once no review at this head says "Changes requested"
gh pr checks <n> --watch              # CI runs the full suite on SQLite and PostgreSQL
gh pr comment <n> -b "CTO sign-off at <sha>: <each blocking item and its evidence>, gates, test count"
for s in $(gh pr list --base feature/<slug> --json number --jq '.[].number'); do gh pr edit $s --base develop; done
gh pr merge <n> --squash --match-head-commit <sha> --delete-branch   # --merge if another open PR is based on the branch
cd $MOHAR_ROOT && git pull --ff-only && git worktree remove .worktrees/<slug> && git branch -D feature/<slug>
```

- Code conflicts in money or auth PRs go back to the engineer; docs and lockfile conflicts the CTO resolves (changelog fragments do not conflict)
  (keep both sides' intent). The resolved head gets CI.
- `mergeable` reads `UNKNOWN` for a minute or two after CI; retry until `MERGEABLE`.
- Probe branches `review/pr-<n>-probes`: once adopted, tag as `archive/review/pr-<n>-probes` and delete the branch.
  Snapshot branches `wip/<branch>-snapshot` are deleted once the work lands.
- While the repository is private, replace the CI step with `scripts/merge-gate.sh <pr>` (#188) and merge at a
  `[skip ci]` head naming the gate result.

## 6. Record

- `docs/handoff/state.md`: move the row from in progress to merged; update the queue and "Running now".
- `billing/docs/progress.md`: status row and a timeline line. Check the PR added its fragment in `billing/changelog.d/`
  (and upgrade notes where behaviour or permissions change); add it if not. At a release, or when the folders grow,
  run `scripts/changelog-assemble.sh` and commit with the trailer `Changelog-Assemble: yes` (ADR 0027).
- After user-facing merges: a `product-reviewer` sweep of `develop`, fix or route its owner-blocking findings, then
  the owner update with screenshots.
