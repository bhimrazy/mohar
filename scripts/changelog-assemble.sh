#!/usr/bin/env bash
# scripts/changelog-assemble.sh [--preview | --check] [--root <repo>]   (ADR 0027)
#
# Folds the changelog fragments into the two changelogs and deletes them. Run by the CTO at a release or when the
# folders grow; commit the result as one commit carrying the trailer "Changelog-Assemble: yes" (the marker CI's
# changelog check accepts for a direct edit of the changelogs).
#
#   billing/changelog.d/*.md                        -> billing/changelog.md, under "## [Unreleased]". A fragment
#       holds one or more of "### Added", "### Changed", "### Fixed", "### Security", each followed by bullets.
#       Each fragment's bullets go to the top of the matching section, before the entries already there; a missing
#       section is created right under "## [Unreleased]".
#   billing/docs/architecture/kernel-changelog.d/*.md -> kernel-changelog.md, under "## Unreleased (pre-1.0)". A
#       fragment is one entry: a "### <title> (#<pr>)" heading and bullets. Entries go above the existing ones.
#
# Fragments are taken in file-name order (LC_ALL=C); README.md is skipped. Nothing is written unless every fragment
# is valid. Without fragments it changes nothing. --preview prints what would be added and changes nothing;
# --check only validates (CI). Works with bash 3.2 and BSD or GNU awk.
set -euo pipefail
export LC_ALL=C

mode=write
root="$(cd "$(dirname "$0")/.." && pwd)"
while [ $# -gt 0 ]; do
    case "$1" in
        --preview) mode=preview ;;
        --check) mode=check ;;
        --root) root="${2:?--root needs a directory}"; shift ;;
        -h | --help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "changelog-assemble: unknown argument $1" >&2; exit 2 ;;
    esac
    shift
done

app_dir="billing/changelog.d"
app_log="billing/changelog.md"
app_heading="## [Unreleased]"
kernel_dir="billing/docs/architecture/kernel-changelog.d"
kernel_log="billing/docs/architecture/kernel-changelog.md"
kernel_heading="## Unreleased (pre-1.0)"
sections="Added Changed Fixed Security"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

fragments() {
    [ -d "$root/$1" ] || return 0
    find "$root/$1" -mindepth 1 -maxdepth 1 -type f -name '*.md' ! -name README.md | sort
}

errors=0
bad() { echo "changelog-assemble: $*" >&2; errors=$((errors + 1)); }

name_ok() { [[ "$(basename "$1")" =~ ^[a-z0-9][a-z0-9-]*\.md$ ]] || bad "${1#"$root"/}: name it <branch-slug>.md or <pr>.md (lower case, digits, hyphens)"; }

# Validates one changelog.md fragment and appends each section's bullets to $tmp/app.<Section>.
parse_app() {
    awk -v file="${1#"$root"/}" -v out="$tmp/app" -v allowed="$sections" '
        function err(m) { print "changelog-assemble: " file ":" FNR ": " m > "/dev/stderr"; bad = 1 }
        BEGIN { n = split(allowed, list, " "); for (i = 1; i <= n; i++) ok[list[i]] = 1 }
        /^#/ {
            name = $0; sub(/^### /, "", name); sub(/[ \t]+$/, "", name)
            if ($0 !~ /^### / || !(name in ok)) { err("\"" $0 "\" is not a section; use ### Added, ### Changed, ### Fixed or ### Security"); sec = ""; skip = 1; next }
            if (name in seen) err("section " name " appears twice")
            seen[name] = 1; order[++count] = name; sec = name; skip = 0; next
        }
        sec == "" { if ($0 ~ /[^ \t]/ && !skip) err("text before the first section heading"); next }
        /^- / { bullets[sec]++ }
        $0 ~ /[^ \t]/ && !bullets[sec] { err("section " sec " must start with a bullet (\"- \")") }
        { body[sec] = body[sec] $0 "\n" }
        END {
            if (!count && !bad) err("no section; start with ### Added, ### Changed, ### Fixed or ### Security")
            for (i = 1; i <= count; i++) if (!bullets[order[i]]) err("section " order[i] " has no entry")
            if (bad) exit 1
            for (i = 1; i <= count; i++) {
                b = body[order[i]]; sub(/^[ \t\n]+/, "", b); sub(/[ \t\n]+$/, "", b)
                print b >> (out "." order[i])
            }
        }' "$1"
}

# Validates one kernel-changelog fragment and appends it, followed by a blank line, to $tmp/kernel.
parse_kernel() {
    awk -v file="${1#"$root"/}" -v out="$tmp/kernel" '
        function err(m) { print "changelog-assemble: " file ":" FNR ": " m > "/dev/stderr"; bad = 1 }
        /^#/ {
            if ($0 ~ /^### [^ \t]/ && !heads) { heads = 1 } else { err("\"" $0 "\": one \"### <title> (#<pr>)\" heading per fragment, nothing else") }
            all = all $0 "\n"; next
        }
        $0 ~ /[^ \t]/ && !heads { err("the fragment must start with a \"### <title> (#<pr>)\" heading") }
        /^- / { bullets++ }
        { all = all $0 "\n" }
        END {
            if (!heads && !bad) err("no \"### <title> (#<pr>)\" heading")
            if (heads && !bullets) err("the entry has no bullet (\"- \")")
            if (bad) exit 1
            sub(/^[ \t\n]+/, "", all); sub(/[ \t\n]+$/, "", all)
            printf "%s\n\n", all >> out
        }' "$1"
}

app_files="$(fragments "$app_dir")"
kernel_files="$(fragments "$kernel_dir")"
for f in $app_files; do name_ok "$f"; parse_app "$f" || errors=$((errors + 1)); done
for f in $kernel_files; do name_ok "$f"; parse_kernel "$f" || errors=$((errors + 1)); done
app_count=$(printf '%s' "$app_files" | grep -c . || true)
kernel_count=$(printf '%s' "$kernel_files" | grep -c . || true)

if [ "$errors" -gt 0 ]; then
    echo "changelog-assemble: $errors problem(s); nothing changed. Format: $app_dir/README.md, $kernel_dir/README.md" >&2
    exit 1
fi
if [ "$mode" = check ]; then
    echo "changelog-assemble: $app_count changelog and $kernel_count kernel-changelog fragment(s) valid"
    exit 0
fi
if [ "$app_count" = 0 ] && [ "$kernel_count" = 0 ]; then
    echo "changelog-assemble: no fragments; nothing to do"
    exit 0
fi

if [ "$mode" = preview ]; then
    for s in $sections; do
        if [ -f "$tmp/app.$s" ]; then printf '%s, ### %s:\n\n%s\n\n' "$app_log" "$s" "$(cat "$tmp/app.$s")"; fi
    done
    if [ -f "$tmp/kernel" ]; then printf '%s:\n\n%s\n' "$kernel_log" "$(cat "$tmp/kernel")"; fi
    exit 0
fi

# Inserts the parsed fragments under the heading; two passes over the log (the first finds the existing sections).
merge_app() {
    awk -v dir="$tmp" -v order="$sections" -v heading="$app_heading" '
        function slurp(f,   line, s) { s = ""; while ((getline line < f) > 0) s = s line "\n"; close(f); return s }
        function trimmed(s) { sub(/[ \t]+$/, "", s); return s }
        BEGIN { n = split(order, S, " "); for (i = 1; i <= n; i++) { c = slurp(dir "/app." S[i]); if (c != "") add[S[i]] = c } }
        FNR == NR {
            if (trimmed($0) == heading && !found) { found = 1; inside = 1; next }
            if (inside && /^## /) inside = 0
            if (inside && /^### /) have[substr(trimmed($0), 5)] = 1
            next
        }
        skipping && $0 ~ /^[ \t]*$/ { next }
        { skipping = 0 }
        trimmed($0) == heading && !done {
            done = 1; inside = 1; print
            for (i = 1; i <= n; i++) if ((S[i] in add) && !(S[i] in have)) printf "\n### %s\n\n%s", S[i], add[S[i]]
            next
        }
        inside && /^## / { inside = 0 }
        inside && /^### / && (substr(trimmed($0), 5) in add) && (substr(trimmed($0), 5) in have) && !used[substr(trimmed($0), 5)]++ {
            print; printf "\n%s", add[substr(trimmed($0), 5)]; skipping = 1; next
        }
        { print }
        END { if (!found) { print "changelog-assemble: no \"" heading "\" heading" > "/dev/stderr"; exit 1 } }' "$1" "$1"
}

merge_kernel() {
    awk -v entries="$tmp/kernel" -v heading="$kernel_heading" '
        BEGIN { while ((getline line < entries) > 0) add = add line "\n"; close(entries) }
        skipping && $0 ~ /^[ \t]*$/ { next }
        { skipping = 0 }
        { t = $0; sub(/[ \t]+$/, "", t) }
        t == heading && !found { found = 1; print; printf "\n%s", add; skipping = 1; next }
        { print }
        END { if (!found) { print "changelog-assemble: no \"" heading "\" heading" > "/dev/stderr"; exit 1 } }' "$1"
}

# Both results are built before either log is replaced, so a failure leaves everything as it was.
if [ "$app_count" != 0 ]; then merge_app "$root/$app_log" > "$tmp/app.out"; fi
if [ "$kernel_count" != 0 ]; then merge_kernel "$root/$kernel_log" > "$tmp/kernel.out"; fi
if [ "$app_count" != 0 ]; then cat "$tmp/app.out" > "$root/$app_log"; fi
if [ "$kernel_count" != 0 ]; then cat "$tmp/kernel.out" > "$root/$kernel_log"; fi
for f in $app_files $kernel_files; do rm -- "$f"; done

echo "changelog-assemble: $app_count fragment(s) into $app_log, $kernel_count into $kernel_log; fragments deleted."
echo "Next: (cd billing && npx vp check --fix), review the diff, then commit:"
echo "  git add -A $app_dir $kernel_dir $app_log $kernel_log"
echo "  git commit -m 'docs(changelog): assemble fragments' -m 'Changelog-Assemble: yes'"
