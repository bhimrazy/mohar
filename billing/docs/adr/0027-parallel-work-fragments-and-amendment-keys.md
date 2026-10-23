# ADR 0027 — Parallel work: log fragments and amendment keys

- Status: Accepted · 2026-10-23 · Owner: Principal architect. From the architecture review of 2026-10-23 (§5, D1, D2).
  The owner adopted changelog fragments on 2026-10-23; #397 built the fragments, the assembler and the CI check.
- Related: ADR 0010 (kernel changelog), `scripts/merge-queue.sh`, issue #83.

## Context

Every open PR on 2026-10-23 edited `billing/changelog.md`. Nine PRs needed a changelog-only merge of `develop` and a
second CI run before they could merge. Two PRs (#365 and #368) chose ADR 0009 amendment numbers before either merged,
and ADR 0023 cites them by number. The ADR 0024 §10 table and ADR 0021 §3 are edited by each PR as running logs.

## Decision

1. **Changelog fragments.** A PR adds `billing/changelog.d/<branch-slug>.md`, with `### Added`, `### Changed`,
   `### Fixed` or `### Security` headings and bullets, and never edits `changelog.md`. At release,
   `scripts/changelog-assemble.sh` folds the fragments into `changelog.md` under the version and deletes them.
   `vp check` covers the folder.
2. **Kernel-changelog fragments.** A PR that changes the public kernel surface (ADR 0010) adds
   `billing/docs/architecture/kernel-changelog.d/<branch-slug>.md` in the current entry format. The same script
   assembles them. A CI check fails a diff under `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, a registry in
   `app/Support`, or `App\Support\Money`, when it adds no fragment. A PR with no API change opts out by stating
   `kernel-changelog: none (<reason>)` in its body.
3. **Amendments are keyed by PR.** A new amendment is headed `## Amendment (#<pr>) — <date>, <title>`, and is cited as
   "ADR 0009 Amendment (#368)". Existing numbered amendments keep their numbers.
4. **ADR numbers.** The next number is claimed by opening the PR. CI refuses two files in `billing/docs/adr/` with
   the same four-digit prefix.
5. **Plans are not logs.** An ADR build-order table (ADR 0024 §10) changes only by an amendment. Items a PR defers go
   to the workstream's tracking issue. Append-only lists that must stay in an ADR (ADR 0021 §3) take one bullet per
   PR, added at the end.
6. **Handoff files.** Only the CTO session edits `docs/handoff/state.md` and `billing/docs/progress.md`, once per
   merge batch. Module PRs do not.

## Consequences

- With no log conflicts, the merge queue runs one CI run per PR. The changelog refresh in `merge-queue.sh` is removed
  once the last open PR that edits `changelog.md` has merged.
- `architecture.md` "Git workflow" is updated to match (it still says the CTO maintains `changelog.md`).
- Reading the unreleased changes needs the folder, or the assembler's `--preview`.

## Alternatives rejected

- **A merge driver (`merge=union`) for the changelogs.** It keeps both sides, but it silently mangles Markdown lists,
  and it needs local git config on every machine and runner.
- **Sequential amendment numbers assigned by the CTO at merge.** That adds a rename to every merge, and a draft
  cannot be cited until it merges.
