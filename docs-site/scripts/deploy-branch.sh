#!/usr/bin/env bash
# Build the docs site locally and push the result to a branch that a static host serves (for example a gh-pages
# branch). The site is not published yet (owner decision, 2026-10-20); this is for when it is.
#
#   DOCS_REMOTE=<remote or URL> npm run deploy:branch            build, then show what would be pushed (dry run)
#   DOCS_REMOTE=<remote or URL> npm run deploy:branch -- --push  build and force-push dist/ as a single commit
#
# Settings (environment):
#   DOCS_REMOTE   git remote name or URL to push to (required; there is no default)
#   DOCS_BRANCH   branch to push                      (default: gh-pages)
#   DOCS_SITE     the site's origin, e.g. https://docs.example.com
#   DOCS_BASE     path the site lives under            (default: /)
#   DOCS_CNAME    custom domain to write into dist/CNAME (optional, for hosts that read it)
#
# The branch holds only the built files, replaced on every push, so it never grows. Pushing does not make anything
# public by itself: the host serving the branch does.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$here"

push=0
[ "${1:-}" = "--push" ] && push=1

remote="${DOCS_REMOTE:?Set DOCS_REMOTE to the git remote or URL to push to.}"
branch="${DOCS_BRANCH:-gh-pages}"
if git remote get-url "$remote" > /dev/null 2>&1; then
    url="$(git remote get-url "$remote")"
else
    url="$remote"
fi

if [ ! -d node_modules ]; then
    echo "Run 'npm ci --ignore-scripts' in docs-site/ first." >&2
    exit 1
fi

source_rev="$(git rev-parse --short HEAD)"
git diff --quiet HEAD -- . || source_rev="${source_rev}-dirty"

npm run build
touch dist/.nojekyll                         # GitHub Pages: serve folders such as _astro/ without Jekyll
if [ -n "${DOCS_CNAME:-}" ]; then
    printf '%s\n' "$DOCS_CNAME" > dist/CNAME
fi

files="$(find dist -type f | wc -l | tr -d ' ')"
size="$(du -sh dist | cut -f1)"
echo
echo "Built ${files} files (${size}) from ${source_rev}."

if [ "$push" != 1 ]; then
    echo "Dry run: would force-push dist/ to ${branch} on ${url}. Add --push to publish."
    exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cp -a dist/. "$work/"
git -C "$work" init -q -b "$branch"
git -C "$work" add -A
git -C "$work" \
    -c user.name="$(git config user.name)" -c user.email="$(git config user.email)" \
    commit -q -m "docs: publish site from ${source_rev}"
git -C "$work" push --force "$url" "${branch}:${branch}"
echo "Pushed to ${branch} on ${url}."
