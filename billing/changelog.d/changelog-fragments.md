### Changed

- **Changelog entries go in fragments (ADR 0027).** A PR adds `billing/changelog.d/<branch-slug>.md` (and, for a kernel
  API change, `billing/docs/architecture/kernel-changelog.d/<branch-slug>.md`) instead of editing the changelogs, so
  parallel PRs no longer conflict on them. `scripts/changelog-assemble.sh` folds the fragments in at a release
  (`--preview` shows what it would add). CI fails a direct edit of either changelog, except in the assemble commit
  (trailer `Changelog-Assemble: yes`) and in PRs up to #393, fails a malformed fragment, and warns when app or kernel
  code changes without one. The local merge gate runs the same check. Contributor workflow only; no app change.
