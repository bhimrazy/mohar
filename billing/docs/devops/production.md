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
| `worker-notifications` | the app image   | `queue:work --queue=notifications,webhooks --timeout=90`                 | 0.5 / 384 MB         | 100 s      |
| `worker-default`       | the app image   | `queue:work --queue=default --timeout=90`                                | 0.5 / 384 MB         | 100 s      |
| `scheduler`            | the app image   | `schedule:work` (exactly one)                                            | 0.5 / 256 MB         | 60 s       |
| `migrate`              | the app image   | one-shot: `migrate`, `objects:ensure-bucket`, `app:install` (idempotent) | 1.0 / 512 MB         | —          |
| `postgres`             | `postgres`      | PostgreSQL 18                                                            | 1.0 / 1 GB           | default    |
| `rustfs`               | `rustfs/rustfs` | S3-compatible object storage (console off)                               | 0.5 / 512 MB         | default    |

The worker pools are the ones ADR 0014 defines. Workers restart every hour (`--max-time=3600`), and a stop waits up
to the grace period so a running job finishes; the critical pool's `--timeout` covers the provisioning cap
(180 s + 20 s lock slack) and stays below the queue's `retry_after` (240 s). The limits fit a 2 vCPU / 4 GB host
with headroom; raise them in `compose.prod.yaml` on larger hosts.

**Networks.** Only Caddy publishes ports (80, 443 and 443/udp for HTTP/3). `edge` connects Caddy and the app on a
fixed range (`EDGE_SUBNET`), which is what `TRUSTED_PROXIES` names, so only Caddy may set the client IP and scheme.
`backend` is internal: PostgreSQL and RustFS have no route to the internet and no published port. The PHP services
also join `egress` for payment gateways, registrars, control panels and mail.

**Hardening.** Every PHP container runs as UID 10001 on a read-only root filesystem (tmpfs for `/tmp`,
`bootstrap/cache`, `/data`, `/config`), with all capabilities dropped and `no-new-privileges`; only the `storage`
volume is writable. Caddy keeps `NET_BIND_SERVICE` only. The Docker socket is mounted nowhere.

**Logs.** Every container logs to stdout/stderr (the app as JSON lines, Caddy's access log as JSON). The json-file
driver rotates at 10 MB × 5 files per container. Read them with
`docker compose -f deploy/compose.prod.yaml logs -f app worker-critical`.

## Requirements

- A Linux host with Docker Engine and the Compose v2 plugin (the smoke test runs on GitHub's `ubuntu-24.04`
  runner; Compose 5.5 was used locally). 2 vCPU and 4 GB of memory as a minimum.
- DNS `A`/`AAAA` records for `APP_DOMAIN` pointing at the host, and ports 80 and 443 (TCP, plus 443/UDP) open. Caddy
  obtains the certificate on first start (Let's Encrypt, with ZeroSSL as fallback).
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
recreate the PHP services: `docker compose -f deploy/compose.prod.yaml up -d --force-recreate app worker-critical
worker-notifications worker-default scheduler`.

## Updating

Until blue-green deploys arrive, an update replaces the containers in place:

```bash
git pull                                              # or check out the release tag
docker compose -f deploy/compose.prod.yaml build      # or set APP_IMAGE to the new digest
docker compose -f deploy/compose.prod.yaml up -d --wait
```

`migrate` runs the new migrations before the new app and workers start; the web container is unavailable for the
seconds its health check takes, and workers finish their current job first (stop grace). Back up before an update
that contains migrations, and read the changelog's upgrade notes.

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

- **Database**, nightly and before every update, from host cron (before the 03:00 automation run):

    ```bash
    docker compose -f /srv/billing/deploy/compose.prod.yaml exec -T postgres \
      sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > /backups/billing-$(date +%F).dump
    ```

- **Objects** (invoice PDFs, attachments, exports, brand assets): copy the `mohar_rustfs-data` volume, or point
  `OBJECTS_*` at a managed bucket with versioning or replication. A copy of the volume with the pinned PostgreSQL
  image (it has `tar`), while the stack runs:

    ```bash
    docker run --rm -v mohar_rustfs-data:/data:ro -v /backups:/backup \
      postgres:18.6-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873 \
      tar czf /backup/objects-$(date +%F).tgz -C /data .
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
docker run --rm -v mohar_rustfs-data:/data -v /backups:/backup \
  postgres:18.6-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873 \
  tar xzf /backup/objects-2026-10-19.tgz -C /data
docker compose -f deploy/compose.prod.yaml up -d --wait
```

Use the same `APP_KEY` as the backup, then sign in and open an invoice PDF to confirm the bucket.

## Troubleshooting

| Symptom                                   | Look at                                                                                                                                       |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `up` stops at `migrate`                   | `docker compose -f deploy/compose.prod.yaml logs migrate`. "No full administrator was created" means step 4 of the first install was skipped  |
| 502 from Caddy                            | `docker compose ... ps`: the app must be `healthy`; `logs app` shows why it is not                                                            |
| No HSTS, or the activity log shows one IP | the app does not trust the proxy: `EDGE_SUBNET` must be the edge network's range (`docker network inspect mohar_edge`)                        |
| Certificate errors                        | DNS for `APP_DOMAIN` and ports 80/443 from the internet; `logs caddy`. Keep the `caddy-data` volume, or every start requests new certificates |
| Jobs not processed                        | `logs worker-default` (or the pool that carries the queue); the dashboard's automation summary shows the scheduler heartbeat                  |
| `Read-only file system` errors            | something writes outside `storage/`, `/tmp` or `bootstrap/cache`: report it as a bug                                                          |

## Traefik instead of Caddy

Caddy is the default (owner decision, 2026-10-19). Traefik fits a host that runs many compose projects routed by
container labels. To use it, replace the `caddy` service and give `app` labels. Pin the Traefik image as
`traefik:<v3 tag>@sha256:<digest>`, at least 7 days old, when you adopt it. Traefik reads the Docker API, so mount
the socket read-only (or put a socket proxy in front of it).

```yaml
services:
    traefik:
        image: traefik:<v3 tag>@sha256:<digest>
        command:
            - --providers.docker=true
            - --providers.docker.exposedbydefault=false
            - --providers.docker.network=mohar_edge
            - --entryPoints.web.address=:80
            - --entryPoints.web.http.redirections.entryPoint.to=websecure
            - --entryPoints.web.http.redirections.entryPoint.scheme=https
            - --entryPoints.websecure.address=:443
            - --certificatesresolvers.le.acme.email=ops@example.com
            - --certificatesresolvers.le.acme.storage=/letsencrypt/acme.json
            - --certificatesresolvers.le.acme.tlschallenge=true
        ports: ['80:80', '443:443']
        volumes:
            - /var/run/docker.sock:/var/run/docker.sock:ro
            - traefik-acme:/letsencrypt
        security_opt: ['no-new-privileges:true']
        networks: [edge]

    app:
        labels:
            - traefik.enable=true
            - traefik.http.routers.billing.rule=Host(`billing.example.com`)
            - traefik.http.routers.billing.entrypoints=websecure
            - traefik.http.routers.billing.tls.certresolver=le
            - traefik.http.services.billing.loadbalancer.server.port=8080
```

`TRUSTED_PROXIES` stays the edge range, which now holds Traefik. This variant is documented, not tested by the
smoke workflow.
