# Changelog fragments

A PR never edits `billing/changelog.md`. It adds one file here instead, named after its branch slug
(`feature/vat-1b-notes` → `vat-1b-notes.md`) or its PR number (`412.md`): lower-case letters, digits and hyphens only, with LF line endings. New files never conflict, so PRs merge
without a changelog-only refresh (ADR 0027).

The format is plain Markdown with headings, no frontmatter. Use one or more of these sections, each at most once, each
followed by bullets written as they will read in the changelog:

```markdown
### Fixed

- **A refund that times out is no longer refunded twice (#334).** What users and operators get, then upgrade
  notes when behaviour, settings or permissions change.
```

Allowed sections: `### Added`, `### Changed`, `### Fixed`, `### Security`. Nothing else may come before the first
section or use another heading. To change an entry before release, edit the fragment.

`scripts/changelog-assemble.sh` folds the fragments into `changelog.md` under `## [Unreleased]` and deletes them. The
CTO runs it at a release or when the folder grows (`--preview` shows the result, `--check` only validates). Its commit
carries the trailer `Changelog-Assemble: yes`; CI fails any other PR that edits `changelog.md` directly, fails a
malformed fragment, and warns when app code changes without a fragment.
