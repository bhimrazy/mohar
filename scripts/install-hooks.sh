#!/usr/bin/env bash
# Install the pre-push gate for this worktree only: scripts/install-hooks.sh (new-worktree.sh calls it).
# core.hooksPath is set with `git config --worktree`, so other worktrees and the global config are untouched.
# Concurrent worktree creation can hold .git/config.lock, so each write is retried (HOOKS_CONFIG_RETRIES attempts,
# HOOKS_CONFIG_DELAY seconds times the attempt number) and the result is read back; a failure exits non-zero.
set -euo pipefail
top="$(git rev-parse --show-toplevel)"
hooks="$top/scripts/githooks"
retries="${HOOKS_CONFIG_RETRIES:-8}"
delay="${HOOKS_CONFIG_DELAY:-0.25}"
chmod +x "$top/scripts/pre-push.sh" "$hooks/pre-push"

git_config() {
    local attempt=1
    until git -C "$top" config "$@" 2>/dev/null; do
        if ((attempt >= retries)); then
            echo "error: git config $* failed after $retries attempts (is .git/config.lock held?)" >&2
            return 1
        fi
        sleep "$(awk -v d="$delay" -v n="$attempt" 'BEGIN { print d * n }')"
        attempt=$((attempt + 1))
    done
}

# --worktree needs extensions.worktreeConfig, a repository-local setting.
git_config extensions.worktreeConfig true
git_config --worktree core.hooksPath "$hooks"

actual="$(git -C "$top" config --worktree --get core.hooksPath || true)"
if [[ "$actual" != "$hooks" ]]; then
    echo "error: core.hooksPath is '${actual:-unset}', expected '$hooks'; the pre-push gate is not installed" >&2
    exit 1
fi
echo "pre-push gate installed for $top: core.hooksPath=$actual (bypass: git push --no-verify)"
