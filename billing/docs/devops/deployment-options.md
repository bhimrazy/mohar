# Deployment options

Advisor review of how to deploy the billing app, written 2026-10-19 against `develop` (Laravel 13.34, PHP 8.4,
Inertia 3 + React 19, PostgreSQL 18, RustFS, queue pools from ADR 0014). It compares runtimes, base images,
reverse proxies, production compose practice, hosting targets (including cPanel shared hosting) and the branch
and build-workflow strategy, then recommends a default stack and what the DevOps engineer builds first.
Sources are the official Laravel, PHP, Docker, FrankenPHP, Caddy, Traefik, cPanel, GitHub, Kamal, Coolify, Fly and
Dokku documentation as of this date. Figures that the documentation does not give are not invented here.

## Facts about the app that decide the design

- Web, queue workers and the scheduler run the same code, so one image with different commands is enough.
- Required extensions beyond Laravel's list: `pdo_pgsql`, `pgsql`, `intl`, `gd` (dompdf images), `bcmath`,
  `pcntl` (Laravel: job timeouts need PCNTL; `queue:work --timeout` is in `install.md`), `opcache`,
  `zip`. `redis` (phpredis) only if `QUEUE_CONNECTION`/`CACHE_STORE` move to Redis; the defaults are `database`,
  and `predis` is not in `composer.lock`.
- `/up` is the health route (`bootstrap/app.php`); `php artisan optimize` after every deploy; `.env` is read only
  by `optimize` once the configuration is cached (`install.md`).
- The front-end build needs PHP first: `wayfinder:generate` writes `resources/js/{actions,routes,wayfinder}`
  (gitignored), which `vp build` imports. A Node-only build stage cannot produce `public/build`.
- `config/inertia.php` has `ssr.enabled => true` but there is no `resources/js/ssr.tsx` and `build:ssr` is never
  run, so pages render client-side. The image must not start an SSR process; set `INERTIA_SSR_ENABLED=false`
  (or leave the bundle absent) rather than ship Node in the runtime image.
- `bootstrap/app.php` does not call `trustProxies()`. Behind any proxy `isSecure()` is false, HSTS is never
  sent and the login lockout sees the proxy IP (`local-setup.md`). A small Core change is a prerequisite:
  read a `TRUSTED_PROXIES` env value (the compose network CIDR) and pass it to `trustProxies()`.
- Static state exists in four places (`CachedCurrencyDirectory::$generation`, `EventKey::$instances`,
  `SecretTemplatePolicy::$sensitiveSamplePaths`, `FirstAdministrator::$passwordGenerator`). Harmless under
  php-fpm or FrankenPHP classic mode, which reset the process state per request; they would need review under
  worker mode (FrankenPHP's docs list class static properties and in-memory caches among the state that persists).
- dompdf bundles DejaVu, so PDFs need no system fonts. It does not shape complex scripts, so Devanagari text
  in invoices is a product question, not an image question.
- ADR 0007: every image is `<name>:<tag>@sha256:<digest>` and at least 7 days old. `check-dependency-age.mjs`
  only scans `image:` lines in compose and workflow files and only verifies Docker Hub tags. Dockerfile `FROM`
  lines and `ghcr.io` images are not covered yet; Dependabot's `docker-compose` entry only watches `/billing`.

## 1. Runtime

| Option                          | Containers | Image / memory                                           | Code changes                                                                   | Verdict                                                                                    |
| ------------------------------- | ---------- | -------------------------------------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| nginx + php-fpm                 | 2          | two images; php-fpm processes sized by `pm.max_children` | none                                                                           | Known quantity, but two images to pin, two configs, and `public/` must be copied into both |
| FrankenPHP classic mode         | 1          | one image (Caddy + PHP ZTS); per-request state resets    | none                                                                           | **Default.** One process serves PHP and static files; HTTP/2, HTTP/3, compression          |
| FrankenPHP worker mode (Octane) | 1          | same image; app stays in memory                          | `laravel/octane` (new dependency, CTO approval, cooldown) + static-state audit | Later, if latency matters. Octane 2.22.0 (2026-10-14) supports framework ^13.0             |
| Octane + Swoole / RoadRunner    | 1          | extra extension or binary                                | same as above                                                                  | No advantage over FrankenPHP for us; Swoole adds a PECL extension to pin                   |

Why classic mode now: the app is an admin and client billing system, not a high-RPS API; worker mode's gain is
boot time per request, and its cost is a class of bugs (stale container, request or config injected into
singletons, growing static arrays) that the Octane documentation describes and that a billing system must not
have. The same image switches to `php artisan octane:frankenphp` later without rebuilding the pipeline.

FrankenPHP constraints that matter: the PHP build is thread-safe, so only thread-safe extensions are allowed
(`imap`, `newrelic`, `pcov` are not; `imagick` conflicts with its threads; we use none). The Alpine and static
musl builds lack `GLOB_BRACE`. `SERVER_NAME=:8080` disables Caddy's automatic HTTPS so the edge proxy owns TLS.
Non-root needs no capability when the listen port is unprivileged.

## 2. Base image and multi-stage layout

**Debian (`trixie`) over Alpine.** FrankenPHP's own docs recommend the Debian variants. musl's `iconv` has no
`//TRANSLIT`, FrankenPHP on Alpine lacks `GLOB_BRACE`, and the official PHP image notes that software with deep
libc assumptions can misbehave on musl. The saving is tens of megabytes on an image that is mostly `vendor/` and
PHP; not worth a second libc to debug in a PDF-producing financial app.

Stages (one Dockerfile, targets `prod` and `dev`):

1. `composer`: `composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader` from the lockfile,
   then `php artisan package:discover`. The Composer binary comes from the pinned `composer` image by digest.
2. `wayfinder`: the runtime base plus the app and `vendor/`; runs `php artisan wayfinder:generate --with-form`
   with a throwaway `APP_KEY` and SQLite so the generated TypeScript exists.
3. `assets`: `node:24.22.1-trixie-slim` by digest (the `.nvmrc` version); `npm ci` from the lockfile, copies the
   Wayfinder output from stage 2, `npm run build`. Only `public/build` leaves this stage.
4. `prod`: `dunglas/frankenphp:<ver>-php8.4-trixie` by digest; extensions via `install-php-extensions` (the
   FrankenPHP image ships it; it removes build dependencies after installing). Copies the app, `vendor/` and
   `public/build`; `php.ini-production` plus an `opcache.ini` (`validate_timestamps=0`, sized
   `memory_consumption` and `max_accelerated_files`); `USER app` (fixed UID); `EXPOSE 8080`;
   `HEALTHCHECK` on `/up`; default command `frankenphp run --config /etc/frankenphp/Caddyfile`.
5. `dev`: from the runtime base with dev extensions, `php.ini-development`, Composer and Node present, the
   source bind-mounted; runs the same Caddyfile plus `vp dev` for HMR.

Build-time versus start-time caching: route, view and event caches are environment-independent and can be
baked. `config:cache` embeds `.env`, so the entrypoint runs `php artisan optimize` at start, which needs a
writable `bootstrap/cache` (tmpfs). Preloading (`opcache.preload`) is left out of v1: there is no official
Laravel preload file, it needs a restart to pick up changes, and classic mode already caches opcodes.

Read-only root filesystem: `read_only: true` with tmpfs for `/tmp`, `/app/bootstrap/cache`, `/data` and
`/config` (Caddy state; no certificates live here), and a named volume for `/app/storage`. Logs go to stderr
(`LOG_CHANNEL=stderr` with the JSON formatter, ADR 0023 §1.7), so `storage/logs` stays empty.

One image, four commands:

| Service             | Command                                                                                | Replicas                                                                                        |
| ------------------- | -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `app`               | `frankenphp run`                                                                       | 1+                                                                                              |
| `worker-*`          | `php artisan queue:work --queue=... --tries=3 --timeout=200 --max-time=3600`           | 1 per pool (ADR 0014: `payments,provisioning,registrar` / `notifications,webhooks` / `default`) |
| `worker-automation` | `php artisan queue:work automation --queue=automation --tries=1 --timeout=3600`        | exactly 1 (Run now; its own connection, `retry_after` 3,900 s, `install.md` §6)                 |
| `scheduler`         | `php artisan schedule:work`                                                            | exactly 1                                                                                       |
| `migrate`           | `php artisan migrate --isolated=1 --force && php artisan app:install --no-interaction` | one-shot                                                                                        |

`--max-time` restarts workers hourly; `stop_grace_period` must exceed the longest job timeout (Laravel's
Supervisor advice for `stopwaitsecs`), so 210 s for the provisioning pool and over 3,600 s for `worker-automation`.
`init: true` so signals reach PHP.

## 3. Reverse proxy and TLS

| Edge               | Certificates                                           | Config                    | Docker socket                           | Fit                                                     |
| ------------------ | ------------------------------------------------------ | ------------------------- | --------------------------------------- | ------------------------------------------------------- |
| Caddy              | automatic; Let's Encrypt with ZeroSSL fallback         | 2-line site block per app | not needed (static file)                | **Default.** Smallest moving part; `/data` must persist |
| Traefik v3         | ACME (TLS-ALPN, HTTP-01, DNS-01); `acme.json` persists | labels on each container  | required; docs recommend a socket proxy | When many compose projects come and go on one host      |
| nginx              | none built in (certbot sidecar)                        | file per site             | no                                      | Only where nginx is already the operator's standard     |
| caddy-docker-proxy | as Caddy                                               | labels                    | required                                | Label workflow without Traefik; third-party plugin      |

The app already sends CSP, HSTS and the other headers (`SecurityHeaders`), so the proxy adds none. It must
forward `X-Forwarded-Proto` and `X-Forwarded-For`, and the app must trust the proxy's address (the prerequisite
above). HSTS is only emitted when `isSecure()` is true, which is the check after go-live.

Decision for the owner: Caddy keeps one configuration language (the app server is Caddy too) and no Docker API
exposure. Traefik is the better fit if the plan is a host running many unrelated compose projects with routing
by label, and it is what Coolify installs by default.

## 4. Production compose

- **Services:** `caddy` (ports 80/443, volume `caddy-data`), `app`, three `worker-*` pools, `worker-automation`, `scheduler`, `migrate`,
  `postgres` (existing pinned `postgres:18.6-alpine`), `rustfs` (existing pin, console off, no published
  ports). Everything but `caddy` has no `ports:`; services talk on an internal network.
- **Restart:** `unless-stopped` for long-running services; `migrate` has `restart: "no"` and `app` and workers
  use `depends_on: migrate: condition: service_completed_successfully`, `postgres`/`rustfs`
  `condition: service_healthy`.
- **Install:** `docker compose run --rm app php artisan app:install` once, interactively, for the first
  administrator (the command prints a one-time password otherwise and must not run in a logged job). Upgrades
  re-run `app:install --no-interaction` inside `migrate`, which is idempotent (`install.md`).
- **Zero-downtime on one host:** Compose replaces a container in place, so a deploy has a gap of a few seconds
  while the new `app` passes its healthcheck. Accept that in v1 (`docker compose up -d --wait`, maintenance mode
  only when a migration needs it). When it matters, the `docker rollout` plugin scales a service to two, waits
  for health, then removes the old container; it requires no `ports:` and no `container_name` on the service,
  which the layout above already satisfies.
- **Backups:** host cron runs `docker compose exec -T postgres pg_dump --format=custom` nightly before the
  03:00 automation run and ships dumps off-host (`install.md` has the retention and restore test); the RustFS
  bucket is synced with `rclone` or replicated to a second bucket; `APP_KEY` lives in a secrets store, never next
  to the dumps. No extra backup image, so nothing new to pin.
- **Logs:** every container to stdout/stderr; json-file driver with `max-size`/`max-file` set in the host's
  `daemon.json` (or per service under `logging.options`) so disks do not fill. Request ids per ADR 0014.
- **Secrets:** `env_file: .env` (mode 600) for the app in v1, because Laravel reads env, not files. Compose
  `secrets:` mount files at `/run/secrets/<name>` and Docker advises them over env for passwords; use them where
  the consumer supports files (`POSTGRES_PASSWORD_FILE`), and add a `*_FILE` shim in the entrypoint when the app
  moves to them. Never pass secrets as build args.
- **Limits:** `deploy.resources.limits` (`memory`, `cpus`) on every service; Compose v2 applies them without
  Swarm. Starting point for a 2 vCPU / 4 GB VPS: app 512 MB, workers 256 MB each, scheduler 128 MB, postgres
  1 GB, rustfs 512 MB, caddy 128 MB.
- **Updates:** bump the image digest in `deploy/compose.yaml` (Dependabot `docker` ecosystem on `/deploy`, same
  14-day cooldown), `docker compose pull && docker compose up -d --wait`. No auto-updaters such as Watchtower:
  they pull unpinned tags, which ADR 0007 forbids.

## 5. Where it can run

| Target                                    | Works?                       | Notes                                                                                                                                                                          |
| ----------------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| One VPS with Docker                       | yes, **recommended**         | `deploy/compose.yaml` as above; 2 vCPU / 4 GB minimum, measured ([sizing.md](sizing.md)); the owner's hosts know VPSs                                                          |
| VPS without Docker (systemd, Forge, Ploi) | yes                          | `install.md` already covers it; Forge provisions nginx, PHP, PostgreSQL, Redis, daemons, scheduler and certificates, no containers                                             |
| Laravel Cloud                             | yes                          | Octane/FrankenPHP toggle, managed PostgreSQL and object storage, scale-to-zero (queues need an always-awake worker cluster or managed queues); per-usage cost                  |
| Fly.io                                    | yes                          | `fly launch` generates a Dockerfile (ours replaces it), managed Postgres, Tigris S3; default 1 GB machine                                                                      |
| Render                                    | yes                          | Docker web service plus background workers and cron, managed PostgreSQL, S3 external                                                                                           |
| Kamal 2                                   | yes, later                   | needs a registry (GHCR works), SSH root, kamal-proxy with Let's Encrypt on a single host, health on `/up`; multiple apps per host by `host`; good once there are several hosts |
| Coolify                                   | yes                          | self-hosted PaaS that deploys our compose file behind Traefik; `${VAR:?}` markers become required fields; no `ports:` on the app service                                       |
| Dokku                                     | yes                          | single-host, Dockerfile or image deploy, plugins for Let's Encrypt and PostgreSQL; smaller community than Coolify today                                                        |
| cPanel on a server you own (WHM, root)    | yes, as "VPS without Docker" | cPanel supports only the OS-packaged PostgreSQL via its `installpostgres` script (not on Ubuntu), so bring your own PostgreSQL 18 or a managed one                             |
| cPanel shared account                     | degraded, see below          |                                                                                                                                                                                |

### cPanel shared hosting

Many target customers run cPanel, so this is answered precisely.

What is available: PHP 8.4 exists in EasyApache 4 (`ea-php84`), but it is not in the default profile; the host
must install it and the `pgsql`/`pdo_pgsql`, `intl`, `gd`, `bcmath` packages, and leave `pcntl` enabled
(shared hosts often disable it). Cron is available per account, and `public/.htaccess` ships for Apache. The
document root must point at `billing/public` (addon domain, or the primary domain's root changed), which a
shared account can usually do.

What is missing and what degrades:

| Need                       | On a shared account                                          | Effect                                                                                                                                                                                                                                                                                                                                                                             |
| -------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PostgreSQL 18              | rarely offered; when offered it is the OS version, far older | must use a remote managed PostgreSQL 18 over the network, or the host must allow it; MySQL is untested in CI (ADR 0006) and not promised                                                                                                                                                                                                                                           |
| Long-running queue workers | no process manager; stray processes are killed               | cron every minute: `queue:work --stop-when-empty --max-time=50` per pool, and `queue:work automation --queue=automation --stop-when-empty --tries=1 --timeout=3600` for Run now (with the `automation` connection argument, `install.md` §6); a job waits up to a minute; provisioning and emails are delayed, never lost; a host that kills long processes stops Run now part-way |
| `pcntl`                    | often disabled                                               | no job timeouts: a hung driver call blocks the pool until PHP's `max_execution_time`                                                                                                                                                                                                                                                                                               |
| Scheduler                  | cron, but some hosts cap frequency at 5 or 15 minutes        | the heartbeat shows gaps; `automation:run` at 03:00 still runs if cron runs at least hourly                                                                                                                                                                                                                                                                                        |
| Composer and Node          | usually absent                                               | deploy a release tarball built elsewhere (`vendor/`, `public/build` included); upgrades by upload plus `migrate` via SSH or the cPanel terminal                                                                                                                                                                                                                                    |
| Object storage             | none local                                                   | any S3-compatible bucket, or `OBJECTS_DRIVER=local` into `storage/app` (then it must be in the account backup)                                                                                                                                                                                                                                                                     |
| Redis                      | usually none                                                 | defaults are `database` for queue, cache and session, so nothing changes                                                                                                                                                                                                                                                                                                           |
| TLS and proxy              | AutoSSL                                                      | fine; no proxy, so no trust-proxy change needed                                                                                                                                                                                                                                                                                                                                    |
| Isolation                  | shared PHP user, no read-only filesystem, no resource limits | security posture below the container stack                                                                                                                                                                                                                                                                                                                                         |

Verdict: it runs, in "cron mode", when the host provides PHP 8.4 with those extensions, minute cron, and a
reachable PostgreSQL 18. That is a documented fallback, not the default, and the tarball the build workflow
produces is what makes it practical. A cPanel server with root is simply the non-Docker VPS path.

## 6. Branch strategy and the build workflow

**`deploy/` on the main line, not a `deploy` branch.** The Dockerfile must match the lockfiles of the commit it
builds; a separate branch drifts, needs rebasing on every release, and escapes the CI policy gates (digest
pins, cooldown, actionlint) that only run on PRs into `develop`/`main`. Provenance attestations bind an image to
a commit, which only makes sense when that commit contains the Dockerfile. Dependabot watches directories on a
branch, so a `deploy/` directory on `develop` gets the same 14-day-cooldown updates as everything else.

File layout:

```
deploy/
  Dockerfile              # stages composer, wayfinder, assets; targets prod and dev; context is billing/
  compose.yaml            # production: caddy, app, worker-*, scheduler, migrate, postgres, rustfs
  compose.dev.yaml        # containerised development: target dev, source bind-mounted, vp dev
  .env.example            # production variables with ${VAR:?} markers
  caddy/Caddyfile         # edge: one site block, TLS, forwarded headers
  frankenphp/Caddyfile    # app server: :8080, php_server, no auto HTTPS
  php/php.ini  php/opcache.ini
  entrypoint.sh           # optimize at start, wait for the database, then exec the command
billing/.dockerignore
.github/workflows/image.yml   # workflow_dispatch only
```

`billing/compose.yaml` stays as the local-infrastructure file for the host-run app (`local-setup.md`).

The manually dispatched workflow:

- **Trigger:** `workflow_dispatch` with inputs `ref` (default `develop`), `push_to_ghcr` (default false),
  `platforms` (default `linux/amd64`). `permissions: contents: read`, plus `packages: write` only in the push
  job.
- **Build:** `docker/setup-buildx-action` (container driver) and `docker/build-push-action`, actions pinned by
  SHA as in `ci.yml`, context `billing`, file `deploy/Dockerfile`, target `prod`.
- **Multi-arch:** `linux/amd64,linux/arm64` via QEMU; emulated `composer install` and `npm ci` are slow, so
  keep amd64 the default and build arm64 on request. arm64 standard runners became available to private
  repositories in January 2026 and count against the plan's minutes; a native arm64 job is the faster option
  when it is needed.
- **Artifact versus GHCR:** export `--output type=oci,dest=image.tar` and upload it with
  `actions/upload-artifact` (`compression-level: 0`, it is already compressed layers; `retention-days` short).
  The OCI layout keeps a multi-platform index; the classic `docker` exporter cannot. Loading an OCI tarball needs
  the containerd image store, which Docker Engine 29+ uses by default. Artifacts share the Packages storage pool
  (500 MB on Free, 2 GB on Team; $0.25/GB-month beyond), so keep retention at 7 days. Pushing to `ghcr.io` with
  `GITHUB_TOKEN` is one extra step behind the `push_to_ghcr` input and links the package to the repository
  automatically; the package is private by default.
- **SBOM and provenance:** `provenance: mode=max` and `sbom: true` on the build step; BuildKit attaches both to
  the index, and they survive in the OCI tarball and in GHCR. Attestations need the container driver, which the
  setup action provides.
- **Size reporting:** `docker buildx imagetools inspect` on the result and `du -h image.tar`, written to
  `$GITHUB_STEP_SUMMARY`; fail the job above an agreed ceiling.
- **Release tarball:** the same run packs `billing/` with `vendor/` and `public/build` from the build stages as
  `release.tar.gz` for the non-Docker and cPanel paths.
- **ADR 0007:** every `FROM` is tag plus digest and at least 7 days old; extend `check-dependency-age.mjs` to
  scan `FROM` lines in `deploy/Dockerfile` and `image:` lines in `deploy/*.yaml`, and add `docker` and
  `docker-compose` Dependabot entries for `/deploy`. The script verifies Docker Hub only; `ghcr.io` images we
  publish are our own and need no age check.

## Recommendation

| Layer         | Default                                                                   | Fallback                                                  |
| ------------- | ------------------------------------------------------------------------- | --------------------------------------------------------- |
| Runtime       | FrankenPHP classic mode, Debian trixie, one image, non-root, read-only FS | Octane worker mode later; nginx + php-fpm never needed    |
| Edge          | Caddy with a static Caddyfile                                             | Traefik when label routing across projects is wanted      |
| Host          | one VPS with Docker Compose                                               | systemd or Forge without Docker; Coolify, Kamal 2 later   |
| cPanel shared | documented cron mode with a remote PostgreSQL 18 and the release tarball  | not supported without PHP 8.4, minute cron and PostgreSQL |
| Files         | `deploy/` on `develop`                                                    | no `deploy` branch                                        |

Decisions the owner must make: Caddy or Traefik at the edge; whether to publish to GHCR as well as the artifact;
whether cPanel shared hosting is a supported target (it needs a support doc and a test on a real account);
whether to adopt Octane after the cooldown and a static-state review.

## What the DevOps engineer builds first

1. Core prerequisite PR: `TRUSTED_PROXIES` read in `bootstrap/app.php`; `INERTIA_SSR_ENABLED` env.
2. `deploy/Dockerfile` with the five stages, `billing/.dockerignore`, `entrypoint.sh`, PHP and Caddy configs;
   build it once off the codespace and record the image size.
3. `deploy/compose.yaml` and `deploy/.env.example`; bring the stack up on a throwaway VPS, run `app:install`,
   confirm HSTS, the real client IP in the activity log, an invoice PDF from the bucket and a queued email.
4. `.github/workflows/image.yml` with the artifact output, SBOM, provenance and size summary; then the policy
   script and Dependabot extensions.
5. `deploy/compose.dev.yaml` and a `docs/devops/production.md` runbook (ADR 0014 expects it): install, update,
   backup, restore, rollback, and the cPanel cron-mode page if the owner chooses to support it.

## Owner decisions (2026-10-19)

- Edge proxy: Caddy. Traefik stays documented as the alternative.
- Build workflow output: always a downloadable OCI artifact; pushing to GHCR is an optional input of the manual run.
- cPanel shared hosting: supported in the degraded "cron mode" described above, documented and tested, with its
  limits stated to the operator.
