# Load testing

Measures how much a single Docker host running the production stack (`deploy/compose.prod.yaml`) can serve, and
how long the background work takes, at the volume of a mid-sized host: 50,000 clients and 100,000 services with a
year of invoices, payments and tickets. The results feed the sizing guide, `billing/docs/devops/sizing.md`.

Runs only on a GitHub-hosted runner, started by hand. The codespace blocks networking between containers, so the
stack cannot run there.

## Run it

- **On a pull request:** add the `load-test` label. It runs the jobs listed in the PR's `loadtest/label-runs.json`,
  one runner each, in parallel: up to three runs, each with a `name`, `profile` (small or medium; large does not fit
  the standard runner), and optionally `volume` (default large), `levels`, `level_duration`, `start_spread`, `warmup`
  and `think`. `loadtest/bin/plan.py` checks every value against a fixed pattern first; the runner (`ubuntu-24.04`)
  and the rule that skips a profile larger than the runner come from the workflow, not the file. Remove and re-add the
  label to run again. Only people with triage access can add a label.
- **Any profile or volume:** Actions → Load test → Run workflow. This is available once the workflow is on the
  default branch. Inputs: `profile` (small, medium, large or all), `volume` (tiny, small, medium, large), `levels`
  (virtual users per step, for example `5 10 20`), `level_duration` (for example `90s`), `runner` (a runner label)
  and `allow_smaller_runner`.
- **On any Linux host with Docker Compose v2 and passwordless sudo:**
  `loadtest/bin/run --profile small --volume large`. Results go to `loadtest/results/<run>/`. Without sudo the
  whole-stack cap is skipped and the summary says so. `--think "10 30"` sets every pause between pages to 10–30 s
  (people who read each page); `--no-stop` runs every level even past saturation.

Each job uploads an artifact named `load-test-<name>-<volume>-<run id>`, where `<name>` is the run's `name` in
`loadtest/label-runs.json` or, for a dispatch, the profile. It holds `summary.md` (also shown on the
run page) and `summary.json`, plus the raw files: k6 summaries per level, `docker-stats.ndjson`, the slowest
PostgreSQL queries, the PDF bench, the automation log and the queue drain. It never holds secrets: generated keys and
passwords stay in a temporary directory that is deleted at the end. To compare several runs, download their
artifacts and run `python3 loadtest/bin/summarize.py --combine <out> <run dir> ...`.

The standard `ubuntu-24.04` runner has 4 vCPUs and 16 GB of memory for a public repository and 2 vCPUs and 8 GB for a
private one (GitHub docs, "GitHub-hosted runners"). This repository is public: the run of 2026-10-20 saw 4 CPUs and
15,983 MB, which fits the small and medium profiles but not large (8 vCPU, 16 GB). By default the workflow skips a
profile larger than the runner, because the numbers would describe the runner, not the profile. To measure large, pass
a larger runner label in `runner`. GitHub's larger runners are offered to organisations on the Team or Enterprise
Cloud plans, so that needs either one of those or a self-hosted runner.

## What a run does (`bin/run`)

1. **Caps the host.** All containers except k6 get one cgroup parent: a systemd slice with `CPUQuota` and
   `MemoryMax` set to the profile's totals (or a cgroup v2 directory with the cgroupfs driver). Together they behave
   like a VPS of that size. Each service also has its own limit from `profiles/<profile>.env`. Docker refuses a CPU
   limit above the host's CPU count, so a limit above it is lowered and reported.
2. **Builds the image** (`deploy/Dockerfile`, target `prod`). It then installs the app on fresh PostgreSQL and
   RustFS (migrations, bucket, reference data, first administrator), as `deploy/bin/smoke` does.
3. **Seeds** with `seed/run.php` in a one-off container with `APP_ENV=loadtest`. The rest of the stack runs with
   `APP_ENV=production`.
4. **Starts the stack**: one colour (blue) of the blue-green production file, through its compose profile, behind
   Caddy with an internal certificate and the upstream file `deploy/bin/deploy` writes for blue. Then it warms up for
   60 seconds. The warm-up fills OPcache, PostgreSQL buffers and compiled views.
5. **Web load** (`k6/main.js`): one k6 run per step of `LOAD_LEVELS`, each for `LEVEL_DURATION`, with `docker stats`
   sampled every few seconds and `pg_stat_statements` collected. **Stop at saturation:** once a level is past the
   comfortable limit (p95 of all requests 1 s or more, or 1% errors or more), the run does one more level so the knee
   shows, then skips the rest. The summary names the last comfortable and the first failing level, with the per-page
   table and the CPU of the app, PostgreSQL and k6 at both.
6. **Background work.** The PDF bench comes first. Then the workers stop and today's `automation:run` runs in the
   scheduler container. When it finishes, the workers start again and the run times how long the queue takes to
   drain. The provisioning hook comes last.
7. **Summarises** with `bin/summarize.py` and tears everything down. With `--keep`, the stack stays up and the
   command that removes it is printed.

## Seed data (`seed/`)

`run.php --volume=tiny|small|medium|large` (or `--clients=N --services=M`) adds:

| volume | clients | services | staff | invoices         |
| ------ | ------: | -------: | ----: | ---------------: |
| tiny   |     200 |      400 |     2 |            1,300 |
| small  |   5,000 |   10,000 |     5 |           35,000 |
| medium |  20,000 |   40,000 |    10 |    about 140,000 |
| large  |  50,000 |  100,000 |    20 |          359,665 |

Tiny and small were measured on 2026-10-19 and large on the runner run of 2026-10-21 (run 39472898451); medium has
not been seeded yet, so its count is an estimate from small.

- **Clients:** 60% USD (US, UK, Canada, Australia, Germany), 25% INR, 15% NPR. Each client has one login,
  `client<n>@loadtest.example.com`. Staff log in as `staff<n>@loadtest.example.com`. Every login uses the run's
  generated password, `LOADTEST_PASSWORD`.
- **Services:** the demo catalogue's products, priced in the client's currency. Billing cycles are weighted
  towards monthly (50%) and annually (30%). Services were registered over the last three years, with more of them
  recent. 25% of recurring services renew on the period that today's automation run invoices (`--peak`), so the
  measured run is a billing day rather than an average day.
- **Invoices:** for each service, every period whose invoice falls in the last year. That is one service per
  invoice, as ADR 0008 has it, and the first one is an order invoice. Most are paid on time, some late. About 6% of
  those due in the last 60 days are still unpaid, and their services are suspended once 8 or more days overdue.
  2.5% of older ones were cancelled, and their services terminated.
- **Tickets:** about one ticket for every two clients over the year, each with a client and staff conversation.
  Older tickets are closed.

The seeder writes multi-row INSERTs instead of going through the Billing actions. The small volume took 10.5 seconds
on SQLite in the codespace; each run's summary records the seeding time. Because it skips the actions, it keeps their
invariants itself:

- invoice numbers follow issue dates with no gaps, in the configured format, and the sequence row points past the
  last one;
- money is integer minor units in the client's currency, and every paid invoice has its payment transaction;
- each line has the `period_guard` that IssueInvoice writes, and each invoice has its service link;
- `next_due_date` and `next_invoice_date` are what the renewal code expects;
- reminders and overdue markers are what the earlier daily runs would have left.

`run.php --verify` checks all of this, and every seed runs it. `run.php --self-test` checks the date arithmetic
against `BillingCycle::advance()`.

It refuses `APP_ENV=production`, because it creates logins with a shared password. It also refuses a database that
already has load-test data. For a quick check on the local SQLite database, from `billing/` in a worktree:
`php artisan migrate:fresh && LOADTEST_PASSWORD=... php ../loadtest/seed/run.php --app=. --volume=tiny`.

Two settings change for the load test: staff two-factor enrolment is off (`security.staff_2fa_required`), so k6 can
sign staff in with a password, and the gateways `bank_transfer` and `manual` are added.

## Load scenarios (`k6/`)

k6 runs as the pinned `grafana/k6` image from `compose.loadtest.yaml`. Like every image, it is pinned by digest and at
least 7 days old, and `composer deps:check` verifies it. The mix of virtual users at each step:

| share | scenario     | what a virtual user does                                                                                                                                                     |
| ----: | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
|   50% | `client`     | signs in, then opens the dashboard, invoices, one invoice (and its PDF, 10% of the time), services, one service, tickets and one ticket                                    |
|   30% | `storefront` | as a new anonymous visitor: home, store, one product, the knowledge base or announcements; then signed in: adds a hosting plan to the cart, views the cart, and either removes it or checks out with bank transfer (20%) |
|   10% | `admin`      | as staff: dashboard, clients, a client search, one client, unpaid invoices and one invoice, services and one service, tickets and one ticket, transactions or orders       |
|   10% | `api`        | the JSON endpoints the UI calls: admin global search, the client picker, and the storefront price preview. There is no public REST API yet.                                   |

Each virtual user loads its first page as full HTML and then navigates with Inertia JSON requests, as a browser
does. Think time is 1 to 3 seconds per page, so a virtual user is a fast, busy person; someone who reads each page
takes 10–30 s, which `--think "10 30"` (k6 `THINK_MIN` and `THINK_MAX`) models by replacing every pause with that
range.

Each virtual user arrives at a random moment in the first 20 seconds of a level (`--start-spread`, 0 for all at once;
it must be shorter than the level and the warm-up),
then signs in once. Before issue #205 they all started together, and that burst of sign-ins on the 2 vCPU host made
most of the multi-second p95 the runner reported for the dashboards, the store and sign-in (`sizing.md`).

Each request is tagged with its page, so the summary has p50, p95 and p99 per page. Errors are non-2xx answers (or
non-redirects for forms), and the summary's page tables list them by class (429, 4xx, 5xx, none for no answer).
Answers of 429 from a rate limiter on the pages marked `limited` (the JSON endpoints, and the anonymous knowledge base
and announcements) are counted separately, not as errors: all virtual users share the load generator's single IP
address, which a real crowd would not.

## Profiles (`profiles/`)

| profile | host             | app (PHP threads) | workers (critical, notifications, default) | PostgreSQL (shared_buffers)  | levels (VUs) |
| ------- | ---------------- | ----------------- | ------------------------------------------ | ---------------------------- | ------------ |
| small   | 2 vCPU, 4 GB     | 1,280 MB (4)      | 1, 1, 1 × 256 MB                           | 1,024 MB (256 MB)            | 5 10 20 40   |
| medium  | 4 vCPU, 8 GB     | 2,560 MB (8)      | 1, 2, 1 × 320 MB                           | 2,560 MB (640 MB)            | 10 20 40 80  |
| large   | 8 vCPU, 16 GB    | 5,120 MB (16)     | 2, 3, 2 × 320 MB                           | 6,144 MB (1,536 MB)          | 20 40 80 160 |

The per-service memory limits add up to 90–97% of the host's total (97% on small, whose scheduler has production's 512 MB). CPU limits are ceilings within the total,
not reservations. `loadtest/frankenphp/Caddyfile` is the production FrankenPHP Caddyfile plus `num_threads` from the
profile: two threads per vCPU, which is what FrankenPHP starts by default on a VPS of that size.

## Not covered yet

- **Provisioning against the WHM simulator.** `hooks/provisioning.sh` records "skipped" until simulator S2 is merged,
  and its comment describes what to add.
- **Web load during the automation run.** They are measured one after the other.
- **Redis.** The stack uses database-backed queues, cache and sessions, as `deploy/compose.prod.yaml` does.
