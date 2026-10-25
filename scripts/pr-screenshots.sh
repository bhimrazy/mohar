#!/usr/bin/env bash
# Publish screenshots for a PR description without adding a branch: the images go into a commit on
# refs/pr-assets/pr-<n>, which GitHub keeps but does not list under branches. Prints Markdown to paste.
#
#   scripts/pr-screenshots.sh <pr-number> <image>...
#
# Re-running for the same PR replaces the ref; links printed earlier keep their own commit sha. Keep the ref after
# merge: once it is deleted (--delete <pr-number>), GitHub may drop the images and the PR's links break.
set -euo pipefail

repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)

if [ "${1:-}" = "--delete" ]; then
    git push -q origin --delete "refs/pr-assets/pr-$2"
    exit 0
fi

[ $# -ge 2 ] || { echo "usage: $0 <pr-number> <image>... | --delete <pr-number>" >&2; exit 2; }
pr=$1; shift

index=$(mktemp)
trap 'rm -f "$index"' EXIT
export GIT_INDEX_FILE=$index
git read-tree --empty
for img in "$@"; do
    [ -f "$img" ] || { echo "not a file: $img" >&2; exit 1; }
    git update-index --add --cacheinfo "100644,$(git hash-object -w "$img"),$(basename "$img")"
done
commit=$(echo "Screenshots for #$pr" | git commit-tree "$(git write-tree)")
unset GIT_INDEX_FILE

git push -q -f origin "${commit}:refs/pr-assets/pr-$pr"

for img in "$@"; do
    name=$(basename "$img")
    echo "![${name%.*}](https://github.com/$repo/raw/$commit/$name)"
done
