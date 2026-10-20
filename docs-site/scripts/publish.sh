#!/usr/bin/env bash
# Build the docs site locally and push the result to a gh-pages branch. No GitHub Actions minutes are used to build.
#
#   npm run publish:pages                 build, then show what would be pushed (dry run)
#   npm run publish:pages -- --push       build and force-push dist/ as a single commit to the gh-pages branch
#
# Settings (environment):
#   DOCS_REMOTE   git remote name or URL to push to       (default: origin)
#   DOCS_BRANCH   branch GitHub Pages serves from          (default: gh-pages)
#   DOCS_SITE     site origin, e.g. https://docs.example.com (default in astro.config.mjs)
#   DOCS_BASE     path the site lives under, "/" for a custom domain (default in astro.config.mjs)
#   DOCS_CNAME    custom domain to write into dist/CNAME   (optional)
#
# The branch holds only the built files, replaced on every publish, so it never grows. Publishing does not turn
# GitHub Pages on: that is a repository setting (Settings -> Pages -> Deploy from a branch -> gh-pages, / (root)).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$here"

push=0
[ "${1:-}" = "--push" ] && push=1

remote="${DOCS_REMOTE:-origin}"
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
touch dist/.nojekyll                         # serve folders such as _astro/ as they are, without Jekyll
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
