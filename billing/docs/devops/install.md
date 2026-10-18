# Production install

How to install the billing app on a new server, from an empty database to a signed-in administrator, and how to
upgrade it later. For a development machine with demo data, use [local-setup.md](local-setup.md) instead.

The install is a CLI step, `php artisan app:install`. A web installer is not part of v1.

## What you need

| Component      | Version / notes                                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------------------------------- |
| PHP            | 8.4 with `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `dom`, `zlib`; php-fpm behind nginx (or another web server) |
| Composer       | 2.10.x                                                                                                                    |
| Node / npm     | the version in `billing/.nvmrc` (24.21.0), only to build the front-end assets                                             |
| PostgreSQL     | 18 (CI runs on `postgres:18.6-alpine`). SQLite is for development only                                                    |
| Object storage | any S3-compatible bucket for the `objects` disk (RustFS, AWS S3, MinIO)                                                   |
| Mail           | an SMTP account or a supported mail API. Security notices, invoices and password resets depend on it                      |
| Processes      | cron for the scheduler, and systemd or Supervisor to keep the queue workers running                                       |

## 1. Infrastructure

Pick one of the two options.

**Docker Compose on the app host.** `billing/compose.yaml` runs PostgreSQL and RustFS bound to `127.0.0.1`, with
data in the `postgres-data` and `rustfs-data` volumes. Set strong values for `DB_PASSWORD`, `OBJECTS_ACCESS_KEY` and
`OBJECTS_SECRET_KEY` in `.env` **before** the first `docker compose up -d --wait`: PostgreSQL reads them only when its
volume is created. The compose file also creates an empty `billing_test` database for the test suite, which
production does not use. The file is written for local infrastructure: it also turns on the RustFS web console
(`RUSTFS_CONSOLE_ENABLE`, port 9001 on `127.0.0.1`). In production set that to `'false'` or keep the port off any
public interface. There is no application image yet, so the app itself runs on the host (step 2).

**Bare metal or managed services.** Create a PostgreSQL database and a user that owns it, and a private bucket
with an access key limited to that bucket. Put the connection details in `.env` (step 3).

## 2. Code and assets

Deploy a tagged release from `main` (or the commit you are promoting) into the app directory, then:

```bash
cd billing
composer install --no-dev --optimize-autoloader
npm ci && npm run build        # writes public/build; Node is not needed at runtime
```

The app runs without the dev dependencies: the install path uses no factories or Faker. The PHP user (for php-fpm and
the workers) must be able to write `storage/` and `bootstrap/cache/`; everything else can stay read-only.

## 3. Environment

Start from `.env.example` and change at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billing.example.com
APP_NAME="Your company"            # the brand name until it is set in Admin → Settings → Company

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=billing
DB_USERNAME=billing
DB_PASSWORD=change-me

QUEUE_CONNECTION=database          # or redis
CACHE_STORE=database               # or redis
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp                   # plus MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS

OBJECTS_DRIVER=s3
OBJECTS_ENDPOINT=https://s3.example.com
OBJECTS_ACCESS_KEY=...
OBJECTS_SECRET_KEY=...
OBJECTS_BUCKET=billing
OBJECTS_REGION=us-east-1
```

`APP_URL` must be the exact public address clients use, with `https://` and no trailing slash. Outside local
development every generated link (password resets, email verification, signed download links, queued mail, the
install's next steps) is built from `APP_URL`, never from the request's `Host` header, and requests for any host other
than `APP_URL`'s host and its subdomains are rejected. A wrong value sends people to the wrong site, and an `http://`
value produces insecure links. When the domain changes, update `APP_URL` and run `php artisan optimize` again. The
install pre-flight warns in production when `APP_URL` is not an `https://` address or points at localhost.

Then generate the application key **once**:

```bash
php artisan key:generate
```

Back up `.env`. `APP_KEY` encrypts stored secrets (gateway, registrar and server credentials, secret settings); if
it is lost they cannot be decrypted. When you rotate it, keep the old one in `APP_PREVIOUS_KEYS`.

## 4. Database, storage and the install command

```bash
php artisan migrate --force
php artisan objects:ensure-bucket   # creates the bucket if the key may; otherwise create it yourself
php artisan app:install
```

`app:install` runs in three stages and prints what it did:

1. **Pre-flight checks.** A missing or invalid `APP_KEY`, an unreachable database or pending migrations stop the
   install before anything is written, and the message says which command fixes it. A `sync` or `null` queue, no
   scheduler heartbeat yet, an object disk that rejects a test file, and in production `APP_DEBUG=true` or a `log`
   or `array` mailer are reported as warnings; the install goes on.
2. **Reference data** from every installed module, each in its own transaction:

    | Installer         | Creates                                                                                                                                                                                                    |
    | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | `core_currencies` | USD (the default), INR and NPR with their symbols, formats and starting rates, on the first install only (while no currency exists). Later runs never re-create a deleted currency or change an edited one |
    | `core_roles`      | the system roles Full Administrator, Billing, Support and Sales, found by a stable key and reset to their built-in permissions (names staff chose are kept)                                                |

    Settings, the daily automation time, theme presets and staff permissions need no rows: they are built-in defaults
    in code and configuration until staff change them. Support departments are not created, because each needs a
    real mailbox address. Modules that need reference rows add an installer (see
    [architecture.md](../architecture.md), "Reference data"); the email templates will arrive this way with the
    Notifications module.

3. **The first full administrator.**
    - In a terminal, it asks for a name, an email address and a password. The password must have at least 12
      characters with upper- and lower-case letters, a number and a symbol, and must not appear in a known breach
      (Have I Been Pwned range API; if the server cannot reach it, that part of the check is skipped). These are the
      production rules in every environment.
    - `php artisan app:install --admin-email=you@example.com [--admin-name="Your Name"]` asks nothing and prints a
      generated one-time password once. It is not stored in plain text or logged; sign-in with it forces a new
      password. Run it in a terminal you trust, not in a CI job whose log others can read.
    - Two-factor setup is required at the first sign-in to the admin area (`security.staff_2fa_required`, on by
      default).
    - If a full administrator already exists, it creates none. Pass `--additional-admin` to add one. It never promotes
      an existing account: use Admin → Staff for that.

The command exits non-zero when a check fails or no administrator could be created, so scripts can stop on it.

## 5. Web server

Point the document root at `billing/public` and send every request that is not a file to `index.php` (the standard
Laravel nginx configuration). Serve HTTPS only. If a load balancer or reverse proxy terminates TLS, configure trusted
proxies as described in [local-setup.md](local-setup.md#reverse-proxies-and-trustproxies).

Cache the configuration, routes, views and events after every deploy:

```bash
php artisan optimize
```

With a cached configuration, `.env` is read only by `php artisan optimize`: run it again after editing `.env`.

## 6. Scheduler and queue workers

Cron, as the user that runs PHP:

```cron
* * * * * cd /srv/billing/billing && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler writes a heartbeat every minute and runs the daily automation pipeline at `core.automation_time`
(03:00 server time by default). The admin dashboard flags automation that has not run for 26 hours.

Queue workers, kept running by systemd or Supervisor (the queue names follow ADR 0014):

```bash
php artisan queue:work --queue=payments,provisioning,registrar --tries=3 --timeout=200
php artisan queue:work --queue=notifications,webhooks --tries=3
php artisan queue:work --queue=default --tries=3
```

The pools follow [ADR 0014](../adr/0014-observability-and-queue-topology.md), which is still proposed. The first pool
calls gateways, control panels and registrars. Module jobs time out after `services.module.job_timeout_seconds` (60 s),
or after the driver's declared command timeout up to `services.module.max_job_timeout_seconds` (180 s); the pool's
`--timeout` (200 s) must be at least that cap plus the 20 s lock slack, and below the queue connection's `retry_after`
(240 s by default, `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER`), or a slow job is started twice. On SQS, set
the visibility timeout of each queue the same way. `--tries=3` is only the fallback for jobs that do not set their own `$tries`.

A minimal systemd unit for one pool:

```ini
[Unit]
Description=billing queue worker (notifications, webhooks)
After=network.target

[Service]
User=www-data
WorkingDirectory=/srv/billing/billing
ExecStart=/usr/bin/php artisan queue:work --queue=notifications,webhooks --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

`--max-time` restarts the worker hourly, so memory does not build up; `Restart=always` brings it back.

## 7. First sign-in

1. Open `APP_URL/login` and sign in as the administrator, change the one-time password if you used one, and set
   up two-factor authentication.
2. Admin → Settings → Company: company name (the brand name everywhere), billing email address, default country and
   date format.
3. Admin → Setup → Currencies: review the starting exchange rates.
4. Work through the go-live checklist in [security/standards.md](../security/standards.md#go-live-checklist).

## Backups

Three things, kept apart, and a restore you have actually tried.

1. **The database.** A compressed custom-format dump at least daily, for example from cron at 02:30 (before the
   03:00 automation run):

    ```bash
    pg_dump --format=custom --no-owner --file=/var/backups/billing/billing-$(date +%F).dump \
      "postgresql://billing@127.0.0.1:5432/billing"
    find /var/backups/billing -name 'billing-*.dump' -mtime +30 -delete   # keep 30 days
    ```

    Give `pg_dump` its password through `~/.pgpass` (mode 600), not the command line. Copy the dumps off the server
    (another region or provider). Keep weekly or monthly dumps longer if your accounting rules require it. A managed
    PostgreSQL service with point-in-time recovery covers this, but still take a periodic logical dump.

2. **The objects bucket.** Invoice PDFs, ticket attachments, exports and brand assets live only there. Turn on bucket
   versioning or replication to a second bucket, or sync it to another provider on a schedule (for example
   `rclone sync`). A database restore without the bucket leaves links to files that no longer exist.

3. **`APP_KEY` (and `.env`).** Store the key in a password manager or secrets store, **separately from the database
   dumps**. Without it, every encrypted value in a dump (gateway, registrar and server credentials, secret
   settings) is unrecoverable. And a dump stored together with the key gives whoever reads it all of those
   credentials.

**Restore test.** At least once before go-live, and then every quarter, restore the latest dump into a scratch
database (`createdb billing_restore && pg_restore --no-owner --dbname=billing_restore <file>`). Point a copy of the
app at it with the same `APP_KEY` and run `php artisan app:install --no-interaction`: the pre-flight must pass and
every reference row must report as unchanged. Then sign in and open an invoice PDF to check the bucket.

## Upgrading

```bash
php artisan down
# take a database dump first (see Backups): a migration cannot always be rolled back
# deploy the new release, then:
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear                 # drop the previous release's cached configuration and routes
php artisan migrate --force
php artisan app:install --no-interaction   # adds new reference data and resets the system roles
php artisan optimize
php artisan queue:restart                  # workers pick up the new code after their current job
php artisan up
```

Re-running `app:install` is safe: on an unchanged database it changes nothing and reports every row as unchanged.
It adds reference data a new release introduces and resets the permissions of the four system roles, so a permission
added in the release reaches them. The system roles are found by a stable key, so a name staff gave one is kept, and a
custom role is never changed, even one named like a system role. Each reset is in the activity log
(`role.updated`). Permission changes staff made to a system role are lost: copy it into a custom role if you need
different permissions. Currencies are never re-created or changed after the first install.

## Demo data

`php artisan db:seed` installs the same reference data through the same installers, then, outside production only,
the demo seeders (admin@example.com and demo@example.com with the password `password`, demo clients, products,
invoices and tickets). In production `db:seed --force` installs the reference data and skips the demo, and a demo
seeder called directly (`db:seed --class=...`) refuses to run. A test compares the demo seed's reference rows with
the install's, so the two cannot drift.

## Troubleshooting

| Message                                                    | Fix                                                                                      |
| ---------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `APP_KEY is not set`                                       | `php artisan key:generate`, then `php artisan optimize` if the configuration is cached   |
| `Database: Cannot connect`                                 | check the `DB_*` values, that PostgreSQL is running and that the user can connect        |
| `Migrations: ... pending`                                  | `php artisan migrate --force`                                                            |
| `Object storage: ... not writable`                         | `php artisan objects:ensure-bucket`, then check the `OBJECTS_*` values and bucket policy |
| `A full administrator already exists, so none was created` | expected on a re-run; pass `--additional-admin` to add another                           |
