# Infrastructure security review — 2026-10-31

## Security review — No blocking issues

Scope: the deploy and delivery stack on `develop` at `8c08e81a` (#532 merged). That covers `.github/` (workflows,
composite action, Dependabot), `deploy/` (Dockerfile, compose files, both Caddyfiles, entrypoint, `bin/deploy`,
`bin/smoke`), `loadtest/` compose and runner, `scripts/*.sh`, the dependency policy (ADR 0007,
`billing/scripts/check-dependency-age.mjs`, `dependency-policy.mjs`, the #530 exceptions) and `billing/docs/devops/`.
Application code is out of scope: it was reviewed on 2026-10-29 (`docs/reviews/2026-10-29-security.md`).

What ran: source reading. Read-only `gh api` calls showed the repository settings (visibility, branch protection,
Actions permissions, fork approval, secrets count, security and analysis). One pickaxe search ran over all 2,385
commits for private keys and provider tokens. One `node` probe ran against `dependency-policy.mjs`. No Docker build,
image pull, workflow run or remote host was touched.

**Verdict.** No blocking item and no High finding. The stack is carefully hardened:

- every action and image is pinned, and the pins are enforced;
- no `${{ }}` reaches a `run:` block;
- the app containers are non-root, read-only, with `cap_drop: ALL`;
- the database and object store sit on an internal network;
- log redaction is pinned by the smoke test.

There are three Medium findings, all in the supply-chain gate: what judges a PR, and what raises an alarm. The PR can
change its own checker (M1). The cooldown waiver is read from a PR body that someone else can edit after approval
(M2). And no new CVE in a locked dependency raises any signal (M3).

## Blocking

None.

## Medium

### M1. CI runs the PR's own copy of the dependency and changelog checkers

- **Where:** `.github/workflows/ci.yml:141` (`../scripts/changelog-check.sh`) and `.github/workflows/ci.yml:208-212`
  (`node scripts/check-dependency-age.mjs`, which imports `billing/scripts/dependency-policy.mjs`). On `pull_request`
  the checkout is the PR merge commit, so these are the PR's versions.
- **Impact:** a PR can weaken the cooldown and pinning gate in the same diff that needs the weakening. The required
  check (`CI (policy, lint, types, static, tests, build)`) then reports green. `scripts/merge-gate.sh:249-276` already
  runs develop's copies for exactly this reason ("so a PR cannot weaken the check that judges it"). CI does not.
- **Failure scenario:** a fork PR "fixes a typo" in `dependency-policy.mjs`, changing `normalise()` so that every
  item counts as excepted. In the same PR it bumps a transitive npm package to a version published yesterday. The
  cooldown step passes. The only remaining control is the reviewer noticing a one-line semantic change in a policy
  module. No CODEOWNERS file exists, and branch protection has `require_code_owner_reviews: false`.
- **Fix:** in the `checks` job, extract the checkers from the base before running them, as the merge gate does. For
  example, `git archive "origin/$BASE_REF" billing/scripts scripts/changelog-check.sh scripts/changelog-assemble.sh`
  into a directory under `billing/`, so the app root stays the PR tree. Run that copy. Fetch the base with
  `fetch-depth` or `git fetch origin "$BASE_REF"`. Add `.github/CODEOWNERS` for `.github/`, `billing/scripts/`,
  `scripts/` and `deploy/`, ready for when a second identity can be required as reviewer. `ci.yml` itself still comes
  from the PR. That is inherent to `pull_request`, but such a change is a visible workflow diff, not a quiet edit
  inside an 800-line module.

### M2. The security-exception waiver is bound to a label, but its contents come from an editable PR body

- **Where:** `.github/workflows/ci.yml:202-207` and `scripts/merge-gate.sh:237-240` read the `Security-Exception:`
  lines from the PR body on every run once the `security-exception` label is present. `parseSecurityExceptions`
  accepts any `name@version`. The probe showed `isExcepted(…, left-pad@9.9.9)` is `true` for a body line naming it.
  Branch protection on `develop` has `dismiss_stale_reviews: false` and `require_last_push_approval: false`.
- **Impact:** the approval (label plus CTO review) covers the list that existed when it was given. The list can grow
  afterwards.
- **Failure scenario:**
  1. An outside contributor opens a genuine security bump from a fork.
  2. A maintainer adds the label and approves.
  3. The contributor edits the PR body to add `Security-Exception: some-pkg@2.0.1`.
  4. They push a lockfile that pulls a version published an hour ago.
  5. The `synchronize` run waives it. The approval is not dismissed, and the PR shows green and approved.

  `dependency-policy.md` ("This is a process control") already notes that the single shared account can set label,
  body and approval. This scenario needs no access to that account.
- **Fix:**
  - Enable `dismiss_stale_reviews` and `require_last_push_approval` on `develop` (and on `main` once it is protected).
  - Read the waiver list from a source the PR author cannot edit, for example a file on the base branch (an exceptions
    table in `docs/handoff/requirements.md` already exists) or a comment whose `author_association` is `OWNER`.
  - Remove the label automatically on `edited` and `synchronize` events, so every change needs a fresh approval.

### M3. No vulnerability signal reaches the project for a dependency that is already locked

- **Where:** `gh api repos/bhimrazy/mohar/vulnerability-alerts` returns 404 (Dependabot alerts off).
  `automated-security-fixes` is `{"enabled":false}`, and `security_and_analysis.dependabot_security_updates` is
  `disabled`. `ci.yml:110-117` runs `composer audit` and `npm audit` only when a manifest, lockfile, `.npmrc` or
  workflow file changes. No scheduled workflow exists.
- **Impact:** a CVE published against a locked package (`laravel/framework`, `symfony/*`, the base images) gives no
  alert, opens no PR and turns no CI run red. It surfaces only when someone happens to edit a lockfile, or runs
  `composer deps:check` before a release (operating model, owner decision 2026-10-21). The docs describe a control
  that is off: `.github/dependabot.yml:3` and `billing/docs/devops/dependency-policy.md` ("Dependabot **security**
  updates bypass its cooldown, so they arrive as PRs") will never fire.
- **Fix:** enable Dependabot alerts and security updates. They are free on a public repository, and the
  `security-exception` flow already handles their PRs. Alternatively, add a weekly `schedule:` job on `develop` with
  `permissions: contents: read` that runs the two audits with `--locked` / `--package-lock-only` and fails visibly.
  Then correct the two documents to match whichever is chosen.

## Low

- **L1. Secret scanning and push protection are off on a public repository.** `security_and_analysis`:
  `secret_scanning`, `secret_scanning_push_protection` and `validity_checks` are all `disabled`. A pickaxe search of
  all commits for private-key headers and GitHub, Stripe, AWS, Slack and Google token prefixes matched only test
  fixtures under `billing/tests/` and `billing/tools/simulators/tests/`. Several agents commit as one account, so a
  pasted key has no backstop. Fix: enable both (free for public repositories).
- **L2. Privileged image jobs are guarded by an `if:`, not by an environment, and the built ref is not checked.**
  - **Where:** `.github/workflows/build-image.yml:184` (`packages: write`) and `:220` (`contents: write`) run only when
    `github.ref` is the default branch. `main` is the default branch and has no branch protection (`404 Branch not
    protected`), so force-pushes are allowed. A dispatch from any other branch uses that branch's copy of the file,
    where the `if:` can simply be deleted.
  - **The built ref:** the build checks out `inputs.ref` (`:52`) with no check that it is merged code. A dispatch from
    `main` with `ref: refs/pull/<n>/head` would push an unmerged fork commit to `ghcr.io/bhimrazy/mohar` under the
    project's name.
  - **Reachable today:** no. `main` holds no workflow files (`contents/.github/workflows?ref=main` returns 404), so
    neither job can run yet.
  - **Fix before the first release:** protect `main`. Move `push` and `release` into an environment (for example
    `release`) whose deployment-branch policy allows only `main`, with a required reviewer. When `push_to_ghcr` or
    `release_tag` is set, fail unless `git merge-base --is-ancestor <built commit> origin/main` (or a tag on it).
- **L3. Access-log redaction misses the set-password token.**
  - **Where:** `deploy/caddy/Caddyfile:25,50,52` redact `reset-password`, `staff-invitation`, `account-invitation` and
    `email/verify` paths. `GET /settings/password/set/{token}` (`billing/routes/settings.php:34-35`) carries a broker
    token in the path, and that token reaches Caddy's JSON access log in clear.
  - **Impact:** limited. `SetPasswordController::usable()` accepts the token only for the signed-in account it was
    made for.
  - **Fix:** add `settings/password/set` to the three regexes. Add a `SMOKESECRET…` request for it in
    `deploy/bin/smoke:160`, so the regression is pinned like the others.
- **L4. PostgreSQL and RustFS lack the container hardening the other services have, and the app holds RustFS root
  credentials.**
  - **Hardening:** `deploy/compose.prod.yaml:311-359` has no `security_opt: no-new-privileges`, no `cap_drop` and no
    `read_only` for `postgres` and `rustfs`. Every PHP service and Caddy has all three.
  - **Credentials:** `deploy/.env.production.example:53-55` gives the app the RustFS root keys. An app-level compromise
    therefore controls the whole object store, including KYC images, not just the app's bucket.
  - **Fix:** add `no-new-privileges` to both services. Add `cap_drop: ALL` with the few capabilities the official
    postgres entrypoint needs (`CHOWN`, `SETUID`, `SETGID`, `FOWNER`, `DAC_OVERRIDE`); this list is not verified by a
    run. Have `objects:ensure-bucket` create a bucket-scoped key for the app.
- **L5. Gaps in pin coverage and update coverage.**
  - **BuildKit pin unchecked:** `check-dependency-age.mjs` collects `image:` lines, but not BuildKit's
    `driver-opts: image=…` (`.github/workflows/build-image.yml:78`). The probe of `composeImages()` on that file
    returns only the binfmt image, so an unpinned or too-new BuildKit would pass CI.
  - **Compose images outside Dependabot:** `.github/dependabot.yml` watches docker-compose images only in `/billing`.
    The `caddy` and `rustfs` pins in `deploy/compose.prod.yaml` and `grafana/k6` in `loadtest/` are never proposed for
    update, so a Caddy security release waits for someone to notice.
  - **Fix:** collect `image=` values in `driver-opts`, and add docker-compose entries for `/deploy` and `/loadtest`.
- **L6. Docs for remote PostgreSQL leave TLS unauthenticated.** `billing/config/database.php:99` defaults
  `DB_SSLMODE` to `prefer`. The "Bare metal or managed services" option in `billing/docs/devops/install.md` (§1, §3)
  never mentions it. An operator following it against a managed PostgreSQL over a network gets encryption without
  server authentication, which an on-path attacker can intercept. Fix: document `DB_SSLMODE=verify-full` and
  `DB_SSLROOTCERT` for any database not on the same host.

## Informational

- **Changelog check, fork branch named `main`:** `scripts/changelog-check.sh:40` skips the whole check when
  `HEAD_REF=main`, so a fork PR from a branch named `main` skips the changelog policy. There is no security impact.
  Compare the head repository as well (`github.event.pull_request.head.repo.full_name`).
- **Repository Actions settings:** `allowed_actions: all` and `sha_pinning_required: false`. Every action is pinned
  today and CI enforces it. Turning on GitHub's own SHA-pinning requirement adds a second layer for free.
- **`new-worktree.sh` slug:** `scripts/new-worktree.sh:6` uses the slug without validating it. Git's ref-name rules
  reject `..` at `worktree add -b feature/<slug>`, so a path traversal fails today. Copying the
  `^[A-Za-z0-9][A-Za-z0-9._-]*$` check from `finish-workstream.sh:28` would make that explicit.
- **`storage-cleanup.sh` safety checks:** `scripts/storage-cleanup.sh:29-38` removes a merged-PR worktree without the
  `.claude-in-use` and recent-activity checks that `sweep-stale.sh` has. This is an operational risk (an agent's tree
  disappears mid-task), not a security one.

## Verified secure

- **Workflows:**
  - No `pull_request_target` and no `workflow_run`.
  - Top-level `permissions: contents: read` in all four workflows. Job-level write scopes appear only in
    `build-image.yml` `push` / `release`. The `ci` aggregator has `permissions: {}`.
  - `persist-credentials: false` on every checkout.
  - An awk scan of every `run:` block in `.github/` found no `${{ }}` expression. Inputs, `head_ref`, `base_ref` and
    the PR body reach shell only through `env`, and are quoted there.
- **Fork PRs:**
  - The repository has 0 Actions secrets and 0 variables.
  - Fork PRs get a read-only token.
  - `fork-pr-contributor-approval` is `first_time_contributors`.
  - The label-triggered jobs (`ci`, `e2e`, `deploy-smoke`, `load-test`) run only for their own label name, and adding
    a label needs triage rights. `labeled` events for other labels get their own concurrency group, so they never
    cancel a real run.
- **Caches:** PR runs write only to their own ref scope. The tool cache and the Composer cache restore into
  directories that each tool validates. The `gha` BuildKit cache is used only by the dispatch-only image build, and its
  scope key is the built ref.
- **Pinning:**
  - Every `uses:` is a 40-character SHA with a tag comment.
  - Every image in workflows, compose files and every Dockerfile `FROM`, `COPY --from` and `# syntax=` line carries a
    `@sha256:` digest.
  - The checker verifies, offline and for every item, that each action SHA matches its tag and each digest is
    published under its tag. It also binds npm `resolved` + `integrity` and Composer dist/source references to the
    registry's values.
  - Exceptions waive only the cooldown, for an exact `name@version`. For images that is name, tag and digest.
- **The #530 exceptions:**
  - The `symfony/mime@8.1.10` waiver is version-exact, and the lockfile diff changes only that package.
  - The `node:24.22.1-trixie-slim@sha256:89c5…` waiver is digest-exact.
  - The change to `dependency-policy.mjs` in that PR is a doc-comment version string only (`git show 426128ed`).
- **Image (`deploy/Dockerfile`):**
  - A multi-stage build. The runtime stage has no compiler, no Composer and no Node.
  - It runs as `USER app` (uid 10001). Code is root-owned, and only `storage/`, `bootstrap/cache`, `/data` and
    `/config` are writable.
  - The file capability is removed from `frankenphp`, so it works under `cap_drop: ALL`.
  - `Dockerfile.dockerignore` allowlists the context. A build step fails if an `.env` or any storage file is in it.
  - Only a throwaway `APP_KEY` appears in a layer (the wayfinder stage). There are no build args or secrets.
  - `HEALTHCHECK` is present, and `APP_DEBUG=false` is set.
- **Compose (`deploy/compose.prod.yaml`):**
  - Only Caddy publishes ports.
  - `backend` is `internal: true`.
  - All PHP services are `read_only`, with tmpfs mounts, `cap_drop: ALL`, `no-new-privileges` and `init`.
  - Caddy is the same, plus `NET_BIND_SERVICE` only.
  - Required secrets fail fast with `:?`. The RustFS console is off.
  - `TRUSTED_PROXIES` is the fixed edge subnet only. There is no Docker socket mount.
  - Dev compose files bind everything to `127.0.0.1`.
- **Caddy:**
  - The admin API is on `127.0.0.1:2019` inside the Caddy container, and `admin off` in the app container.
  - Automatic HTTPS with HTTP-to-HTTPS redirect. `header -Server` in the app server.
  - `X-Forwarded-*` is rewritten by Caddy, and the app trusts only `-For`, `-Proto` and `-Host` from the edge subnet.
  - The access and error logs redact token paths, every query string, the redirect `Location` and the Referer.
    `deploy/bin/smoke:160-161` asserts it. Security headers come from `SecurityHeaders` middleware (CSP nonce, HSTS).
- **Deploy scripts:**
  - `deploy/bin/deploy` writes the state file with `%q`, and the upstream file from a fixed template with colour
    `blue|green` only.
  - Image pruning is limited to images this project recorded.
  - `deploy/bin/smoke` refuses any project name other than `mohar-smoke*` before its `down -v`.
- **Developer scripts:**
  - `pg.sh` reduces slugs to `[A-Za-z0-9_]` before any SQL, checks `--drop-db` names against `^billing_test_…$`, and
    publishes only on `127.0.0.1`.
  - `merge-gate.sh` requires a numeric PR and an optional 40-character SHA, refuses fork PRs by default, binds the run
    to the head it read, and runs develop's policy scripts.
  - `finish-workstream.sh` and `sweep-stale.sh` validate input, act on git metadata only, and never remove a dirty,
    marked or unmerged tree.
  - Every `rm -rf` on a variable uses `${var:?}` or a fixed sub-path.
- **Operator docs:**
  - Every secret in `deploy/.env.production.example` is empty, with "long and random" guidance.
  - The env file gets `chmod 600`, and the file is git-ignored.
  - `SESSION_SECURE_COOKIE` and `SESSION_ENCRYPT` are on.
  - cPanel and bare-metal docs put the document root at `billing/public`.
  - `APP_KEY` is kept apart from backups, with a Docker-secrets option (`compose.secrets.example.yaml`).
  - `POSTGRES_HOST_AUTH_METHOD=trust` appears only on the throwaway CI service.

## Not verified

- How merges satisfy `develop`'s `required_approving_review_count: 1` with `enforce_admins: true`, given that all
  agents share one account (`review-rules.md`). The setting was read, not exercised.
- The runtime behaviour of the images: no build or pull was run. The digests were not re-resolved against Docker Hub;
  CI does that. The user and capability needs of `postgres` and `rustfs`, and whether RustFS runs as root.
- Whether the FrankenPHP app server's default log (`deploy/frankenphp/Caddyfile:11-14`, no filter) ever records a
  request URI. It has no access log, but Caddy's error entries can include the request.
- The `github-pages` environment and whatever deploys the documentation site (no workflow for it exists on `develop`).
- The CVE identifiers quoted in `deploy/Dockerfile` and in #530 were taken as written.
