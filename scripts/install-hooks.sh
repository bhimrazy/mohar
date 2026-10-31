#!/usr/bin/env bash
# Install the pre-push gate for this worktree only: scripts/install-hooks.sh (new-worktree.sh calls it).
# core.hooksPath is set with `git config --worktree`, so other worktrees and the global config are untouched.
set -euo pipefail
top="$(git rev-parse --show-toplevel)"
chmod +x "$top/scripts/pre-push.sh" "$top/scripts/githooks/pre-push"
# --worktree needs extensions.worktreeConfig, a repository-local setting.
git -C "$top" config extensions.worktreeConfig true
git -C "$top" config --worktree core.hooksPath "$top/scripts/githooks"
echo "pre-push gate installed for $top (bypass: git push --no-verify)"
