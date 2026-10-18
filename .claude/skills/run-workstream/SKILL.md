---
name: run-workstream
description: Run one Mohar workstream from spec to merged PR — create the worktree, brief the module engineer, run the review gates, verify fixes, merge and record. Use when starting, reviewing or merging a module, driver or importer.
---

# Run a workstream

The full process is in `docs/handoff/operating-model.md`. This skill gives the commands and the briefs.

## 1. Prepare

- The spec `billing/docs/product/<module>.md` exists with MUST/SHOULD acceptance criteria. If not,
  launch `product-manager` first.
- `scripts/new-worktree.sh <slug>` creates `.worktrees/<slug>/billing` on `feature/<slug>` from
  `develop`. If the worktree already exists, fast-forward it: `git -C .worktrees/<slug> merge --ff-only origin/develop`.
- If `package-lock.json` changed since the worktree was made, the engineer must run `npm ci` first.

## 2. Brief the engineer

Launch `subagent_type: module-engineer`, in the background. The brief adds what the agent definition
does not know:

```
Module: <name>. Spec: billing/docs/product/<module>.md (implement v1 per its acceptance criteria;
out-of-scope section stays out).
Worktree: /workspaces/whmcs/.worktrees/<slug>/billing, branch feature/<slug>, at develop <sha>.
Setup: <npm ci needed? migrate:fresh --seed, wayfinder:generate --with-form>.
Dev server ports: <range, e.g. 8100-8199>; screenshots to billing/docs/screenshots/<module>/.
Module-specific constraints: <contracts to use, events to consume or emit, security requirements,
integration notes with modules already merged>.
Order of work: <if several drivers or phases, which first and what "done" means for each>.
```

## 3. Review (round 1)

When the draft PR is up, launch in parallel (background):

- `review-board`: PR number, worktree, the domain expertise needed, focus areas from the spec.
- `qa-design-reviewer`: PR number, worktree, the main flows to drive, a free port.
- `product-reviewer`: PR number, worktree, pages in scope, a free port. Required for every
  user-facing PR; its owner-blocking findings block the merge like any other blocking item.
- `security-reviewer`: when the PR touches auth, permissions, money, files, webhooks or third-party
  input. Name the concrete threats for this module.

## 4. Fix and re-review

Resume the same engineer (SendMessage to its id) with the blocking and medium items. When it reports
fixes, launch `re-reviewer` with the PR number and the list of items to verify.

## 5. Merge (CTO)

```bash
cd .worktrees/<slug>/billing && export XDEBUG_MODE=off && H=/workspaces/whmcs/scripts/heavy.sh
git fetch -q origin && git merge --no-edit origin/develop      # resolve conflicts, keep both sides' intent
$H composer install -q && $H npm ci --silent
php artisan wayfinder:generate --with-form
vendor/bin/pint --dirty --format agent && $H vendor/bin/phpstan analyse --memory-limit=1G
npx tsc --noEmit && npx vp check && npm test && $H npm run build && $H php artisan test --compact
git push origin feature/<slug>
gh pr ready <n>                                                 # CI does not run on drafts
gh pr checks <n> --watch
gh pr comment <n> -b "CTO sign-off: <reviews passed, gates, test count>"
gh pr merge <n> --merge
cd /workspaces/whmcs && git pull --ff-only && git worktree remove .worktrees/<slug> && git branch -D feature/<slug>
```

## 6. Record

- `docs/handoff/state.md`: move the row from in-progress to merged; update test count and next steps.
- `billing/docs/progress.md`: status row and a timeline line.
- `billing/changelog.md`: what users and operators get, under `[Unreleased]`.
- Non-blocking review items: `gh issue create` with the PR reference.
- Run a `product-reviewer` sweep of `develop` when user-facing work merged, fix or route its
  owner-blocking findings, then send the owner a short update with screenshots.
