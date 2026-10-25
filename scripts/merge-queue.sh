#!/usr/bin/env bash
# scripts/merge-queue.sh <pr>:<signed-head>[:<issue>] ...  Merges each PR in order once its CI passes at the signed head.
# A PR that conflicts with develop only in the append-only logs (billing/changelog.md, the kernel changelog) is
# refreshed (develop merged, both entries kept),
# pushed, re-signed as a changelog-only change and merged after CI. Any other conflict stops the queue.
set -uo pipefail
R=bhimrazy/mohar
ROOT="${MOHAR_ROOT:-$(git rev-parse --show-toplevel)}"
wt() { git -C "$ROOT" worktree list --porcelain | awk -v b="refs/heads/$1" '/^worktree /{w=$2} $0=="branch "b{print w}'; }
ci() { for _ in $(seq 1 90); do s=$(gh pr checks "$1" --repo $R --json name,bucket -q '[.[]|select(.name|startswith("CI "))|.bucket]|first' 2>/dev/null); case "$s" in pass) return 0;; fail) return 1;; esac; sleep 60; done; return 1; }
for item in "$@"; do
    IFS=: read -r n h issue <<<"$item"
    [ "$(gh pr view "$n" --repo $R --json state -q .state)" = MERGED ] && { echo "$n already merged"; continue; }
    [ "$(gh pr view "$n" --repo $R --json headRefOid -q .headRefOid)" = "$h" ] || { echo "$n head moved from signed $h; stopping"; exit 1; }
    for _ in 1 2 3 4 5; do m=$(gh pr view "$n" --repo $R --json mergeable -q .mergeable); [ "$m" != UNKNOWN ] && break; sleep 20; done
    if [ "$m" = CONFLICTING ]; then
        b=$(gh pr view "$n" --repo $R --json headRefName -q .headRefName); d=$(wt "$b")
        [ -n "$d" ] && [ -z "$(git -C "$d" status --porcelain)" ] || { echo "$n conflicts and has no clean worktree; stopping"; exit 1; }
        git -C "$d" fetch -q origin && git -C "$d" merge -q origin/develop --no-edit >/dev/null 2>&1
        u=$(git -C "$d" diff --name-only --diff-filter=U)
        if [ -z "$u" ] || [ -n "$(printf '%s\n' "$u" | grep -vxE 'billing/changelog\.md|billing/docs/architecture/kernel-changelog\.md')" ]; then git -C "$d" merge --abort; echo "$n conflicts in: $u; stopping"; exit 1; fi
        for f in $u; do perl -i -ne 'if (/^=======$/) { print "\n" if $ARGV =~ /kernel-changelog/; next } print unless /^(<<<<<<< HEAD|>>>>>>> origin\/develop)$/' "$d/$f"; done
        git -C "$d" add $u && git -C "$d" commit -q --no-edit && git -C "$d" push -q || { echo "$n refresh push failed; stopping"; exit 1; }
        h=$(git -C "$d" rev-parse HEAD)
        gh pr comment "$n" --repo $R --body "CTO sign-off at $h. The only change since the last sign-off is a merge of develop that resolved a conflict only in the changelogs, keeping both entries." >/dev/null
        echo "$n refreshed to $h"; sleep 30
    fi
    ci "$n" || { echo "$n CI failed or timed out; stopping"; exit 1; }
    [ "$(gh pr view "$n" --repo $R --json headRefOid -q .headRefOid)" = "$h" ] || { echo "$n head moved; stopping"; exit 1; }
    # Squash unless another open PR is based on this branch (operating model, step 7).
    b=$(gh pr view "$n" --repo $R --json headRefName -q .headRefName)
    [ -n "$(gh pr list --repo $R --base "$b" --json number -q '.[].number')" ] && how=--merge || how=--squash
    gh pr merge "$n" --repo $R $how --match-head-commit "$h" >/dev/null && echo "$n MERGED ($how)" || { echo "$n merge refused; stopping"; exit 1; }
    [ -n "${issue:-}" ] && gh issue close "$issue" --repo $R --comment "Fixed by #$n." >/dev/null && echo "closed $issue"
done
