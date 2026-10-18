# Local setup & developer workflow

How to run the billing app, its infrastructure and every CI gate on a workstation or Codespace.
For a production server, see [install.md](install.md) (`php artisan app:install`).
CI itself is defined once, at the repository root: `.github/workflows/ci.yml` (all jobs run in `billing/`).

## Prerequisites

| Tool           | Version                                        | Notes                                                                                        |
| -------------- | ---------------------------------------------- | -------------------------------------------------------------------------------------------- |
| PHP            | 8.4 (local build: 8.4.15)                      | extensions: `pdo_sqlite`, `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `dom`, `zlib` |
| Composer       | 2.10.x                                         |                                                                                              |
| Node / npm     | `billing/.nvmrc` (24.21.0) / npm 11            | `nvm use` inside `billing/`; lockfile is `package-lock.json` → `npm ci`                      |
| Docker Compose | v2.23+ (uses inline `configs.content`)         | RustFS + PostgreSQL                                                                          |
| Playwright     | Chromium in `~/.cache/ms-playwright` (screens) | only for screenshots                                                                         |

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
composer test:pgsql                            # migrate:fresh --seed + same suite on PostgreSQL (billing_test)
composer ci                                    # every CI gate below, in CI order (incl. test:pgsql)
```

`composer test:pgsql` clears cached config, then runs `php artisan migrate:fresh --seed --force` and
`vendor/bin/pest --parallel` with `DB_CONNECTION=pgsql` and `DB_HOST` / `DB_PORT` / `DB_DATABASE` /
`DB_USERNAME` / `DB_PASSWORD` taken from your shell, defaulting to the compose values
(`127.0.0.1` / `5432` / `billing_test` / `billing` / `billing`). `.env` is not consulted for these: real
environment variables win over `.env` and over the `<env>` defaults in `phpunit.xml`. The CI `tests-pgsql`
job runs the same script.

Two worktrees can run it at the same time against the shared server with different databases — a missing
database is created by `migrate --force`, and parallel workers add `<name>_test_<n>` copies:

```bash
DB_DATABASE=billing_test_orders composer test:pgsql
```

CI runs the same gates in one job — see [ci.md](ci.md) for the step list, triggers and caching, and
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
  email sends, `webhooks` for outbound webhook deliveries). Standalone:
  `php artisan queue:work --queue=notifications,webhooks`.
- Production: run at least one worker that consumes `provisioning` (driver calls: create, suspend,
  terminate, change package/password), e.g. `php artisan queue:work --queue=provisioning,default`, and one
  that consumes `notifications,webhooks` (email and webhook jobs set their own tries and backoff; webhook
  retries are re-dispatched every minute by `notifications:retry-webhooks` from the scheduler). Without it,
  emails stay `queued` in the email log and webhook deliveries stay `pending`.
  Module command jobs try once and time out after `services.module.job_timeout_seconds` (60 s); keep
  the connection's `retry_after` (90 s) above `services.module.lock_ttl_seconds` (80 s).
- Scheduler: `php artisan schedule:work` (foreground, every minute) or cron
  `* * * * * php artisan schedule:run`. `php artisan schedule:list` shows registered entries — the only
  daily entry is `automation:run` at 03:00.
- Automation pipeline: `php artisan automation:run [--date=2026-10-17] [--only=<task-key>] [--force]`.
  Tasks are idempotent per run date (recorded in `automation_runs`); `--force` re-runs succeeded tasks.

## Screenshots

Screenshots for `docs/progress.md` live in `docs/screenshots/` and are captured with headless Playwright
(Chromium in `~/.cache/ms-playwright`) against a running instance (`composer dev`,
`APP_URL=http://localhost:8000`); the design-system showcase is at `/dev/components`.

## Reverse proxies and `TrustProxies`

Locally (`artisan serve`, Vite) there is no proxy, and no proxies are trusted, so `X-Forwarded-*` headers are ignored.
Keep it that way unless a proxy really sits in front of the app: trusting `X-Forwarded-For` from anyone would let
clients spoof their IP.

Behind a load balancer or reverse proxy (production, or a local Traefik/nginx), configure trusted proxies in
`bootstrap/app.php`. Use the proxy's address range, never `*` on a public network:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(
        at: ['10.0.0.0/8'], // the load balancer's private range
        headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
    );
})
```

Without it, `request()->ip()` is the proxy's address and `isSecure()` is false. So:

- the login lockout (per email + IP) would lock an email for every visitor once a few bad passwords came through,
- the activity log would record the proxy IP,
- HSTS would never be sent.

Check after deploying: sign in, then confirm that the activity log shows your real IP and the response has
`Strict-Transport-Security`.

## Worktree workflow

Every workstream works in its own git worktree, branched from `develop`:

```bash
scripts/new-worktree.sh <module-slug>    # from the repo root
# -> .worktrees/<slug>/billing on feature/<slug>, vendor + node_modules copied, .env copied,
#    SQLite migrated and seeded, Wayfinder generated
```

Run all commands inside `.worktrees/<slug>/billing`. Infrastructure (RustFS, PostgreSQL) is shared across
worktrees; the SQLite file is per worktree. Rebase on `develop` and run `composer ci` before review.

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
