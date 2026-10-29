---
name: resume-session
description: Resume the Mohar build after a Claude Code restart, a context reset, or on a new device. Audits git, worktrees, PRs, CI and agents, then restarts the work where it stopped. Use at the start of any session that continues earlier work.
---

# Resume session

1. **Read the state.** `docs/handoff/state.md`, then `docs/handoff/operating-model.md`. Note open PRs,
   in-progress workstreams and the "Next" list.

2. **Check the machine.**

   ```bash
   nproc 2>/dev/null || sysctl -n hw.ncpu; free -h 2>/dev/null || vm_stat | head -5; df -h /; uptime
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
   scripts/sweep-stale.sh    # dry run: merged worktrees, idle _review trees, orphan databases; --apply removes trees
   ```

   For each open PR: `gh pr checks <n>` and `gh pr view <n> --comments` to see the latest review verdict.

   Secure work before anything else. Push every worktree that has unpushed commits (`git -C <d> push`). For a
   worktree with staged or modified files, save a snapshot without touching its files, so the next reboot cannot
   lose it; the owning agent commits the real change later and the snapshot branch is then deleted:

   ```bash
   git -C <d> add -A && t=$(git -C <d> write-tree) && c=$(git -C <d> commit-tree $t -p HEAD -m "wip: snapshot after restart") \
     && git -C <d> push origin $c:refs/heads/wip/<branch>-snapshot && git -C <d> reset -q
   ```

   Merge green PRs whose merge watchers died with the session (watchers are shell jobs and stop too), but only
   those with a CTO sign-off comment at their current head; never on CI alone. Retarget
   PRs stacked on a branch that merged (`gh pr list --json number,baseRefName`). Check the repository's current
   visibility (`gh repo view --json visibility`): the CI rules differ (`operating-model.md`, "Repository
   visibility and CI").

4. **New device only.** Set `MOHAR_ROOT` to the main checkout in `.claude/settings.local.json`
   (`{"env": {"MOHAR_ROOT": "/path/to/mohar"}}`; the codespace uses `/workspaces/whmcs`), then install prerequisites from `billing/docs/devops/local-setup.md` (PHP 8.4 with
   pdo_pgsql, Composer, Node with npm, Docker, `gh auth login`). Then in `billing/`:
   `composer install`, `npm ci`, `cp .env.example .env`, `php artisan key:generate`,
   `touch database/database.sqlite`, `php artisan migrate --seed`, `php artisan wayfinder:generate --with-form`. For each in-progress branch run
   `git worktree add .claude/worktrees/<slug> feature/<slug>` and set up `billing/` the same way (or copy
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

     Resume within the agent budget (`operating-model.md`, "Agent budget": load below 8, 2 GB free, disk below
     85%), in order: re-checks of PRs awaiting sign-off, then fix rounds, then builds. The rest wait in the queue in
     `state.md`. A resumed agent keeps the model it started with; when the owner's model preference has changed,
     launch a fresh agent of the same type with a full brief instead (state found in step 3, head commit, dirty
     files, snapshot branch, PR, review links). Otherwise resume with SendMessage to its agent id and tell it the
     exact state found in step 3 and that processes it had running were stopped.
   - Restart the session's background shell jobs: the resource monitor (step 2), the storage cleanup loop
     (`scripts/storage-cleanup.sh --apply` every 30 minutes) and, if the owner was using it, the preview server
     on port 8000, detached so it survives a Claude Code restart (not a codespace reboot):

     ```bash
     # Codespace
     cd billing && XDEBUG_MODE=off DB_DATABASE=$PWD/database/preview.sqlite \
       APP_URL=https://$CODESPACE_NAME-8000.app.github.dev setsid nohup php artisan serve --host=0.0.0.0 \
       --port=8000 --no-reload > $MOHAR_ROOT/.claude/worktrees/_review/preview-8000.log 2>&1 < /dev/null &
     # macOS (no setsid; localhost only)
     cd billing && (XDEBUG_MODE=off DB_DATABASE=$PWD/database/preview.sqlite APP_URL=http://localhost:8000 \
       nohup php artisan serve --host=127.0.0.1 --port=8000 --no-reload \
       > $MOHAR_ROOT/.claude/worktrees/_review/preview-8000.log 2>&1 < /dev/null &)
     ```

     Long-running watchers (such as `.claude/worktrees/_review/merge-when-green.sh`) live in `.claude/worktrees/_review/`, not
     `/tmp`, which a reboot clears.
   - New device, or the id is unknown: launch a fresh agent of the right type from `.claude/agents/`
     with the brief template in the `run-workstream` skill, pointing at the existing branch, PR and
     review comments. Tell it to read the branch's commits before writing anything.

6. **Periodic reviews.** Run the `review-round` skill now if the newest file in `docs/reviews/` is older than 12
   hours, and recreate the 12-hour schedule with CronCreate.

7. **Report to the owner.** In a few lines: what was found, what was resumed, what is next. Then update
   `docs/handoff/state.md` if anything changed.
