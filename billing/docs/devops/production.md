# Production on one Docker host

Runbook for `deploy/compose.prod.yaml`: install, configure, update, back up, restore and roll back. The image is
described in [container-image.md](container-image.md), the design choices in
[deployment-options.md](deployment-options.md). Installs without Docker follow [install.md](install.md).
Blue-green deploys (no gap during an update) and the expand-then-contract migration rule come in the next PR; until
then an update restarts the app in place, with a gap of a few seconds.

## What runs

| Service                | Image           | Command                                                                  | Limit (CPU / memory) | Stop grace |
| ---------------------- | --------------- | ------------------------------------------------------------------------ | -------------------- | ---------- |
| `caddy`                | `caddy`         | edge proxy: TLS, HTTP→HTTPS, forwards to `app:8080`                      | 0.5 / 128 MB         | default    |
| `app`                  | the app image   | FrankenPHP, classic mode                                                 | 1.0 / 512 MB         | 30 s       |
| `worker-critical`      | the app image   | `queue:work --queue=payments,provisioning,registrar --timeout=200`       | 0.5 / 384 MB         | 210 s      |
| `worker-notifications` | the app image   | `queue:work --queue=notifications,webhooks` (60 s job timeout)           | 0.5 / 384 MB         | 70 s       |
| `worker-default`       | the app image   | `queue:work --queue=default` (60 s job timeout)                          | 0.5 / 384 MB         | 70 s       |
| `worker-automation`    | the app image   | `queue:work automation --queue=automation --tries=1 --timeout=3600`      | 0.5 / 384 MB         | 3,660 s    |
| `scheduler`            | the app image   | `schedule:work` (exactly one)                                            | 0.5 / 512 MB         | 3,660 s    |
| `migrate`              | the app image   | one-shot: `migrate`, `objects:ensure-bucket`, `app:install` (idempotent) | 1.0 / 512 MB         | —          |
| `postgres`             | `postgres`      | PostgreSQL 18                                                            | 1.0 / 1 GB           | 60 s       |
| `rustfs`               | `rustfs/rustfs` | S3-compatible object storage (console off)                               | 0.5 / 512 MB         | default    |

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

**Sizing.** The memory limits add up to 4,224 MB (4,736 MB while `migrate` runs), and the CPU limits to 5.5 (6.5).
Limits are ceilings, not reservations, but a host whose containers can together claim more memory than it has gets
OOM-killed before any limit applies. So **6 GB is the minimum and 8 GB is recommended**; 4 GB is not enough. CPU limits
may exceed the host's cores (2 vCPU works): busy containers then share the CPU and run slower, nothing is killed.

**Networks.** Only Caddy publishes ports (80, 443 and 443/udp for HTTP/3). `edge` connects Caddy and the app on a
fixed range (`EDGE_SUBNET`), which is what `TRUSTED_PROXIES` names, so only Caddy may set the client IP and scheme.
`backend` is internal: PostgreSQL and RustFS have no route to the internet and no published port. The PHP services,
the app included, also join `egress` for payment gateways, registrars, control panels and mail.

**Hardening.** Every PHP container runs as UID 10001 on a read-only root filesystem (tmpfs for `/tmp`,
`bootstrap/cache`, `/data`, `/config`), with all capabilities dropped and `no-new-privileges`; only the `storage`
volume is writable. Caddy keeps `NET_BIND_SERVICE` only. The Docker socket is mounted nowhere.

**Logs.** Every container logs to stdout/stderr (the app as JSON lines, Caddy's access log as JSON). The json-file
driver rotates at 10 MB × 5 files per container. Read them with
`docker compose -f deploy/compose.prod.yaml logs -f app worker-critical`.

## Requirements

- A Linux host with Docker Engine and the Compose v2 plugin (the smoke test runs on GitHub's `ubuntu-24.04`
  runner; Compose 5.5 was used locally). 2 vCPU and 6 GB of memory as a minimum, 8 GB recommended (see Sizing).
- DNS `A`/`AAAA` records for `APP_DOMAIN` pointing at the host, and ports 80 and 443 (TCP, plus 443/UDP) open. Caddy
  obtains the certificate on first start from Let's Encrypt. Set `CADDY_GLOBAL_OPTIONS="email ops@example.com"`: Caddy
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
    `OBJECTS_SECRET_KEY` and the `MAIL_*` block. `DB_HOST`, `OBJECTS_ENDPOINT` and `TRUSTED_PROXIES` are set by the
    compose file; do not set them here.

2. **Image.** Build it on the host, or name a pushed image in `APP_IMAGE` (by digest) and skip the build.

    ```bash
    docker compose -f deploy/compose.prod.yaml build
    ```

3. **Application key**, once. Put the output in `APP_KEY`, and store a copy in your password manager, away from the
   backups: it decrypts the stored gateway, registrar and server credentials.

    ```bash
    docker compose -f deploy/compose.prod.yaml run --rm --no-deps -e APP_OPTIMIZE=0 app php artisan key:generate --show
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

5. **Start everything.** The `migrate` one-shot runs again before the app and workers start, and Caddy waits for the
   app's health check.

    ```bash
    docker compose -f deploy/compose.prod.yaml up -d --wait
    ```

6. **Check.** Open `https://APP_DOMAIN/up`, then sign in. Confirm that the response carries
   `Strict-Transport-Security` (the app trusts the proxy) and that Admin → Activity shows your real IP address, not
   the proxy's. `app:install`'s warnings (mail, scheduler heartbeat) clear once mail is configured and the
   scheduler has run for a minute.

The same sequence, with generated secrets and Caddy's internal certificate, is what `deploy/bin/smoke` runs on a
GitHub runner (Actions → Deploy smoke, or the `deploy-smoke` label on a pull request).

## Changing settings

Containers read `deploy/.env` when they start, and the entrypoint caches the configuration then. After editing it,
recreate the services that read it: `docker compose -f deploy/compose.prod.yaml up -d --force-recreate caddy app
worker-critical worker-notifications worker-default worker-automation scheduler` (Caddy reads `APP_DOMAIN`, `CADDY_*`
and the ports). Like an update, this can wait up to an hour while an automation run finishes (see Updating).

## Updating

Until blue-green deploys arrive, an update replaces the containers in place:

```bash
git pull                                              # or check out the release tag
docker compose -f deploy/compose.prod.yaml build      # or set APP_IMAGE to the new digest
docker compose -f deploy/compose.prod.yaml up -d --wait
```

`migrate` runs the new migrations before the new app and workers start; the web container is unavailable for the
seconds its health check takes. Every worker finishes its current job first (stop grace), and so does the scheduler:
**while the daily automation run or a Run now is in progress, `up` waits for it, up to an hour.** Check Admin →
Automation before updating. During that wait the old code keeps running against the already migrated schema. Back up
before an update that contains migrations, and read the changelog's upgrade notes.

**Base images.** The `FROM` images in `deploy/Dockerfile` are updated by Dependabot (14-day cooldown). Caddy and
RustFS in `compose.prod.yaml` are bumped by hand: GitHub does not document whether Dependabot reads a compose file
named `compose.prod.yaml`. Every pin must be a digest at least 7 days old (`composer deps:check`), and the postgres
image must equal the one in `billing/compose.yaml` (CI checks it).

## Rolling back

Redeploy the previous image: check out the previous release and build, or set `APP_IMAGE` to the previous digest,
then `up -d --wait`. Migrations are not rolled back. A release whose migrations only add tables and columns runs
with the older code; one that dropped or renamed something needs the database restored from the backup taken before
the update (the expand-then-contract rule in the next PR prevents such releases).

## Backups

What to back up, as in [install.md](install.md#backups): the database, the objects bucket, and `deploy/.env` with
`APP_KEY`, each kept apart from the others.

- **Database**, nightly and before every update, from the host's crontab (`crontab -e` as a user in the `docker`
  group), before the 03:00 automation run. Cron turns `%` into a newline, hence `\%`; `umask 077` keeps the dump
  readable by its owner only:

    ```cron
    30 2 * * * umask 077 && docker compose -f /srv/billing/deploy/compose.prod.yaml exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > /backups/billing-$(date +\%F).dump
    ```

- **Objects** (invoice PDFs, attachments, exports, brand assets): point `OBJECTS_*` at a managed bucket with versioning
  or replication, or copy the `rustfs-data` volume. Stop RustFS for the copy, so no object is caught half-written
  (uploads and PDF downloads fail for those seconds; run it outside the automation window). The RustFS image has `tar`:

    ```bash
    compose="docker compose -f /srv/billing/deploy/compose.prod.yaml"
    $compose stop rustfs
    (umask 077 && $compose run --rm --no-deps -v /backups:/backup --entrypoint tar rustfs czf /backup/objects-$(date +%F).tgz -C /data .)
    $compose start rustfs
    ```

- **`deploy/.env`** in a secrets store, never next to the dumps.

Copy the files off the host, and test a restore every quarter.

## Restoring

Into an empty stack (a new host, or after `down -v`):

```bash
docker compose -f deploy/compose.prod.yaml up -d --wait postgres rustfs
docker compose -f deploy/compose.prod.yaml exec -T postgres \
  sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error' < /backups/billing-2026-10-19.dump
docker compose -f deploy/compose.prod.yaml stop rustfs
docker compose -f deploy/compose.prod.yaml run --rm --no-deps -v /backups:/backup --entrypoint tar rustfs \
  xzf /backup/objects-2026-10-19.tgz -C /data
docker compose -f deploy/compose.prod.yaml up -d --wait
```

Use the same `APP_KEY` as the backup, then sign in and open an invoice PDF to confirm the bucket.

## Troubleshooting

| Symptom                                                                                 | Look at                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `up` stops at `migrate`                                                                 | `docker compose -f deploy/compose.prod.yaml logs migrate`. "No full administrator was created" means step 4 of the first install was skipped                                                                                                                                                                                                                                                                                                                                                                          |
| 502 from Caddy                                                                          | `docker compose ... ps`: the app must be `healthy`; `logs app` shows why it is not                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| No HSTS, or the activity log shows one IP                                               | the app does not trust the proxy: `EDGE_SUBNET` must be the edge network's range (`docker network inspect mohar_edge`)                                                                                                                                                                                                                                                                                                                                                                                                |
| Certificate errors                                                                      | DNS for `APP_DOMAIN` and ports 80/443 from the internet; `logs caddy`. Keep the `caddy-data` volume, or every start requests new certificates                                                                                                                                                                                                                                                                                                                                                                         |
| Jobs not processed                                                                      | `logs worker-default` (or the pool that carries the queue; **Run now** is `worker-automation`); the dashboard's automation summary shows the scheduler heartbeat                                                                                                                                                                                                                                                                                                                                                      |
| An automation run was killed (host reboot, out of memory, a stop that ran out of grace) | the day's remaining tasks did not run, and **Run now** reports "another run was in progress" while the killed run's locks remain. Make sure no run is in progress (Admin → Automation), then clear the scheduler's mutex and the automation locks: `docker compose -f deploy/compose.prod.yaml exec -T scheduler php artisan schedule:clear-cache` and `... exec -T scheduler php artisan automation:release-locks --force` (install.md §6), and press **Run now**. Tasks that already succeeded that day are skipped |
| `Read-only file system` errors                                                          | something writes outside `storage/`, `/tmp` or `bootstrap/cache`: report it as a bug                                                                                                                                                                                                                                                                                                                                                                                                                                  |

## Traefik

Caddy is the edge proxy (owner decision, 2026-10-19). A Traefik variant is not part of this release: Traefik's Docker
provider needs the Docker API, and even a read-only socket mount allows every API call, so it needs a socket proxy on
an internal network first. [deployment-options.md](deployment-options.md) compares the two.
