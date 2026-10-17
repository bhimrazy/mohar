# ADR 0007 — Exact dependency pins and a 7-day release cooldown

- Status: Accepted · 2026-10-17

## Context

Supply-chain attacks increasingly arrive as freshly published versions of popular packages (hijacked maintainer
accounts, self-propagating npm worms) and are usually detected and yanked within days. Floating ranges and
"latest" installs pull them in immediately. npm 11 can filter fresh versions when resolving
(`min-release-age`), Dependabot has a cooldown, but Composer 2.10 has no minimum release age and nothing checks
GitHub Actions, Docker images or the lockfiles themselves.

## Decision

- Direct Composer and npm dependencies are pinned to exact versions; lockfiles are the source of truth and CI
  installs only from them. Actions are pinned by commit SHA, images by digest, tools by exact version.
- Nothing may be adopted until it has been published for at least 7 days. `.npmrc` sets `save-exact=true` and
  `min-release-age=7`; Dependabot keeps a 14-day cooldown; `billing/scripts/check-dependency-age.mjs` (no
  dependencies) verifies publish dates of every changed locked package, action, image and tool in CI and in
  `composer ci`, and fails closed when a date cannot be verified.
- `composer audit` and `npm audit --audit-level=high` are required CI gates.
- Critical security fixes may skip the cooldown only with the `security-exception` PR label and explicit CTO
  approval; pinning and audit gates still apply.

When adopted, the lockfiles contained 94 packages younger than 7 days (incl. `laravel/framework` 13.37.0,
`react` 19.3.1, the Radix UI 1.8 family); they were moved back to the newest mutually compatible releases older
than 7 days (`laravel/framework` 13.34.0: 13.35 introduced the QUERY verb, which only Wayfinder ≥ 0.1.22 supports).

## Consequences

New releases (including bug fixes) reach us a week later; security fixes have an explicit, audited fast path.
Upgrades are deliberate PRs, and lockfile diffs are reviewable. Composer prints "exact version constraints
should be avoided" warnings for `composer validate`, which we accept for an application. Details and the
day-to-day workflow: `docs/devops/DEPENDENCY_POLICY.md`.
