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

   Secure work before anything else. Push every worktree that has unpushed commits (`git -C <d> push`). For a
   worktree with staged or modified files, save a snapshot without touching its files, so the next reboot cannot
   lose it; the owning agent commits the real change later and the snapshot branch is then deleted:

   ```bash
   git -C <d> add -A && t=$(git -C <d> write-tree) && c=$(git -C <d> commit-tree $t -p HEAD -m "wip: snapshot after restart") \
     && git -C <d> push origin $c:refs/heads/wip/<branch>-snapshot && git -C <d> reset -q
   ```

   Merge green PRs whose merge watchers died with the session (watchers are shell jobs and stop too), and retarget
   PRs stacked on a branch that merged (`gh pr list --json number,baseRefName`).

4. **New device only.** Install prerequisites from `billing/docs/devops/local-setup.md` (PHP 8.4 with
   pdo_pgsql, Composer, Node with npm, Docker, `gh auth login`). Then in `billing/`:
   `composer install`, `npm ci`, `cp .env.example .env`, `php artisan key:generate`,
   `touch database/database.sqlite`, `php artisan migrate --seed`, `php artisan wayfinder:generate --with-form`. For each in-progress branch run
   `git worktree add .worktrees/<slug> feature/<slug>` and set up `billing/` the same way (or copy
   `vendor`, `node_modules` and `.env` as `scripts/new-worktree.sh` does).

5. **Resume agents and background jobs.**
   - Same machine: find the agents that were mid-task. Each subagent has a transcript and a `.meta.json`
     (type, description) in `~/.claude/projects/-workspaces-whmcs/<session-id>/subagents/`. Those whose last
     activity is the time the session stopped, and that never returned a final answer, were interrupted:

     ```bash
     cd ~/.claude/projects/-workspaces-whmcs/<session-id>/subagents
     for f in $(ls -t agent-*.jsonl | head -20); do a=${f#agent-}; a=${a%.jsonl}
       echo "$a $(tail -1 $f | jq -r '.timestamp[:16]') $(jq -r .description agent-$a.meta.json)"; done
     ```

     Resume each with SendMessage to its agent id. Tell it the exact state found in step 3 (head commit, dirty
     files, snapshot branch, PR) and that processes it had running were stopped.
   - Restart the session's background shell jobs: the resource monitor (step 2), the storage cleanup loop
     (`scripts/storage-cleanup.sh --apply` every 30 minutes) and, if the owner was using it, the preview server
     on port 8000:

     ```bash
     cd billing && XDEBUG_MODE=off DB_DATABASE=$PWD/database/preview.sqlite \
       APP_URL=https://$CODESPACE_NAME-8000.app.github.dev php artisan serve --host=0.0.0.0 --port=8000 --no-reload
     ```
   - New device, or the id is unknown: launch a fresh agent of the right type from `.claude/agents/`
     with the brief template in the `run-workstream` skill, pointing at the existing branch, PR and
     review comments. Tell it to read the branch's commits before writing anything.
   - Keep to the concurrency limits in `operating-model.md`: at most two engineers building at once.

6. **Periodic reviews.** Recreate the 12-hour review schedule (`operating-model.md`, "Periodic reviews")
   with CronCreate, and run a round now if the newest file in `docs/reviews/` is older than 12 hours.

7. **Report to the owner.** In a few lines: what was found, what was resumed, what is next. Then update
   `docs/handoff/state.md` if anything changed.
