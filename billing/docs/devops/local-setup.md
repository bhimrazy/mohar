# Local setup & developer workflow

How to run the billing app, its infrastructure and every CI gate on a workstation or Codespace.
For a production server, see [install.md](install.md) (`php artisan app:install`).
CI itself is defined once, at the repository root: `.github/workflows/ci.yml` (all jobs run in `billing/`).

## Prerequisites

| Tool           | Version                                                                           | Notes                                                                                        |
| -------------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| PHP            | 8.4 (local build: 8.4.15)                                                         | extensions: `pdo_sqlite`, `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `dom`, `zlib` |
| Composer       | 2.10.x                                                                            |                                                                                              |
| Node / npm     | `billing/.nvmrc` (24.22.1) / npm 11                                               | `nvm use` inside `billing/`; lockfile is `package-lock.json` → `npm ci`                      |
| Docker Compose | v2.23+ (uses inline `configs.content`)                                            | RustFS + PostgreSQL                                                                          |
| Playwright     | 1.64.1, global (`npm i -g playwright@1.64.1`, then `playwright install chromium`) | screenshots and page walks (`scripts/screenshot.mjs`); not in the lockfile                   |

Always prefix PHP commands with `XDEBUG_MODE=off` unless you are debugging — Xdebug makes the suite and
Larastan several times slower. The composer `ci` / `test:pgsql` scripts set it for you.

## First run

```bash
cd billing
composer install && npm ci
cp -n .env.example .env && php artisan key:generate
touch database/database.sqlite
docker compose up -d --wait          # rustfs (S3 :9000, console :9001) + postgres (:5432), all on 127.0.0.1
php artisan objects:ensure-bucket    # creates the "billing" bucket used by the objects disk
php artisan migrate:fresh --seed     # reference data + demo: admin@example.com / demo@example.com, password "password"
php artisan wayfinder:generate --with-form
composer dev                         # app server, queue, logs and Vite
```

`OBJECTS_DRIVER=local` in `.env` keeps the app usable without Docker (files go to `storage/app`).

## Infrastructure (`billing/compose.yaml`)

| Service    | Image (pinned)                                       | Data volume     | Health        |
| ---------- | ---------------------------------------------------- | --------------- | ------------- |
| `rustfs`   | `rustfs/rustfs:1.0.0`                                | `rustfs-data`   | `GET /health` |
| `postgres` | `postgres:18.6-alpine@sha256:77f58511…` (2026-09-21) | `postgres-data` | `pg_isready`  |

- The compose project is called `billing` (the directory name), so every worktree shares the same two
  containers and volumes — start them once from any worktree.
- PostgreSQL reads `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_PORT` from `.env` (defaults
  `billing` / `billing` / `billing` / `5432`). These only apply when the volume is first created; after
  changing them run `docker compose down postgres && docker volume rm billing_postgres-data`.
- An init script creates a second database, `billing_test`, for the test suite. Parallel runs create
  `billing_test_test_<n>` automatically.
- PostgreSQL 18 images store data in `/var/lib/postgresql/18/docker`; the volume is mounted at
  `/var/lib/postgresql` (mounting `/var/lib/postgresql/data` as in older guides fails on 18+).
- Shell: `docker compose exec postgres psql -U billing -d billing`.

## Switching the database

SQLite stays the default for development and the fast test run. To run the app on PostgreSQL, replace
`DB_CONNECTION=sqlite` in `.env` with the commented block from `.env.example`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=billing
DB_USERNAME=billing
DB_PASSWORD=billing
```

then `php artisan config:clear && php artisan migrate:fresh --seed`. Switch back by restoring
`DB_CONNECTION=sqlite` and commenting the other `DB_*` lines (with SQLite, `DB_DATABASE` is a file path).

## Tests and gates

```bash
XDEBUG_MODE=off vendor/bin/pest --parallel     # SQLite in-memory (phpunit.xml)
composer test:pgsql                            # Feature + Extensions suites on PostgreSQL (billing_test)
composer ci                                    # every CI gate below, in CI order (incl. test:pgsql)
```

`composer test:pgsql` clears cached config, then runs `vendor/bin/pest --parallel --exclude-testsuite=Unit,Arch`
(the database-free suites run only on SQLite; one worker per core, or `PEST_PROCESSES=<n>`; extra arguments after
`--` go to Pest, and an explicit path runs whatever it names; ParaTest accepts one path, so use a directory or `--filter` for more) with `DB_CONNECTION=pgsql` and `DB_HOST` / `DB_PORT` / `DB_DATABASE` /
`DB_USERNAME` / `DB_PASSWORD` taken from your shell, defaulting to the compose values
(`127.0.0.1` / `5432` / `billing_test` / `billing` / `billing`). `.env` is not consulted for these: real
environment variables win over `.env` and over the `<env>` defaults in `phpunit.xml`. The CI "Pest (PostgreSQL)"
step runs the same script, with the CI-only settings described in [ci.md](ci.md#postgresql-step).

Each worker migrates its own `<name>_test_<n>` copy once and runs every test in a transaction, so nothing migrates
or seeds the base database first; it only has to exist (`compose.yaml` creates `billing_test`, `scripts/pg.sh`
creates one per worktree). Two worktrees can run it at the same time against the shared server with different
databases:

```bash
DB_DATABASE=billing_test_orders composer test:pgsql
```

CI runs the same gates in parallel jobs — see [ci.md](ci.md) for the jobs, triggers and caching, and
[dependency-policy.md](dependency-policy.md) for pinning, the 7-day cooldown and `composer deps:check`.
`composer ci` runs, in order: `deps:check` (lockfiles in sync, `composer audit`, `npm audit`, dependency
cooldown and binding checks — needs network), Pint, Wayfinder, `vp check`, tsc, Vitest (`npm test`), Larastan,
Pest on SQLite, `test:pgsql`, build.

Validate workflow changes with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 (download the
release binary, check it against `actionlint_1.7.12_checksums.txt`, run it from the repository root).

## Queue worker, scheduler and automation

- `composer dev` already runs a queue worker on `provisioning,default` (registered by the Services
  module). Standalone: `php artisan queue:work --queue=provisioning,default --tries=3`
  (`QUEUE_CONNECTION=database`; tests use `sync`).
- `composer dev` also runs a second worker for the Notifications module's queues (`notifications` for
  email sends, `webhooks` for outbound webhook deliveries, `document-emails` for composing the invoice emails that
  carry the PDF, read last). Standalone:
  `php artisan queue:work --queue=notifications,webhooks,document-emails`.
- `composer dev` also runs a worker on the `automation` connection (registered by the Platform module) for
  **Run now** on Admin → Automation. Standalone: `php artisan queue:work automation --queue=automation --tries=1`.
  Those jobs run on their own connection because a run can outlast the pools' `retry_after` (`install.md` §6).
- Production: run at least one worker that consumes `provisioning` (driver calls: create, suspend,
  terminate, change package/password), e.g. `php artisan queue:work --queue=provisioning,default`, and one
  that consumes `notifications,webhooks,document-emails` (email and webhook jobs set their own tries and backoff; webhook
  retries are re-dispatched every minute by `notifications:retry-webhooks` from the scheduler). Without it,
  emails stay `queued` in the email log and webhook deliveries stay `pending`.
  Module command jobs try once and time out after `services.module.job_timeout_seconds` (60 s), or the
  driver's declared command timeout up to 180 s; keep the connection's `retry_after` (240 s) above that cap
  plus the 20 s lock slack.
- Scheduler: `php artisan schedule:work` (foreground, every minute) or cron
  `* * * * * php artisan schedule:run`. `php artisan schedule:list` shows registered entries — the only
  daily entry is `automation:run` at 03:00.
- Automation pipeline: `php artisan automation:run [--date=2026-10-17] [--only=<task-key>] [--force]`.
  Tasks are idempotent per run date (recorded in `automation_runs`); `--force` re-runs succeeded tasks.

## Screenshots

Screenshots for `docs/progress.md` live in `docs/screenshots/` and are captured with headless Playwright against a
running instance (`composer dev`, `APP_URL=http://localhost:8000`); the design-system showcase is at `/dev/components`.
Playwright is installed globally at a pinned version (table above), outside the lockfile. From the repository root:

```bash
node scripts/screenshot.mjs http://localhost:8000/admin out.png --login admin@example.com:password
node scripts/screenshot.mjs http://localhost:8000/admin out-dark.png --login admin@example.com:password --dark --width 390 --height 844
```

Then `npm run images:optimize -- <paths>` (CI fails on images over 500 KB).

## Mail

`docker compose up -d mailpit` starts Mailpit, which catches every outgoing mail: set `MAIL_MAILER=smtp`,
`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` in `.env` and read the mail at http://localhost:8025 (API: `/api/v1/messages`). The host ports can be moved with
`MAILPIT_SMTP_PORT` and `MAILPIT_UI_PORT`.
Invitations, password resets and invoice mail can then be tested end to end without a real mail provider.

## Reverse proxies and `TrustProxies`

Locally (`artisan serve`, Vite) there is no proxy, and no proxies are trusted, so `X-Forwarded-*` headers are ignored.
Keep it that way unless a proxy really sits in front of the app: trusting `X-Forwarded-For` from anyone would let
clients spoof their IP.

Behind a load balancer or reverse proxy (production, or a local Traefik/nginx), set `TRUSTED_PROXIES` to the
proxy's address or range, comma-separated (`config/trustedproxy.php`). Never use `*` on a network others can
reach:

```dotenv
# The compose network of the edge proxy, or the load balancer's private range
TRUSTED_PROXIES=172.18.0.0/16
```

Only requests that arrive from those addresses may set the client IP, scheme and host (`X-Forwarded-For`, `-Proto`,
`-Host`); the port follows from the scheme. `X-Forwarded-Port`, `X-Forwarded-Prefix`, RFC 7239 `Forwarded` and AWS
ELB headers are never trusted: Caddy passes a client's own `X-Forwarded-Port` through unchanged (`bootstrap/app.php`). The value
is read per request, so it also works with a cached configuration. Unset means no proxy at all, including on
`*.on-forge.com`, `*.on-vapor.com` and Laravel Cloud, where the framework would otherwise trust every proxy: set
`TRUSTED_PROXIES` there too.

Two more things the proxy setup relies on:

- **TrustHosts stays on outside `local`.** It rejects any `Host` or trusted `X-Forwarded-Host` that is not
  `APP_URL`'s host (a 400), and generated links always use `APP_URL` (#59). Never run production with
  `APP_ENV=local`.
- **The proxy forwards the original `Host`.** Caddy and Traefik do by default; nginx needs
  `proxy_set_header Host $host;`. A proxy that rewrites `Host` to the upstream name (`app:8080`) gets a 400 on
  every request, even with a correct `X-Forwarded-Host`.

`tests/Feature/Security/TrustedProxiesTest.php` covers these cases.

Without it, `request()->ip()` is the proxy's address and `isSecure()` is false. So:

- the login lockout (per email + IP) would lock an email for every visitor once a few bad passwords came through,
- the activity log would record the proxy IP,
- HSTS would never be sent.

Check after deploying: sign in, then confirm that the activity log shows your real IP and the response has
`Strict-Transport-Security`.

## Containerised development (optional)

`deploy/compose.dev.yaml` runs the app from the image's `dev` target with `billing/` bind-mounted, plus Vite
for HMR, as your own user: `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f deploy/compose.dev.yaml up
--build app vite`, then http://localhost:8080 (`APP_PORT` changes the port). It reads `billing/.env`; the
PostgreSQL and RustFS services of `billing/compose.yaml` are included and start with a plain `up`. The image
itself is described in [container-image.md](container-image.md).

## Worktree workflow

Every workstream works in its own git worktree, branched from `develop`:

```bash
scripts/new-worktree.sh <module-slug>    # from the repo root
# -> .claude/worktrees/<slug>/billing on feature/<slug>, vendor + node_modules copied, .env copied,
#    SQLite migrated and seeded, Wayfinder generated, pre-push gate installed
```

Run all commands inside `.claude/worktrees/<slug>/billing`. Infrastructure (RustFS, PostgreSQL) is shared across
worktrees; the SQLite file is per worktree. Rebase on `develop` and run `composer ci` before review.

### Pre-push gate

`new-worktree.sh` runs `scripts/install-hooks.sh`, which sets `core.hooksPath` for that worktree only
(`git config --worktree`, with `extensions.worktreeConfig` on in the repository config; the global config is never
touched). In an older worktree, run `scripts/install-hooks.sh` once. `git push` then runs `scripts/pre-push.sh` on what
the branch changes against `origin/develop`, the fast checks that most CI failures came from:

| Check       | Runs on                                                           |
| ----------- | ----------------------------------------------------------------- |
| `pint`      | changed PHP (`pint --test`)                                       |
| `vp`        | changed frontend and Markdown files under `billing/` (`vp check`) |
| `changelog` | the whole diff (`scripts/changelog-check.sh`)                     |
| `phpstan`   | changed PHP in the analysed paths (`phpstan.neon`)                |
| `tests`     | `scripts/test.sh --changed`                                       |

A docs-only push runs only `vp` and `changelog`. When `test.sh` would run the whole suite (shared code such as Core or
`app/Support` changed) the gate leaves the tests to CI; `PRE_PUSH_FULL=1 git push` runs them anyway. Pint, phpstan,
`vp` and the tests go through `scripts/heavy.sh`. Every failing check is listed, then the push is refused.

- Preview the plan: `scripts/pre-push.sh --dry-run`.
- Skip single checks: `PRE_PUSH_SKIP=tests,phpstan git push`. `PRE_PUSH_BASE` replaces `origin/develop`.
- Emergency bypass: `git push --no-verify`. CI still runs every check, so a bypassed push only costs a failed run.
- The test step stops after `PRE_PUSH_TEST_TIMEOUT` seconds (default 300, a wait for a `heavy.sh` slot counts). It then
  prints a note and lets the push through with the tests left to CI; the other checks always block.
- The installer sets `extensions.worktreeConfig=true` in the repository config (`.git/config`, shared by all worktrees).
  To undo: `git config --worktree --unset core.hooksPath` in the worktree, and, once no worktree needs it,
  `git config --unset extensions.worktreeConfig`.
- Without `origin/develop` locally the gate prints a note and lets the push through (`git fetch origin develop`).

After the PR merges, `scripts/finish-workstream.sh <slug>` removes the worktree, the merged local branch and the
slug's PostgreSQL databases (`--dry-run` to preview). `scripts/sweep-stale.sh` lists leftovers (dry run; `--apply` acts).

## Building `pdo_pgsql` for the local PHP 8.4.15

The Codespace PHP is a custom build in `/usr/local/php/8.4.15` (scan dir `ini/conf.d`) without the
PostgreSQL drivers. They were built as shared extensions from the matching source tarball:

```bash
sudo apt-get install -y libpq-dev               # libpq 16 headers + pg_config
mkdir -p /tmp/php-src && cd /tmp/php-src
curl -fsSLO https://www.php.net/distributions/php-8.4.15.tar.xz
# sha256 from https://www.php.net/releases/index.php?json&version=8.4.15
echo "a060684f614b8344f9b34c334b6ba8db1177555997edb5b1aceab0a4b807da7e  php-8.4.15.tar.xz" | sha256sum -c -
tar -xJf php-8.4.15.tar.xz
PHPBIN=/usr/local/php/8.4.15/bin
for ext in pgsql pdo_pgsql; do
  (cd php-8.4.15/ext/$ext && $PHPBIN/phpize \
    && ./configure --with-php-config=$PHPBIN/php-config --with-${ext//_/-} \
    && make -j"$(nproc)" && make install)   # -> /usr/local/php/8.4.15/extensions/
done
printf 'extension=pgsql\nextension=pdo_pgsql\n' > /usr/local/php/8.4.15/ini/conf.d/20-pgsql.ini
php -m | grep pgsql                              # pdo_pgsql, pgsql
```

The extension directory is owned by the `codespace` user, so only the `apt-get` step needs `sudo`. On a
fresh Codespace image that ships a different PHP patch release, download that version's tarball instead —
an extension must be built against the exact PHP it is loaded into.
