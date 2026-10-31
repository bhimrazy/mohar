# Dependency policy — exact pins + 7-day cooldown

Everything we install is pinned to an exact version and must have been **published at least 7 days ago**
(`MIN_RELEASE_AGE_DAYS`, default 7) before it lands on `develop`. A week gives the ecosystem time to spot
compromised or broken releases (account takeovers, worm-style npm publishes, yanked regressions) before they
reach us. Decision record: [ADR 0007](../adr/0007-dependency-pinning-and-cooldown.md).

## Rules

| What                    | Pinning rule                                                                                                                                            | Cooldown enforced by                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Composer packages       | exact versions in `composer.json` (`"laravel/framework": "13.36.0"`), `composer.lock` is the source of truth                                            | `scripts/check-dependency-age.mjs` (packagist `time`)                                    |
| npm packages            | exact versions in `package.json` (checked by the script for `billing/` and `docs-site/`), `save-exact=true`, `package-lock.json` is the source of truth | `.npmrc` `min-release-age=7` (native) + the script (registry `time`), for both lockfiles |
| GitHub Actions          | full commit SHA + `# <tag>` comment on the same line; SHA must equal the tag                                                                            | the script (release date, tag → SHA)                                                     |
| Docker images           | `<name>:<tag>@sha256:<digest>` (compose, workflow `services:`, setup-buildx `driver-opts: image=`)                                                      | the script (Docker Hub push date)                                                        |
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
| npm 11.19 (Node 24.22.1) | `save-exact`; `min-release-age=<days>` (+ `min-release-age-exclude`) and `before=<date>` filter versions when **resolving** (`npm install`/`update`). `npm ci` installs the lock as-is and ignores them. |
| Composer 2.10.3          | `config.policy` — `advisories.block` (default on), `malware.block` (default on), `abandoned`; `composer audit`. **No minimum release age**: `policy.minimum-release-age` is only a reserved name.        |
| Dependabot               | `cooldown.default-days` (we use 14) for version updates; **security updates ignore the cooldown**. Both work from the default branch only (see [Dependabot](#dependabot)).                               |
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
  `--all`). Publish dates are immutable and cached locally in `storage/framework/cache/dependency-age.json`. A cached
  date also skips the integrity, dist and SHA-to-tag checks made with that lookup, so the cache never comes from the
  tree being judged: the checker ignores it in GitHub Actions and fails when the file is committed, and CI and the
  merge gate point `DEPENDENCY_AGE_CACHE` at a fresh file outside the PR tree. A PR run only fetches its few changed
  items.
- The audits read their settings from the tree being judged, so CI runs the base checker **first**, before any
  tool that reads the PR's `composer.json` or `.npmrc`, and removes its copy before they run. Then:
    - Composer runs with `--no-scripts --no-plugins` (`validate` and `audit`). Without them a `pre-command-run`
      script in the PR's `composer.json` runs inside those commands.
    - npm audits a copy of `package.json` and `package-lock.json` in an empty directory, so no project `.npmrc` is
      read. It also gets `--registry=https://registry.npmjs.org/ --include=dev --strict-ssl=true
      --noproxy=registry.npmjs.org` on the command line; npm rejects an empty `--https-proxy=`, so the bypass is by
      host. `composer audit` gets `--abandoned=report`. This holds in CI, the weekly audit and the merge gate;
      `composer deps:check` uses the same flags but audits in place, on your own checkout.
    - An allowlist (`dependency-policy.mjs`): a diff run fails when a change touches any `.npmrc` key other than
      `save-exact`, `min-release-age`, `ignore-scripts`, `engine-strict` and `fund`. Keys are read the way npm reads
      them: a line ends at any CR or LF, so `"registry"=`, `'omit' = dev`, `ca[]=`, `userconfig=` (which loads another
      file) and keys behind a lone CR all count. It also fails when `billing/composer.json` changes `repositories` or
      any `config` key other than `allow-plugins`, `optimize-autoloader`, `preferred-install` and `sort-packages`
      (`config.audit`, `config.policy`, `config.disable-tls`, …). The error names the file and key. Adding a key to the
      allowlist is a change to `dependency-policy.mjs`, which only takes effect once it is on develop.
    - The `security-exception` label allows such a change. `.github/CODEOWNERS` also lists the manifests and `.npmrc`
      files.
- A push run (develop or main) is judged like the PR it merged: CI looks up the PR whose merge or squash commit is
  the pushed commit, merged into the pushed branch, and reads that PR's label and waivers through
  `security-exceptions.sh`. An approved waiver or setting change therefore does not turn the branch red after the
  merge (the #530 push did). A push that no merged PR produced has no waivers. Only the head commit's PR counts, for
  the whole pushed range: a push carrying several merged PRs is judged with the last one's label and waivers, so that
  label also approves an audit-setting change made by an earlier PR in the range.
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
  package code runs before the lockfiles pass. On a PR the checker, `dependency-policy.mjs`, `changelog-check.sh` and
  `security-exceptions.sh` are the **base branch's** copies (CI extracts them from `HEAD^1`, like
  `scripts/merge-gate.sh`), so a PR cannot weaken the check that judges it; a change to them takes effect after it
  merges. `.github/CODEOWNERS` asks the owner to review these files, the workflows and `deploy/`.
- `.github/workflows/dependency-audit.yml` runs weekly (and on demand): both audits and
  `check-dependency-age.mjs --all --min-days=0` on develop's lockfiles, nothing installed. The age is not re-judged
  for merged items; a failure is a new advisory, a broken pin or an image tag re-pushed upstream (re-pin it).
  Like every scheduled workflow it runs only once its file is on the default branch: until then this weekly signal
  does not exist, and `composer deps:check` before a release stays the only audit of an unchanged lockfile. PR runs diff against the PR base, pushes against
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

## Dependabot

`.github/dependabot.yml` asks for weekly PRs into `develop` for actions, compose images (`billing/`, `deploy/`,
`loadtest/`, one PR per image across them), the `deploy/Dockerfile` base images, Composer and npm, after a 14-day
cooldown; they pass the check without intervention. The CI PostgreSQL `services:` image is not updated by Dependabot —
bump it in the same PR as the compose.yaml update (CI fails on drift).

**None of this runs today.** Dependabot works from the default branch, and `main` (the landing page) carries neither
this file nor the manifests; no Dependabot PR has ever been opened. Security updates also need Dependabot alerts and
security updates turned on in the repository settings (both off), and they always target the default branch. Until
`main` carries the code (the first release) or the default branch changes, both are owner decisions, the vulnerability
signal is the weekly audit above once its file is on `main`, plus `composer deps:check` before a release.

## Emergency security fixes (cooldown exception)

A fix for a critical/high vulnerability may be adopted before it is 7 days old:

1. Open the PR with the minimal version bump and link the advisory (GHSA/CVE) in the description.
2. Add the **`security-exception`** label **and** list every item to waive in the PR body, one line each:

    ```text
    Security-Exception: laravel/framework@13.36.1
    Security-Exception: @scope/pkg@1.2.3, other-pkg@4.5.6
    ```

    Only those exact `name@version` items skip the cooldown; anything else that is too new still fails, so
    fresh packages cannot ride along with a security bump. The job summary lists the waived items. Pinning/binding
    checks, lockfile sync and the audits are **always enforced** whenever they run.

    The label approves the list as it stands: `scripts/security-exceptions.sh` (CI and the merge gate) fails when
    the PR body was edited after the label was last added, or in the same second, so nobody can widen the list
    after the review. The approver re-reads the body **after** adding the label: an edit made between their reading
    and their click would pass. To change the list, edit the body, have a maintainer re-check the lines, remove and
    re-add the label, then re-run the job (the label and the body are read at run time; adding a label other than
    `ci` does not start a run). The label also allows a change to the audit settings (above).

3. The CTO approves the PR explicitly (review comment naming the advisory). Without that approval it is not merged.
4. Locally: `DEPENDENCY_AGE_EXCEPTIONS='laravel/framework@13.36.1' composer deps:check`; npm needs
   `--min-release-age=0` (or `min-release-age-exclude`) for that one install.

Limits accepted for now:

- **One label approves both kinds of exception.** A PR labelled for a cooldown waiver may also change an audit setting
  outside the allowlist, and CI only warns. The reviewer who adds the label checks the diff of `composer.json` and
  every `.npmrc` as well as the `Security-Exception` lines; the warning annotation names each such change.
- **A PR into `main` is judged by its own scripts.** `main` has no `billing/scripts` until the first release, so CI
  falls back to the PR's copies, with a warning. A release PR carries develop's merged code, so these are develop's
  scripts unless the release branch changed them; read such a change before merging a release.
- **The PR's `billing/.nvmrc` picks the Node (and npm) that run the base checker and `npm audit`.** setup-node
  installs it before the checker runs. The checker accepts only an exact release listed on nodejs.org that has cleared
  the cooldown, and a change shows as a tool item in the check; Node older than 18 has no `fetch`, so the checker
  fails closed. No official release is known to make a check pass falsely. Pinning the policy steps to the base
  branch's `.nvmrc` would remove the PR's choice entirely, at the cost of a second Node setup.

**This is a process control, not a technical one**: the repository has a single admin account that every agent
and engineer acts as, so the label, the body and the approval can all be set by the PR author. The binding above
keeps an outsider (a fork author has no triage rights) from widening an approved list, but it cannot show who
added the label. The real gate is the CTO's sign-off comment naming the advisory at the PR's head commit, checked
before the merge (`gh pr merge --match-head-commit`). Making it a technical control needs a second identity
(required review from a separate account, or a protected environment with required reviewers).

Dependabot **security** updates, once they can arrive (see [Dependabot](#dependabot)), bypass its cooldown and fail
the check — handle them with the same label + `Security-Exception` lines + approval flow.

## Open follow-ups

- [#11](https://github.com/bhimrazy/whmcs/issues/11) — done in the PR that bumps `laravel/framework` 13.36.0 and
  `laravel/wayfinder` 0.1.22; transitives stay at their newest release older than 7 days.
