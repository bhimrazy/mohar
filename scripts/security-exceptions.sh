#!/usr/bin/env bash
# scripts/security-exceptions.sh <pr-number> <owner/repo>   The cooldown waivers of a PR (ADR 0007), for CI and the
# local merge gate.
#
# Without the `security-exception` label: prints nothing. With it: prints the PR body's "Security-Exception:" lines
# (the format check-dependency-age.mjs reads from DEPENDENCY_AGE_EXCEPTIONS), but only when the body has not been
# edited since the label was last added. A maintainer adds the label after reviewing the list; a later edit (by
# anyone, including the PR author) fails here until a maintainer removes and re-adds the label. That binds the list
# to the approval without a second source to keep in sync. The waivers are version-exact, so a new push cannot
# reuse one for another version.
# Exit codes: 0 ok · 1 the body changed after the label · 2 usage or GitHub API error. Needs gh (GH_TOKEN in CI).
set -euo pipefail

pr="${1:-}"
repository="${2:-}"
[[ "$pr" =~ ^[0-9]+$ ]] && [[ "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] \
    || { echo "usage: scripts/security-exceptions.sh <pr-number> <owner/repo>" >&2; exit 2; }

label=security-exception # also spelled out in jq_filter (gh's --jq takes no --arg)
# One read, so the label, its time, the edit time and the body are one consistent snapshot. Lines: label present,
# when it was last added, when the body was last edited (empty if never), then the body.
query='query($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    pullRequest(number: $number) {
      body
      lastEditedAt
      labels(first: 100) { nodes { name } }
      timelineItems(last: 100, itemTypes: [LABELED_EVENT]) { nodes { ... on LabeledEvent { createdAt label { name } } } }
    }
  }
}'
jq_filter='.data.repository.pullRequest
  | ([.labels.nodes[].name] | index("security-exception") != null),
    ([.timelineItems.nodes[] | select(.label.name == "security-exception") | .createdAt] | last // ""),
    (.lastEditedAt // ""),
    (.body // "")'
snapshot="$(gh api graphql -F owner="${repository%%/*}" -F name="${repository#*/}" -F number="$pr" -f query="$query" \
    --jq "$jq_filter")" \
    || { echo "security-exceptions: could not read PR #$pr of $repository" >&2; exit 2; }

present="$(sed -n 1p <<< "$snapshot")"
labelled_at="$(sed -n 2p <<< "$snapshot")"
edited_at="$(sed -n 3p <<< "$snapshot")"
[ "$present" = true ] || exit 0

# ISO 8601 UTC timestamps from the same API compare as strings.
if [ -z "$labelled_at" ] || { [ -n "$edited_at" ] && [[ "$edited_at" > "$labelled_at" ]]; }; then
    echo "security-exceptions: the PR body was edited (${edited_at:-?}) after the $label label was added (${labelled_at:-not found}); a maintainer re-checks the Security-Exception lines, then removes and re-adds the label" >&2
    exit 1
fi
sed -n '4,$p' <<< "$snapshot" | grep -iE '^[[:space:]]*Security-Exception:' || true
