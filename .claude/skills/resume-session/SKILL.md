---
name: resume-session
description: Resume the Mohar build after a Claude Code restart, a context reset, or on a new device. Audits git, worktrees, PRs, CI and agents, then restarts the work where it stopped. Use at the start of any session that continues earlier work.
---

# Resume session

1. **Read the state.** `docs/handoff/state.md`, then `docs/handoff/operating-model.md`. Note open PRs,
   in-progress workstreams and the "Next" list.

2. **Check the machine.**

   ```bash
   nproc; free -h; df -h /; uptime
   ```

   Start the resource monitor in the background:
   `scripts/resource-monitor.sh <scratchpad>/resources.log`.

3. **Sync git.**

   ```bash
   git fetch -q origin && git checkout develop && git pull --ff-only
   git worktree list --porcelain | awk '/^worktree /{print $2}' | tail -n +2 | while read -r d; do
     echo "$d $(git -C "$d" branch --show-current) ahead=$(git -C "$d" rev-list --count origin/develop..HEAD) dirty=$(git -C "$d" status --short | wc -l)"
   done
   gh pr list; gh issue list
   ```

   For each open PR: `gh pr checks <n>` and `gh pr view <n> --comments` to see the latest review verdict.

4. **New device only.** Install prerequisites from `billing/docs/devops/local-setup.md` (PHP 8.4 with
   pdo_pgsql, Composer, Node with npm, Docker, `gh auth login`). Then in `billing/`:
   `composer install`, `npm ci`, `cp .env.example .env`, `php artisan key:generate`,
   `touch database/database.sqlite`, `php artisan migrate --seed`, `php artisan wayfinder:generate --with-form`. For each in-progress branch run
   `git worktree add .worktrees/<slug> feature/<slug>` and set up `billing/` the same way (or copy
   `vendor`, `node_modules` and `.env` as `scripts/new-worktree.sh` does).

5. **Resume agents.**
   - Same machine: resume each in-progress agent with SendMessage to its agent id (ids are in the
     previous session transcript). Tell it the exact state found in step 3 (head commit, dirty files, PR)
     and that processes it had running were stopped.
   - New device, or the id is unknown: launch a fresh agent of the right type from `.claude/agents/`
     with the brief template in the `run-workstream` skill, pointing at the existing branch, PR and
     review comments. Tell it to read the branch's commits before writing anything.
   - Keep to the concurrency limits in `operating-model.md`: at most two engineers building at once.

6. **Periodic reviews.** Recreate the 12-hour review schedule (`operating-model.md`, "Periodic reviews")
   with CronCreate, and run a round now if the newest file in `docs/reviews/` is older than 12 hours.

7. **Report to the owner.** In a few lines: what was found, what was resumed, what is next. Then update
   `docs/handoff/state.md` if anything changed.
