# Container image

One image runs every process of the app: web, queue workers, the scheduler and migrations. It is built from
`deploy/Dockerfile` with the repository root as the context (narrowed by `deploy/Dockerfile.dockerignore` to
`billing/` and the runtime files in `deploy/`). Why this design: [deployment-options.md](deployment-options.md).
The production compose stack, blue-green deploys and the build workflow come in later PRs (D2, D3).

```sh
docker build -f deploy/Dockerfile --target prod -t billing:local .   # from the repository root
```

## Stages

| Stage       | Base                                 | Purpose                                                                                 |
| ----------- | ------------------------------------ | --------------------------------------------------------------------------------------- |
| `php`       | `dunglas/frankenphp` (Debian trixie) | FrankenPHP 1.13 with `bcmath gd intl pcntl pdo_pgsql pgsql zip` (OPcache built in)      |
| `php-libs`  | `php`                                | collects the shared libraries FrankenPHP, PHP and the extensions link against (`ldd`)   |
| `runtime`   | `debian:trixie-<date>-slim`          | PHP without the compiler toolchain: binaries, extensions, `php.ini` and missing libs    |
| `vendor`    | `php` + Composer                     | `composer install --no-dev` from the lockfile, optimised autoloader, package discovery  |
| `wayfinder` | `vendor`                             | `wayfinder:generate --with-form` (the front-end build imports the generated TypeScript) |
| `assets`    | `node` (the `.nvmrc` version)        | `npm ci` and `npm run build`; only `public/build` leaves this stage                     |
| `dev`       | `php` + Composer + Node              | `deploy/compose.dev.yaml`; source bind-mounted                                          |
| `prod`      | `runtime`                            | the image you deploy (default target)                                                   |

The `runtime` stage keeps the image small: the official PHP images keep their build toolchain (about 370 MB),
which a running app never needs. Its Debian slim snapshot must match the one the FrankenPHP image was built
from (same glibc), so the two are bumped together.

Every `FROM` and `COPY --from=` image is `<name>:<tag>@sha256:<digest>` and at least 7 days old (ADR 0007).
`composer deps:check` and the CI policy step scan `deploy/Dockerfile` and `deploy/*.yaml` for this.

## Runtime contract

| Item              | Value                                                                                                                                           |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| User              | `app`, UID/GID 10001; code is owned by root and read-only to it                                                                                 |
| Port              | 8080, plain HTTP (`SERVER_NAME=:8080`); TLS belongs to the edge proxy. No capability is needed (the binary's `cap_net_bind_service` is removed) |
| Writable paths    | `/app/storage` (a volume), and on a read-only root filesystem tmpfs for `/tmp`, `/app/bootstrap/cache`, `/data` and `/config` (Caddy state)     |
| Defaults baked in | `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr` with the JSON formatter, `INERTIA_SSR_ENABLED=false`                              |
| Required from you | `APP_KEY`, `APP_URL`, `DB_*`, mail and object-storage settings (`install.md`), `TRUSTED_PROXIES` behind a proxy                                 |
| Entrypoint        | recreates the storage skeleton, runs `php artisan optimize` (config embeds the environment, so at start, not build), then runs the command      |
| Health            | `HEALTHCHECK` runs `app-healthcheck`: `GET /up` on port 8080 with `APP_URL`'s host, because TrustHosts rejects other hosts                      |
| Logs              | everything on stderr: Laravel as JSON lines, Caddy as JSON                                                                                      |

`APP_OPTIMIZE=0` skips the cache step for a one-off command. The same image runs every service:

| Service   | Command                                                                         |
| --------- | ------------------------------------------------------------------------------- |
| web       | default (`frankenphp run --config /etc/frankenphp/Caddyfile`), classic mode     |
| worker    | `php artisan queue:work --queue=<pool> --tries=3 --timeout=200 --max-time=3600` |
| scheduler | `php artisan schedule:work`                                                     |
| migrate   | `php artisan migrate --force && php artisan app:install --no-interaction`       |

PHP settings are `php.ini-production` plus `deploy/php/app.ini` (upload limits for ticket attachments, matched by
the Caddyfile's `request_body` limit) and `deploy/php/opcache.ini` (`validate_timestamps=0`: code never changes in
a running container).

## Measured (2026-10-19, codespace, linux/amd64)

| Measure                                  | Value                                                                                                                                                                            |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Image, unpacked root filesystem          | 420 MB (FrankenPHP 57, PHP CLI 21, extra libraries 97, app with `vendor/` 162, Debian slim and the rest)                                                                         |
| Image, `docker save` (compressed layers) | 162 MB                                                                                                                                                                           |
| Without the `runtime` stage              | 1,208 MB unpacked (the toolchain of the official PHP image)                                                                                                                      |
| Build, no cache                          | 428 s on the shared 4-vCPU codespace (`install-php-extensions` compiling the extensions is the largest step)                                                                     |
| Cold start                               | 1.2–2.1 s (three runs on the shared codespace) from `docker run` to the first `200` on `/up` (includes `php artisan optimize`)                                                   |
| Smoke test against PostgreSQL 18.6       | read-only root, all capabilities dropped, `no-new-privileges`: migrations and `app:install`, `/up` healthy, sign-in, a queued `RunAutomationJob` processed by a worker container |

## Not yet

- Only `linux/amd64` has been built; arm64 comes with the build workflow (D3).
- The Debian packages `install-php-extensions` pulls (ICU, libpq, libgd and friends) come from the trixie
  repositories at build time and are not pinned by version; rebuilding later may pick up Debian security updates.
- `aws/aws-sdk-php` is 76 MB unpacked (6 MB compressed) although only S3 is used; the SDK's
  `removeUnusedServices` Composer script could drop the rest.
- Worker mode (Octane) needs a review of the app's static state first (`deployment-options.md`).
