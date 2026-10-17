# Dependency policy — exact pins + 7-day cooldown

Everything we install is pinned to an exact version and must have been **published at least 7 days ago**
(`MIN_RELEASE_AGE_DAYS`, default 7) before it lands on `develop`. A week gives the ecosystem time to spot
compromised or broken releases (account takeovers, worm-style npm publishes, yanked regressions) before they
reach us. Decision record: [ADR 0007](../adr/0007-dependency-pinning-and-cooldown.md).

## Rules

| What                    | Pinning rule                                                                                                 | Cooldown enforced by                                                 |
| ----------------------- | ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| Composer packages       | exact versions in `composer.json` (`"laravel/framework": "13.35.0"`), `composer.lock` is the source of truth | `scripts/check-dependency-age.mjs` (packagist `time`)                |
| npm packages            | exact versions in `package.json`, `save-exact=true`, `package-lock.json` is the source of truth              | `.npmrc` `min-release-age=7` (native) + the script (registry `time`) |
| GitHub Actions          | full commit SHA + `# <tag>` comment on the same line; SHA must equal the tag                                 | the script (release date, tag → SHA)                                 |
| Docker images           | `<name>:<tag>@sha256:<digest>` (compose and workflow `services:`)                                            | the script (Docker Hub push date)                                    |
| Tools                   | `billing/.nvmrc` (Node), `tools: composer:<x.y.z>` in the setup action                                       | the script (nodejs.org / GitHub release)                             |
| Transitive dependencies | locked (ranges are allowed only below the direct level)                                                      | the script (every locked package, not just direct ones)              |

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
  `--all`). Publish dates are immutable and cached in `node_modules/.cache/dependency-age.json`.
- Exit codes: `0` ok · `1` policy violation · `2` a date could not be verified (network/registry failure —
  never treated as a pass). Set `GITHUB_TOKEN` locally if you hit GitHub API rate limits.
- CI ([CI.md](CI.md)) runs it on every PR and push, together with `composer validate`, `composer audit --locked`
  and `npm audit --audit-level=high`. `composer ci` runs the same via `composer deps:check`.

## Adding or upgrading a dependency

1. Pick a version published ≥ 7 days ago (`npm view <pkg> time --json`, `composer show -a <pkg>` or
   packagist). With `min-release-age=7` in `.npmrc`, `npm install <pkg>` already skips newer versions.
2. Install it exactly: `npm install <pkg>@<x.y.z>` (saved exact) or `composer require <vendor/pkg>:<x.y.z>`.
   For a transitive Composer package: `composer update <vendor/pkg> --with=<vendor/pkg>:<x.y.z>`.
3. Run `composer deps:check` (and the full `composer ci`), commit manifest + lockfile together.
4. Adding a **new** direct dependency still needs CTO approval (ARCHITECTURE: "do not change the application's
   dependencies without approval").

Dependabot opens weekly PRs for actions, compose images, Composer and npm after its 14-day cooldown; they pass
the check without intervention. The CI PostgreSQL `services:` image is not updated by Dependabot — bump
it in the same PR as the compose.yaml update (CI fails on drift).

## Emergency security fixes (cooldown exception)

A fix for a critical/high vulnerability may be adopted before it is 7 days old:

1. Open the PR with the minimal version bump and link the advisory (GHSA/CVE) in the description.
2. Add the **`security-exception`** label. The cooldown step then reports violations as warnings instead of
   failing (re-run the job after labelling; it reads labels at run time). Pinning, SHA/tag integrity, lockfile
   sync and the audits are **still enforced**.
3. The CTO approves the PR explicitly (review comment naming the advisory). Without that approval it is not merged.
4. Locally: `DEPENDENCY_AGE_EXCEPTION=1 composer deps:check`; npm needs `--min-release-age=0` (or
   `min-release-age-exclude`) for that one install.

Dependabot **security** updates bypass its cooldown, so they arrive as PRs that fail the check — handle them
with the same label + approval flow.
