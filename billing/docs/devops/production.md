# Production on one Docker host

Runbook for `deploy/compose.prod.yaml`: install, configure, update, back up, restore and roll back. The image is
described in [container-image.md](container-image.md), the design choices in
[deployment-options.md](deployment-options.md). Installs without Docker follow [install.md](install.md).
Every deploy is blue-green (`deploy/bin/deploy`): the new version starts beside the live one and takes traffic only
once it is healthy, with no gap, and the old one stays ready for a one-command rollback. That is why migrations
follow the expand-then-contract rule ([architecture.md](../architecture.md#migrations-during-blue-green-deploys-expand-then-contract)).

## What runs

The app's roles run once per colour (`blue`, `green`): `app-<colour>`, `worker-critical-<colour>`,
`worker-notifications-<colour>`, `worker-default-<colour>`, `worker-automation-<colour>` and `scheduler-<colour>`.
Only the live colour's workers, automation worker and scheduler run; the previous colour's web container keeps
running for a rollback until you `retire` it. `deploy/bin/deploy status` shows which colour is live.

| Service                  | Image           | Command                                                                  | Limit (CPU / memory) | Stop grace |
| ------------------------ | --------------- | ------------------------------------------------------------------------ | -------------------- | ---------- |
| `caddy`                  | `caddy`         | edge proxy: TLS, HTTP→HTTPS, forwards to the live colour                 | 0.5 / 128 MB         | default    |
| `app-<colour>`           | the app image   | FrankenPHP, classic mode                                                 | 1.0 / 512 MB         | 30 s       |
| `worker-critical-…`      | the app image   | `queue:work --queue=payments,provisioning,registrar --timeout=200`       | 0.5 / 384 MB         | 210 s      |
| `worker-notifications-…` | the app image   | `queue:work --queue=notifications,webhooks` (60 s job timeout)           | 0.5 / 384 MB         | 70 s       |
| `worker-default-…`       | the app image   | `queue:work --queue=default,imports --timeout=150 --memory=256`          | 0.5 / 384 MB         | 160 s      |
| `worker-automation-…`    | the app image   | `queue:work automation --queue=automation --tries=1 --timeout=3600`      | 0.5 / 384 MB         | 3,660 s    |
| `scheduler-…`            | the app image   | `schedule:work` (exactly one, live colour)                               | 0.5 / 512 MB         | 3,660 s    |
| `migrate`                | the app image   | one-shot: `migrate`, `objects:ensure-bucket`, `app:install` (idempotent) | 1.0 / 512 MB         | —          |
| `postgres`               | `postgres`      | PostgreSQL 18                                                            | 1.0 / 1 GB           | 60 s       |
| `rustfs`                 | `rustfs/rustfs` | S3-compatible object storage (console off)                               | 0.5 / 512 MB         | default    |

The workers run the four commands of `App\Support\Queues::workerCommands()`, which `app:install` prints and
[install.md §6](install.md#6-scheduler-and-queue-workers) explains: the three ADR 0014 pools (with `--tries=3`) and
the automation worker. The three pools also restart every hour (`--max-time=3600`). A stop waits up to the grace
period so a running job finishes; the critical pool's `--timeout` covers the provisioning cap (180 s + 20 s lock
slack) and stays below the queue's `retry_after` (240 s). `worker-automation` runs **Run now** from Admin →
Automation on its own `automation` connection (`retry_after` 3,900 s, above the job's 3,600 s timeout); start it with
the connection argument as shown, run only one, and never add `automation` to another worker's `--queue`. Its stop
grace of 3,660 s lets an update wait for a run in progress instead of killing it.

The scheduler gets the same 3,660 s: the daily automation pipeline runs inside it (546 s for 50,000 clients in load
testing), and `schedule:work` waits for a run in progress when it is stopped. An idle scheduler still stops in well
under a second. Its 512 MB covers `schedule:work`, `schedule:run` and the pipeline, which PHP allows up to 256 MB.

**Sizing.** The minimum is **2 vCPU and 4 GB**, the same host [sizing.md](sizing.md) measured: at 50,000 clients on that
host (one colour, the load-test profile) all containers together used at most 857 MB, and the p95 over all pages
was 424 ms at 40 active users. A full blue-green deploy, with both colours' web containers and pools running, also
ran on a 3.6 GB Docker VM (peak 691 MiB in total; demo data, not 50,000 clients: see the restore drill below).
The memory limits in the table are ceilings, not what is used: they add up to 4,736 MB between deploys and 5,888 MB at the peak of a deploy
(the live colour, the previous colour's web container, the new one and its pools; `migrate` runs before that), more than a 4 GB host has.
Nothing in the measurements came near them, but a host whose containers together claimed more than its memory would
be OOM-killed before a limit applied, so watch `free -m` during a deploy and run `deploy/bin/deploy retire` to free the
previous web container when memory is tight. CPU limits may exceed the host's cores: busy containers then share the
CPU and run slower, nothing is killed. Not measured: a deploy at 50,000 clients, a deploy during the billing day, and
hosts with more than 2 vCPU (sizing.md says when to grow).

**Networks.** Only Caddy publishes ports (80, 443 and 443/udp for HTTP/3). `edge` connects Caddy and the app on a
fixed range (`EDGE_SUBNET`), which is what `TRUSTED_PROXIES` names, so only Caddy may set the client IP and scheme.
`backend` is internal: PostgreSQL and RustFS have no route to the internet and no published port. The PHP services,
the app included, also join `egress` for payment gateways, registrars, control panels and mail.

**Hardening.** Every PHP container runs as UID 10001 on a read-only root filesystem (tmpfs for `/tmp`,
`bootstrap/cache`, `/data`, `/config`), with all capabilities dropped and `no-new-privileges`; only the `storage`
volume is writable. Caddy keeps `NET_BIND_SERVICE` only. The Docker socket is mounted nowhere.

**Logs.** Every container logs to stdout/stderr (the app as JSON lines, Caddy's access log as JSON). The json-file
driver rotates at 10 MB × 5 files per container. Read them with
`docker compose -f deploy/compose.prod.yaml --profile blue --profile green logs -f app-blue worker-critical-blue`
(use the live colour). Caddy's access log names the colour that served each request (`upstream`).

## Requirements

- A Linux host with Docker Engine and the Compose v2 plugin (the smoke test runs on GitHub's `ubuntu-24.04`
  runner; Compose 5.5 was used locally). 2 vCPU and 4 GB of memory as a minimum (see Sizing and [sizing.md](sizing.md)).
- DNS `A`/`AAAA` records for `APP_DOMAIN` pointing at the host, and ports 80 and 443 (TCP, plus 443/UDP) open. Caddy
  obtains the certificate on first start from Let's Encrypt. Set `CADDY_GLOBAL_OPTIONS="email <your address>"` (Let's Encrypt refuses `example.com`): Caddy
  then also uses ZeroSSL as a fallback issuer (without an email it uses Let's Encrypt only) and you get expiry notices.
- An SMTP account or mail API; password resets, invoices and security notices depend on it.
- A place off the host for backups.

## First install

All commands run from the repository root (a clone at the release you deploy).

1. **Settings.** Create `deploy/.env` and fill it in. Generate the secrets with `openssl rand -base64 32`.

    ```bash
    cp deploy/.env.production.example deploy/.env && chmod 600 deploy/.env
    ```

    Set `APP_DOMAIN` and `APP_URL` (`https://` + the domain), `DB_PASSWORD`, `OBJECTS_ACCESS_KEY`,
    `OBJECTS_SECRET_KEY`, the `MAIL_*` block and `CADDY_GLOBAL_OPTIONS="email <your address>"`. `DB_HOST`, `OBJECTS_ENDPOINT` and `TRUSTED_PROXIES` are set by the
    compose file; do not set them here.

2. **Image.** Build it on the host (`deploy/bin/deploy` does this in step 5 too), or use a pushed image by digest
   with `deploy/bin/deploy --image <ref>`.

    ```bash
    docker compose -f deploy/compose.prod.yaml build migrate
    ```

3. **Application key**, once. Put the output in `APP_KEY` (or in a Docker secret, see
   [key-rotation.md](key-rotation.md#where-the-key-lives)), and store a copy in your password manager, away from the
   backups: it decrypts the stored gateway, registrar and server credentials. Rotating it later:
   [key-rotation.md](key-rotation.md).

    ```bash
    docker compose -f deploy/compose.prod.yaml run --rm --no-deps -e APP_OPTIMIZE=0 migrate php artisan key:generate --show
    ```

4. **Database, bucket, reference data and the first administrator.** This starts PostgreSQL and RustFS, then asks
   for the administrator's name, email address and password in your terminal:

    ```bash
    docker compose -f deploy/compose.prod.yaml up -d --wait postgres rustfs
    docker compose -f deploy/compose.prod.yaml run --rm migrate sh -c \
      'php artisan migrate --force && php artisan objects:ensure-bucket && php artisan app:install'
    ```

    `app:install --admin-email=you@example.com` instead prints a generated one-time password once (sign-in then
    forces a new one). Two-factor setup is required at the first admin sign-in.

5. **First deploy.** It checks and runs the migrations again (nothing to do), starts the blue colour, writes Caddy's
   upstream and starts Caddy, then the workers and the scheduler.

    The `migrate` service runs `migrate --isolated=1`: a second deploy started meanwhile stops with "The [migrate]
    command is already running." A migrator that was killed keeps the lock for up to an hour; once you are sure none
    is running, release it with `docker compose -f deploy/compose.prod.yaml run --rm migrate php artisan tinker
   --execute 'Cache::lock("framework/command-migrate")->forceRelease();'`. A migration can also stop on a table lock
    it cannot get (`DB_MIGRATION_LOCK_TIMEOUT`, docs/architecture.md rule 5): the error names the session in the way.

    ```bash
    deploy/bin/deploy
    ```

6. **Check.** Open `https://APP_DOMAIN/up`, then sign in. Confirm that the response carries
   `Strict-Transport-Security` (the app trusts the proxy) and that Admin → Activity shows your real IP address, not
   the proxy's. `app:install`'s warnings (mail, scheduler heartbeat) clear once mail is configured and the
   scheduler has run for a minute.

The same sequence, with generated secrets and Caddy's internal certificate, is what `deploy/bin/smoke` runs on a
GitHub runner (Actions → Deploy smoke, or the `deploy-smoke` label on a pull request).

The smoke also runs the full demo seeder, `php artisan db:seed --force`, once at the end in a one-off container with
`APP_ENV=local` on the stack's backend network (the stack itself stays `production`, where `DatabaseSeeder` skips demo data). The seeders need Faker, which the production image does not contain, so the step builds the smoke-only `smoke-seed` target of `deploy/Dockerfile` (dev dependencies; never deployed or published). That exercises every
module's seeder on PostgreSQL with real commits; any seeder error fails the smoke, and the step refuses to run under
`APP_ENV=production` or for a `SMOKE_PROJECT` that is not `mohar-smoke*`. Never seed demo data on a real installation: the demo accounts have a known password.

## Changing settings

Containers read `deploy/.env` when they start, and the entrypoint caches the configuration then. After editing it,
deploy again: `deploy/bin/deploy` starts the other colour with the new settings and switches to it, without a gap (moving
the scheduler and automation worker can wait up to an hour, see Updating). Caddy reads `APP_DOMAIN`, `CADDY_*` and
the ports: after changing those, also run `docker compose -f deploy/compose.prod.yaml up -d --force-recreate caddy`
(a restart of a few seconds).

## Updating

```bash
git pull                     # or check out the release tag
deploy/bin/deploy            # or: deploy/bin/deploy --image ghcr.io/<owner>/<repo>@sha256:<digest>
```

The script, step by step:

1. Builds the checkout as `mohar-app:<commit>` (or uses `--image`).
2. Runs `php artisan deploy:check-migrations --pending` in the new image, and stops if a pending migration drops,
   renames or changes schema in one step (`DEPLOY_SKIP_MIGRATION_CHECK=1` skips the check once you have reviewed
   it).
3. Runs the one-shot `migrate` with the new image: migrations, the bucket, reference data.
4. Starts the idle colour's web container and waits for its health check, then probes `/up` from Caddy's network.
   If it is not healthy, it stops it and leaves traffic where it was.
5. Writes `deploy/state/live/upstream.caddy` and reloads Caddy gracefully: in-flight requests finish, new ones go
   to the new colour. It confirms Caddy's running configuration names the new upstream, and restores the old file
   if the reload or that check fails. Caddy's config directory (`deploy/caddy`) is mounted, not the single file, so a
   release that changes the Caddyfile takes effect at this reload too. The live colour is recorded right after the
   switch.
6. Starts the new colour's three pools, then stops the old scheduler and automation worker and starts the new ones
   (exactly one of each ever runs; if the new ones do not become healthy, the old ones start again and the deploy
   stops, with traffic on the new colour and `rollback` ready), then stops the old pools. A stopped worker finishes its current job first, so no
   job runs twice. **While the daily automation run or a Run now is in progress, stopping the old scheduler or
   automation worker waits for it, up to an hour** (their 3,660 s stop grace); traffic has already switched, and both
   colours' pools keep working the queues meanwhile. Check Admin → Automation before deploying.
7. Records the live and previous colour and their images in `deploy/state/deploy.env`.

Back up the database before an update that contains migrations, and read the changelog's upgrade notes. While both
colours run, the host needs more memory: see Sizing.

**Base images.** The `FROM` images in `deploy/Dockerfile` are updated by Dependabot (14-day cooldown). Caddy and
RustFS in `compose.prod.yaml` are bumped by hand: GitHub does not document whether Dependabot reads a compose file
named `compose.prod.yaml`. Every pin must be a digest at least 7 days old (`composer deps:check`), and the postgres
image must equal the one in `billing/compose.yaml` (CI checks it).

**PHP security floor.** The image runs PHP 8.4.27 (FrankenPHP 1.13.2), which fixes CVE-2026-109618 and
CVE-2026-109619 (unauthenticated remote code execution in every web SAPI); images built from an older checkout run
PHP 8.4.26 or older and are vulnerable. Update with the steps above: `deploy/bin/deploy` rebuilds the image and replaces
the web container and every worker, so no PHP process keeps the old binary. Check with
`docker compose -f deploy/compose.prod.yaml exec web-<colour> php -v`. The prod build stops if its PHP is older than the
floor, and the admin dashboard shows "PHP runtime" in red while it is (see [install.md](install.md#php-security-floor)).

## Rolling back

```bash
deploy/bin/deploy rollback   # switch back to the previous colour; run it again to roll forward
deploy/bin/deploy retire     # once you are sure: stop the previous colour's web container
```

A rollback switches Caddy back to the previous colour's web container (started again if it was retired), moves the
workers and the scheduler with the same drain, and keeps the other colour for a roll-forward. Migrations are not
rolled back: the expand-then-contract rule keeps the previous release working against the new schema. A release
that broke that rule (a `@contract` or `@compatible` migration that was wrong) needs the database restored from the
backup taken before the update.

## Backups

What to back up, as in [install.md](install.md#backups): the database and the objects bucket. `APP_KEY` is kept in
a secrets store, apart from both; host and file backups exclude `deploy/.env` ([key-rotation.md](key-rotation.md)).

> **Keep the key with your backups, in a different place.** A dump and a bucket copy are not restorable without the
> `APP_KEY` they were written under: the gateway, registrar and server credentials and the identity images (KYC) are
> encrypted with it. Read with another key, an encrypted value fails with a
> `DecryptException` (the restore drill below did that on purpose with an identity image). So for every backup you keep, keep the key it
> was written under (and the earlier ones in `APP_PREVIOUS_KEYS` for dumps from before a rotation), in a password
> manager or secrets store that survives the loss of this host. Never in the same file, folder or bucket as the dumps.

- **Database**, nightly and before every update, from the host's crontab (`crontab -e` as a user in the `docker`
  group), before the 03:00 automation run. Cron turns `%` into a newline, hence `\%`; `umask 077` keeps the dump
  readable by its owner only:

    ```cron
    30 2 * * * umask 077 && docker compose -f /srv/billing/deploy/compose.prod.yaml exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > /backups/billing-$(date +\%F).dump
    ```

- **Objects** (invoice PDFs, attachments, exports, brand assets): point `OBJECTS_*` at a managed bucket with versioning
  or replication, or copy the `rustfs-data` volume. Stop RustFS for the copy, so no object is caught half-written
  (uploads and PDF downloads fail for those seconds; run it outside the automation window). The RustFS image has `tar`.
  It runs as root (`--user 0:0`), because the image's own user cannot write to a root-owned `/backups`, and with
  `umask 077` inside the container, so the archive is readable by root only:

    ```bash
    compose="docker compose -f /srv/billing/deploy/compose.prod.yaml"
    $compose stop rustfs
    $compose run --rm --no-deps --user 0:0 -v /backups:/backup --entrypoint sh rustfs \
      -c 'umask 077 && tar czf /backup/objects-$(date +%F).tgz -C /data .'
    $compose start rustfs
    ```

- **`APP_KEY`** (and the earlier keys, for dumps taken before a rotation) in a password manager or secrets store, never
  next to the dumps. A snapshot of the host or of `/srv/billing` excludes `deploy/.env`. To keep the key out of
  `deploy/.env` altogether, use Docker secrets (`deploy/compose.secrets.example.yaml`, see
  [key-rotation.md](key-rotation.md#where-the-key-lives)).

Copy the files off the host, and test a restore every quarter.

## Restoring

Into an empty stack (a new host, or after removing the volumes). You need `deploy/.env` with the same `APP_KEY` as the
backup (from your password manager, see above); on a new host also clone the repository at the release you run.

To empty a stack you want to restore over, remove it with both colours' profiles, or the app and worker containers of
the colours stay running with their volumes in use (`down -v` alone removes only `postgres`, `rustfs` and `caddy`, and
`--remove-orphans` does not help):

```bash
docker compose -f deploy/compose.prod.yaml --profile blue --profile green down -v
rm -rf deploy/state     # the live colour of the old stack
```

```bash
docker compose -f deploy/compose.prod.yaml up -d --wait postgres rustfs
docker compose -f deploy/compose.prod.yaml exec -T postgres \
  sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error' < /backups/billing-2026-10-19.dump
docker compose -f deploy/compose.prod.yaml stop rustfs
docker compose -f deploy/compose.prod.yaml run --rm --no-deps --user 0:0 -v /backups:/backup --entrypoint tar rustfs \
  xzf /backup/objects-2026-10-19.tgz -C /data
docker compose -f deploy/compose.prod.yaml start rustfs
deploy/bin/deploy
```

`deploy/bin/deploy` does the rest: it builds the image if the host has none, finds nothing to migrate, starts a colour,
writes Caddy's upstream and starts Caddy, then the workers and the scheduler (a plain `up` would start no app colour,
and Caddy refuses to start without an upstream).

**Check the result**, in this order:

1. Row counts: `select count(*)` of the tables that matter (`clients`, `invoices`, `payments`, `services`) against the
   same query on the old database, if you still have it.
2. Sign in as an administrator.
3. Open an invoice PDF. It comes from the bucket, or is rendered again and stored, so it alone does not prove the bucket.
4. If you use KYC, open an identity document in the review queue. Only the right key decrypts the image and nothing
   regenerates it: it is the proof that the bucket and the key both came back.

### Restore drill, 2026-10-22

Run once, end to end, as the steps above read, on Docker Desktop (macOS, Compose 5.5) with a scratch project name
and generated secrets: the demo seed (956 invoices, 22 clients), one invoice PDF rendered into the bucket and one
identity image encrypted onto it, then the backup, `down -v`, the restore into a fresh stack and the checks.

| Measure                                                                                     | Result                                                                             |
| ------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Database                                                                                    | 117 tables, 6,217 rows, 29 MB on disk; the dump is 1.0 MB (`--format=custom`)      |
| Objects                                                                                     | 28 files in the archive (invoice PDF, KYC image, ticket attachment); 0.8 MB        |
| Backup time                                                                                 | dump under 1 s; objects archive, including stopping and starting RustFS, under 1 s |
| Restore time, from an empty host to the checks passing (image already built, layers cached) | 42 s                                                                               |
| Row counts, all 117 tables, before the backup against after the restore                     | identical                                                                          |
| Administrator and client sign-in                                                            | 302 to the dashboard in both                                                       |
| Invoice PDF                                                                                 | 200, `application/pdf`, 879,110 bytes, same SHA-256 as before                      |
| KYC image                                                                                   | decrypts, same SHA-256 and the same document number                                |
| KYC image with another `APP_KEY`                                                            | `DecryptException`                                                                 |

Not covered: a database or bucket of a real size (the dump and restore times grow with it; no restore at 50,000
clients has been timed), a restore from a managed bucket or from the `rclone` copy, a dump taken before a key rotation,
and a different `DB_PASSWORD` or `OBJECTS_*` pair on the new host.

## Troubleshooting

| Symptom                                                                                 | Look at                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| --------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `deploy` stops at the migration check                                                   | a pending migration drops, renames or changes schema in one step: split it (architecture.md), or tag it `@contract` / `@compatible` with the reason                                                                                                                                                                                                                                                                                                                                                                                                              |
| `deploy` stops at the migrations                                                        | its output, or `logs migrate`. "No full administrator was created" means step 4 of the first install was skipped                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `deploy` says the new colour is unhealthy                                               | the printed `app-<colour>` logs; traffic stayed on the live colour, so fix and deploy again                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| 502 from Caddy                                                                          | `deploy/bin/deploy status`: the live `app-<colour>` must be `healthy`; `docker compose -f deploy/compose.prod.yaml --profile <colour> logs app-<colour>` shows why it is not                                                                                                                                                                                                                                                                                                                                                                                     |
| No HSTS, or the activity log shows one IP                                               | the app does not trust the proxy: `EDGE_SUBNET` must be the edge network's range (`docker network inspect mohar_edge`)                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Certificate errors                                                                      | DNS for `APP_DOMAIN` and ports 80/443 from the internet; `logs caddy`. Keep the `caddy-data` volume, or every start requests new certificates                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Jobs not processed                                                                      | `logs worker-default-<colour>` (or the pool that carries the queue; **Run now** is `worker-automation-<colour>`); the dashboard's automation summary shows the scheduler heartbeat                                                                                                                                                                                                                                                                                                                                                                               |
| An automation run was killed (host reboot, out of memory, a stop that ran out of grace) | the day's remaining tasks did not run, and **Run now** reports "another run was in progress" while the killed run's locks remain. Make sure no run is in progress (Admin → Automation), then clear the scheduler's mutex and the automation locks with the live colour's scheduler: `docker compose -f deploy/compose.prod.yaml --profile <colour> exec -T scheduler-<colour> php artisan schedule:clear-cache` and `... php artisan automation:release-locks --force` (install.md §6), and press **Run now**. Tasks that already succeeded that day are skipped |
| `Read-only file system` errors                                                          | something writes outside `storage/`, `/tmp` or `bootstrap/cache`: report it as a bug                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

## Traefik

Caddy is the edge proxy (owner decision, 2026-10-19). A Traefik variant is not part of this release: Traefik's Docker
provider needs the Docker API, and even a read-only socket mount allows every API call, so it needs a socket proxy on
an internal network first. [deployment-options.md](deployment-options.md) compares the two.
