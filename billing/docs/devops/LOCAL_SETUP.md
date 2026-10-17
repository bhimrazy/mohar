# Local setup & developer workflow

How to run the billing app, its infrastructure and every CI gate on a workstation or Codespace.
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
docker compose up -d --wait          # rustfs (S3, :9000, console :9001) + postgres (:5432 on 127.0.0.1)
php artisan objects:ensure-bucket    # creates the "billing" bucket used by the objects disk
php artisan migrate:fresh --seed     # admin@example.com / demo@example.com, password "password"
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
composer test:pgsql                            # same suite on the compose PostgreSQL (billing_test)
composer ci                                    # every CI gate below, in CI order
```

`composer test:pgsql` is equivalent to
`DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=billing_test DB_USERNAME=billing DB_PASSWORD=billing vendor/bin/pest --parallel`
— real environment variables win over the `<env>` defaults in `phpunit.xml` and over `.env`.
Run `php artisan config:clear` first if you ever cached config.

| CI job         | Local equivalent                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `lint`         | `vendor/bin/pint --parallel --test` · `php artisan wayfinder:generate --with-form` · `npx vp check` · `npx tsc --noEmit` |
| `static`       | `vendor/bin/phpstan analyse --memory-limit=1G`                                                                           |
| `tests-sqlite` | `vendor/bin/pest --parallel`                                                                                             |
| `tests-pgsql`  | `php artisan migrate:fresh --seed` on PostgreSQL, then `composer test:pgsql`                                             |
| `build`        | `npm run build` (runs Wayfinder via the Vite plugin, so PHP must be available)                                           |

Validate workflow changes with [actionlint](https://github.com/rhysd/actionlint) v1.7.12 (download the
release binary, check it against `actionlint_1.7.12_checksums.txt`, run it from the repository root).

## Queue worker, scheduler and automation

- `composer dev` already runs a queue worker. Standalone: `php artisan queue:work --tries=3`
  (`QUEUE_CONNECTION=database`; tests use `sync`).
- Scheduler: `php artisan schedule:work` (foreground, every minute) or cron
  `* * * * * php artisan schedule:run`. `php artisan schedule:list` shows registered entries — the only
  daily entry is `automation:run` at 03:00.
- Automation pipeline: `php artisan automation:run [--date=2026-10-17] [--only=<task-key>] [--force]`.
  Tasks are idempotent per run date (recorded in `automation_runs`); `--force` re-runs succeeded tasks.

## Screenshots

`docs/PROGRESS.md` references `node scripts/screenshots.mjs` (headless Playwright, output in
`docs/screenshots/`). The script is not committed yet; until it is, capture the design-system showcase at
`/dev/components` and module pages with Playwright's Chromium (`~/.cache/ms-playwright`) against
`composer dev` (`APP_URL=http://localhost:8000`).

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
