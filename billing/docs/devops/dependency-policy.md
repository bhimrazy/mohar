# Dependency policy — exact pins + 7-day cooldown

Everything we install is pinned to an exact version and must have been **published at least 7 days ago**
(`MIN_RELEASE_AGE_DAYS`, default 7) before it lands on `develop`. A week gives the ecosystem time to spot
compromised or broken releases (account takeovers, worm-style npm publishes, yanked regressions) before they
reach us. Decision record: [ADR 0007](../adr/0007-dependency-pinning-and-cooldown.md).

## Rules

| What                    | Pinning rule                                                                                                                                            | Cooldown enforced by                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Composer packages       | exact versions in `composer.json` (`"laravel/framework": "13.34.0"`), `composer.lock` is the source of truth                                            | `scripts/check-dependency-age.mjs` (packagist `time`)                                    |
| npm packages            | exact versions in `package.json` (checked by the script for `billing/` and `docs-site/`), `save-exact=true`, `package-lock.json` is the source of truth | `.npmrc` `min-release-age=7` (native) + the script (registry `time`), for both lockfiles |
| GitHub Actions          | full commit SHA + `# <tag>` comment on the same line; SHA must equal the tag                                                                            | the script (release date, tag → SHA)                                                     |
| Docker images           | `<name>:<tag>@sha256:<digest>` (compose and workflow `services:`)                                                                                       | the script (Docker Hub push date)                                                        |
| Tools                   | `billing/.nvmrc` (Node), `tools: composer:<x.y.z>` in the setup action                                                                                  | the script (nodejs.org / GitHub release)                                                 |
| Transitive dependencies | locked (ranges are allowed only below the direct level)                                                                                                 | the script (every locked package, not just direct ones)                                  |

CI installs only from lockfiles (`composer install`, `npm ci`); both fail or are rejected when the lockfile is
out of sync with the manifest (`composer validate`, `npm ci`). Exceptions to exact pinning: the PHP runtime
constraint (`"php": "^8.4"`, the runner provides the latest 8.4 patch) and `setup-php`'s `php-version: '8.4'`
(it cannot install an exact patch release).

Composer's own supply-chain policy is set explicitly in `composer.json` → `config.policy`: block installing
packages with security advisories or flagged as malware, report (not fail) abandoned packages.

## What each tool does natively (verified 2026-10-17)

| Tool                     | Native support                                                                                                                                                                                           |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| npm 11.19 (Node 24.21.0) | `save-exact`; `min-release-age=<days>` (+ `min-release-age-exclude`) and `before=<date>` filter versions when **resolving** (`npm install`/`update`). `npm ci` installs the lock as-is and ignores them. |
| Composer 2.10.3          | `config.policy` — `advisories.block` (default on), `malware.block` (default on), `abandoned`; `composer audit`. **No minimum release age**: `policy.minimum-release-age` is only a reserved name.        |
| Dependabot               | `cooldown.default-days` (we use 14) for version updates; **security updates ignore the cooldown**.                                                                                                       |
| Renovate                 | has `minimumReleaseAge`, but we do not run Renovate — the script fills the gap.                                                                                                                          |

## The check

```bash
node scripts/check-dependency-age.mjs              # items added/changed since merge-base with develop
node scripts/check-dependency-age.mjs --all        # every locked package, action, image and tool
node scripts/check-dependency-age.mjs --base=<ref> # explicit base (CI uses HEAD^1 = PR base)
MIN_RELEASE_AGE_DAYS=14 node scripts/check-dependency-age.mjs
composer deps:check                                # composer validate + composer audit + npm audit + the check
```

- Pinning rules are checked offline for every item; ages are fetched only for changed items (or all with
  `--all`). Publish dates are immutable and cached locally in `storage/framework/cache/dependency-age.json`
  (CI does not persist it; a PR run only fetches its few changed items).
- Every age is bound to what is actually installed: an action SHA must equal its tag; an image digest must be
  the tag's current multi-arch index digest (registry `Docker-Content-Digest`) or one of its platform digests
  (otherwise exit 2: its origin and age cannot be proven — re-pin); an npm `resolved` URL must be
  `https://registry.npmjs.org/<name>/-/<basename>-<version>.tgz` and its `integrity` must equal the registry's;
  a Composer `dist`/`source` URL and reference must equal packagist's for that version.
- The pure rules live in `scripts/dependency-policy.mjs` and are unit-tested offline
  (`scripts/__tests__/dependency-policy.test.mjs`, part of `npm test`), including the negative cases (another
  image's digest under a tag, a tarball of another package, a swapped integrity or Composer archive).
- Exit codes: `0` ok · `1` policy violation · `2` a date could not be verified (network/registry failure —
  never treated as a pass). Set `GITHUB_TOKEN` locally if you hit GitHub API rate limits.
- CI ([ci.md](ci.md)) runs it on every PR and push, together with `composer validate`, and — only when the change
  touches `billing/composer.json|lock`, `billing/package.json|package-lock.json`, `docs-site/package.json|package-lock.json`,
  any `.npmrc` or a workflow file — `composer audit --locked` and `npm audit --audit-level=high --package-lock-only`
  (for `billing/` and `docs-site/`). Why: on 2026-10-21 a newly published advisory turned every PR red at once
  (fixed by #206), so the owner decided (2026-10-21) that the audits block only changes to dependency files. A new
  advisory against unchanged lockfiles surfaces on the next dependency change or a manual `composer deps:check`;
  there is no nightly run while `main`, the default branch, stays a landing page (owner, 2026-10-21). All **before**
  `composer install` / `npm ci`, so no
  package code runs before the lockfiles pass. PR runs diff against the PR base, pushes against
  `github.event.before` (a new branch or an unreachable base checks `--all`). `composer ci` runs the same via
  `composer deps:check`.

## Adding or upgrading a dependency

1. Pick a version published ≥ 7 days ago (`npm view <pkg> time --json`, `composer show -a <pkg>` or
   packagist). With `min-release-age=7` in `.npmrc`, `npm install <pkg>` already skips newer versions.
2. Install it exactly: `npm install <pkg>@<x.y.z>` (saved exact) or `composer require <vendor/pkg>:<x.y.z>`.
   For a transitive Composer package: `composer update <vendor/pkg> --with=<vendor/pkg>:<x.y.z>`.
3. Run `composer deps:check` (and the full `composer ci`), commit manifest + lockfile together.
   After a downgrade, run `php artisan view:clear`: compiled Blade views of the newer package version (e.g.
   Wayfinder's templates) are otherwise reused because the older files have older mtimes. `composer ci` does it.
4. Adding a **new** direct dependency still needs CTO approval (ARCHITECTURE: "do not change the application's
   dependencies without approval").

Dependabot opens weekly PRs for actions, compose images, Composer and npm after its 14-day cooldown; they pass
the check without intervention. The CI PostgreSQL `services:` image is not updated by Dependabot — bump
it in the same PR as the compose.yaml update (CI fails on drift).

## Emergency security fixes (cooldown exception)

A fix for a critical/high vulnerability may be adopted before it is 7 days old:

1. Open the PR with the minimal version bump and link the advisory (GHSA/CVE) in the description.
2. Add the **`security-exception`** label **and** list every item to waive in the PR body, one line each:

    ```text
    Security-Exception: laravel/framework@13.36.1
    Security-Exception: @scope/pkg@1.2.3, other-pkg@4.5.6
    ```

    Only those exact `name@version` items skip the cooldown; anything else that is too new still fails, so
    fresh packages cannot ride along with a security bump. The job summary lists the waived items. Re-run the
    job after editing the label or body (both are read at run time). Pinning/binding checks, lockfile sync and
    the audits are **always enforced** whenever they run.

3. The CTO approves the PR explicitly (review comment naming the advisory). Without that approval it is not merged.
4. Locally: `DEPENDENCY_AGE_EXCEPTIONS='laravel/framework@13.36.1' composer deps:check`; npm needs
   `--min-release-age=0` (or `min-release-age-exclude`) for that one install.

**This is a process control, not a technical one**: the repository has a single admin account that every agent
and engineer acts as, so the label, the body and the approval can all be set by the PR author. Making it a
technical control needs a second identity (required review from a separate account, or a protected
environment with required reviewers).

Dependabot **security** updates bypass its cooldown, so they arrive as PRs that fail the check — handle them
with the same label + `Security-Exception` lines + approval flow.

## Open follow-ups

- [#11](https://github.com/bhimrazy/whmcs/issues/11) — bump `laravel/framework` to 13.36.x + `laravel/wayfinder`
  0.1.22 on/after 2026-10-22 (security hardening in framework #61837 and #61755 was skipped by the cooldown
  downgrade to 13.34.0); merge before `feature/platform`, or that PR takes the exception path.
