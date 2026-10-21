# cPanel shared hosting ("cron mode")

How to run the app on a cPanel shared account: no Docker, no long-running processes, the scheduler and the queue
workers started by cron every minute. It works, with the limits listed below; a VPS with Docker
([production.md](production.md)) or with root ([install.md](install.md)) is the better home. Why this is a supported
fallback and not the default: [deployment-options.md](deployment-options.md#cpanel-shared-hosting).

## What the account needs

| Need              | Details                                                                                                                                                                                      |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP 8.4           | EasyApache's `ea-php84`, with `pdo_pgsql`, `pgsql`, `intl`, `gd`, `bcmath`, `zip`, `mbstring`, `openssl`, `curl`. Ask the host to install the missing ones. `pcntl` is optional (see Limits) |
| PostgreSQL 18     | a database the account can reach over the network: a managed PostgreSQL 18, or one the host provides. cPanel's own PostgreSQL is the OS version, usually far older                           |
| Cron every minute | cPanel → Cron Jobs → "Once Per Minute". Hosts that only allow every 5 or 15 minutes delay jobs by that much and need the two settings under Limits (Scheduler)                               |
| A terminal        | SSH or cPanel → Terminal, for the install and upgrade commands                                                                                                                               |
| `flock`           | usually present (`util-linux`); without it, drop the `flock -n …` part of each cron line                                                                                                     |
| Document root     | the domain's document root must point at `~/billing/public` (cPanel → Domains → Manage)                                                                                                      |
| Object storage    | any S3-compatible bucket, or `OBJECTS_DRIVER=local` (files in `storage/app/objects`, which the account backup must then include)                                                             |

## Limits compared with the Docker stack

| Topic             | In cron mode                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Queued jobs       | started within a minute instead of at once: provisioning, emails and webhooks are delayed, never lost                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Job timeouts      | without `pcntl`, `--timeout` does not apply: a hung driver call blocks its pool until PHP's own limit stops it                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Scheduler         | The installer's Scheduler check warns when the heartbeat is older than 5 minutes, so a 5-minute cron shows a flapping warning and a 15-minute cron a permanent one; ignore it, the check does not know the cron interval. `schedule:run` has no catch-up: a daily entry (`dailyAt`) runs only when a cron tick lands on its exact minute. With a cron that is not every minute, set the **automation time** (Admin → Automation) and the separate exchange-rate refresh time (`platform.rates_update_time`, empty by default) to a multiple of the cron interval, such as `03:00` (the default) for an hourly, 5 or 15 minute cron. The minute is compared in the company time zone (ADR 0013): in a zone with a 45-minute offset such as Nepal's, an hourly cron ticks at :45 there, so choose `03:45`; any other time is skipped every day without an error, and invoicing and renewals stop. |
| Upgrades          | a few minutes in maintenance mode: no blue-green                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Isolation         | a shared PHP user, a writable code directory and no resource limits: a weaker security posture than the containers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Composer and Node | not needed: the release tarball includes `vendor/` and the built front end                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

## Install

1. **Get the release tarball.** The "Build image" workflow (Actions → Build image → Run workflow) produces it with
   every image build, as the artifact `release-<commit>` (`release-<commit>.tar.gz` and its `.sha256`). Check it:

    ```bash
    sha256sum -c release-<commit>.tar.gz.sha256
    ```

    The checksum proves the upload arrived intact, nothing more: it sits beside the tarball it describes, and two
    builds of one commit give different tarballs (file times and ownership are inside), so it is not a signature or a
    reproducible-build hash. Downloading the artifact needs a signed-in GitHub account with access to the repository
    (or `gh run download <run-id> -n release-<commit>`), and it expires after 7 days.

2. **Upload and extract it** into the home directory, which gives `~/billing`:

    ```bash
    tar -xzf release-<commit>.tar.gz -C "$HOME"
    ```

    Point the domain's document root at `~/billing/public`.

3. **Settings.** Create `~/billing/.env` from `~/billing/.env.example`, readable only by the account:

    ```bash
    cd ~/billing && cp .env.example .env && chmod 600 .env
    ```

    Set at least:

    ```dotenv
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://billing.example.com
    DB_CONNECTION=pgsql
    DB_HOST=…  DB_PORT=5432  DB_DATABASE=…  DB_USERNAME=…  DB_PASSWORD=…
    QUEUE_CONNECTION=database
    CACHE_STORE=database
    SESSION_DRIVER=database
    OBJECTS_DRIVER=local           # or s3 with the OBJECTS_* block
    MAIL_MAILER=smtp               # and the MAIL_* block
    LOG_CHANNEL=single
    ```

    Leave `TRUSTED_PROXIES` unset: on a shared host Apache answers directly, there is no proxy to trust.

4. **Key, database, administrator, caches**, in the terminal (`php` is EasyApache's PHP 8.4; on most hosts
   `/opt/cpanel/ea-php84/root/usr/bin/php`):

    ```bash
    php=/opt/cpanel/ea-php84/root/usr/bin/php
    cd ~/billing
    $php artisan key:generate --force
    $php artisan migrate --force
    $php artisan objects:ensure-bucket      # only with OBJECTS_DRIVER=s3
    $php artisan app:install                # asks for the first administrator
    $php artisan optimize
    ```

    Keep a copy of `APP_KEY` in a password manager, away from the backups: it decrypts the stored gateway, registrar
    and server credentials.

5. **Cron.** Add the five lines of [`deploy/cpanel/crontab.example`](../../../deploy/cpanel/crontab.example) as
   "Once Per Minute" jobs, or with `crontab -e`: the scheduler, one short-lived worker per ADR 0014 pool, and the
   automation worker that runs **Run now**. Each worker stops when its queue is empty or after 55 s; `flock -n`
   keeps a pool from running twice while a long job is still busy. The automation worker uses its own `automation`
   connection (the `queue:work automation` argument matters, see [install.md §6](install.md#6-scheduler-and-queue-workers)).

6. **Check.** Open `https://<domain>/up`, then sign in. After a minute, the dashboard's automation summary shows a
   scheduler heartbeat.

## Upgrading

```bash
php=/opt/cpanel/ea-php84/root/usr/bin/php
cd ~ && $php billing/artisan down
mkdir -p release-new && tar -xzf release-<new commit>.tar.gz -C release-new   # gives ~/release-new/billing
mv billing/.env release-new/billing/.env
rm -rf release-new/billing/storage && mv billing/storage release-new/billing/storage
mv billing billing.previous && mv release-new/billing billing && rmdir release-new
cd ~/billing && $php artisan migrate --force && $php artisan app:install --no-interaction && $php artisan optimize
$php artisan up
```

`storage/` (uploads, local objects, logs) and `.env` move into the new release; the old code stays in
`~/billing.previous`. The cron lines need no change: the next minute's workers start from the new code. To roll
back, swap the folders back the same way (move `.env` and `storage/` again) and run `optimize`: the migration rule
([architecture.md](../architecture.md#migrations-during-blue-green-deploys-expand-then-contract)) keeps the previous
release working against the new schema.

## Backups

- **Database:** with the provider's backups, or `pg_dump --format=custom` from a machine that can reach it
  ([install.md](install.md#backups)).
- **Files:** `~/billing/storage` (and `storage/app/objects` with `OBJECTS_DRIVER=local`) in the cPanel account
  backup, or the bucket's own versioning or replication.
- **`.env`** with `APP_KEY` in a secrets store, never next to the dumps.

## How cron mode is tested

`deploy/cpanel/simulate-cron` runs the guide without a cPanel account: it builds the release tarball
(`docker build --target release`), extracts it into a throwaway home directory, writes `.env` as step 3 says, runs
the step 4 commands in PHP 8.4 with the app's extensions, serves `public/` with PHP's built-in server (standing in
for Apache's document root), then runs every line of `crontab.example` the way cron does, once a minute:

```bash
eval "$(scripts/pg.sh cron-sim)"    # any reachable PostgreSQL 18
deploy/cpanel/simulate-cron         # CRON_TICKS=3 by default
```

It checks the tarball (`vendor/`, `public/build`), `/up` and `/login`, that `flock` skips a second start of a busy
pool without taking a job, that one queued job per pool (automation, default, notifications) is processed with no
failed job, that each tick finishes inside its minute, and that the scheduler's heartbeat is recent.

Last run (2026-10-20, PHP 8.4.26 with `pcntl`, PostgreSQL 18.6): the 19 MB tarball extracted to 164 MB with
`vendor/` and `public/build`; install, `/up` and `/login` passed; `flock` skipped a second start of the default pool;
the three ticks took 6 s, 4 s and 4 s, with one job still pending after the first and none after the second; all
three jobs processed, `failed_jobs` 0, heartbeat 5 s old.
