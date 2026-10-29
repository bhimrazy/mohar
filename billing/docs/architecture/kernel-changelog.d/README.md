# Kernel-changelog fragments

A PR that changes the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`,
registry methods in `app/Support`, `App\Support\Money`) adds one file here instead of editing `kernel-changelog.md`.
Name it after the branch slug or the PR number, as for `billing/changelog.d/` (ADR 0027).

A fragment is one entry in the format the kernel changelog already uses: one `###` heading naming the change and the
PR, then bullets. No other heading, no frontmatter.

```markdown
### `ReturnHint::$signedPaid` (#323)

- `App\Data\Billing\ReturnHint` has a new last constructor parameter `bool $signedPaid = false`. Additive: existing
  implementers need no change.
```

Start the first bullet of a breaking change with `**Breaking.**`; the release that ships it reads the marker to bump
`App\Support\Kernel::API_VERSION` (ADR 0010 Amendment (#513)).

A change to the public signatures also regenerates `docs/architecture/kernel-api.txt` (`php artisan
kernel:api-snapshot`). CI fails when that file is stale, and when it changes in a PR that adds or edits no fragment here.

`scripts/changelog-assemble.sh` puts the entries at the top of `## Unreleased (pre-1.0)` and deletes the fragments.
CI also warns when a kernel file changes and the PR adds no fragment here.
