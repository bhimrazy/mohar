# Sizing guide

How much a single Docker host running the production stack ([production.md](production.md)) can serve, measured
with the load-test harness in `loadtest/` (`loadtest/README.md`). Every number here comes from a posted run; the
guide says where each one is from and what has not been measured yet. Hosts in Nepal are mostly small, so the
small-host guidance comes first.

Sources:

- the GitHub runner run of 2026-10-21, [actions/runs/39472898451](https://github.com/bhimrazy/mohar/actions/runs/39472898451),
  reported in the [results comment on PR #108](https://github.com/bhimrazy/mohar/pull/108#issuecomment-6308776905);
- the codespace re-measure of 2026-10-20, reported in the
  [earlier comment on PR #108](https://github.com/bhimrazy/mohar/pull/108#issuecomment-6297654724);
- the restore drill of 2026-10-22 (production.md, "Restore drill"), which ran the full production stack, including a
  blue-green deploy, on a Docker Desktop VM with 3.6 GB.

## Short answer

**Minimum and recommended: 2 vCPU and 4 GB**, for the whole production stack on one host
([production.md](production.md)), up to the 50,000 clients measured. Memory is not what limits it (see "Memory"
below); the tail latency at 40 active users is, and the first step when that matters is more vCPU (the medium
profile, 4 vCPU and 8 GB, which has not been measured). Every doc states these numbers: production.md,
[install.md](install.md), [deployment-options.md](deployment-options.md) and the docs site's requirements page.

| Host                                          | Clients      | What the measurements say                                                                                                                                                                                                    |
| --------------------------------------------- | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Small: 2 vCPU, 4 GB** (the profile)         | under 2,000  | Enough, with a wide margin (inference). The small profile was measured at 50,000 clients, 25 times this volume, with 40 active users: median 41 ms per request, no errors.                                                   |
| Small: 2 vCPU, 4 GB                           | 2,000–20,000 | Enough for the pages and the billing day (inference from the 50,000-client run, which is the larger data set). The tail at 40 active users (sign-in, dashboards) is the thing to watch; see "Where the small host runs out". |
| Small: 2 vCPU, 4 GB (minimum and recommended) | 50,000       | Measured. Median 41 ms over all pages; p95 under 200 ms on the searches and lists, p95 of 3–5 s on the dashboards, the storefront and sign-in at 40 active users; billing day 284.5 s.                                       |
| Medium: 4 vCPU, 8 GB                          | any          | Not measured. The next step when the small host's tail latency matters: 8 PHP threads instead of 4. It fits the public repository's runner and can be run from Actions.                                                      |
| Large: 8 vCPU, 16 GB                          | above 50,000 | Not measured. The large profile needs 8 vCPU and the standard GitHub runner has 4, so it needs a larger or self-hosted runner (`loadtest/README.md`).                                                                        |

"Active users" are k6 virtual users: each one loads a page, waits 1–3 seconds, loads the next, so one virtual user is
roughly one person clicking through the site. 40 virtual users produced 17.7 requests per second.

## What was measured and how

- **Host:** a GitHub-hosted `ubuntu-24.04` runner with 4 CPUs, 15,983 MB and Docker 28.0.4. The whole stack ran
  inside a systemd slice capped at the **small profile: 2 vCPU and 4 GB**, with the profile's per-service limits
  (`loadtest/profiles/small.env`): FrankenPHP with **4 PHP threads** behind Caddy, PostgreSQL with
  `shared_buffers=256MB`. k6, the load generator, ran outside the cap.
- **Data:** the large seed volume, **50,000 clients, 100,000 services and 359,665 invoices** with a year of payments
  and tickets, seeded in 115 s; database size 867 MB; all seed invariants held. 25% of the recurring services renew on
  the measured day, so the automation run is a billing day, not an average day.
- **Web load:** four 2-minute levels of **5, 10, 20 and 40 virtual users** with the scenario mix in
  `loadtest/README.md` (client area 50%, storefront 30%, admin 10%, JSON endpoints 10%).
- **Background work:** invoice PDFs from one process, the billing-day `automation:run` with the workers stopped, then
  the queue drain once they start again.
- **Code:** the PR #108 merge commit (`bf97103`), which includes the performance work of #166 (dashboard and search)
  and #189 (renewals). The run took 21 minutes; artifact `load-test-small-large-39472898451`.

Two limits appear in the results. The harness calls a level "comfortable" when the p95 of all requests is under 1 s
with under 1% errors (`loadtest/bin/summarize.py`); that is the line the "fits" verdicts below use. The product
target for a page is p95 under 200 ms, and the per-page table says which pages meet it.

## Results: small profile, 50,000 clients

### Web load per level

| Virtual users | req/s | p50   | p95    | p99    | Errors |
| ------------: | ----: | ----- | ------ | ------ | -----: |
|             5 |   3.5 | 36 ms | 102 ms | 629 ms |      0 |
|            10 |   4.8 | 36 ms | 144 ms | 779 ms |      0 |
|            20 |   9.2 | 37 ms | 188 ms | 1.8 s  |      0 |
|            40 |  17.7 | 41 ms | 424 ms | 4.2 s  |      0 |

No request errors and no 429 answers at any level (two content errors are listed at the end). 40 virtual users was still "comfortable" (p95 under 1 s), so the small
profile was not saturated by its own levels: during the 40-user level the app container averaged 42% of one core
(peak 180%) and PostgreSQL 45%.

### Per page

"Light" is the 5-user level, the closest the harness has to a single request.

| Page                       | Light: p50 / p95 | 40 users: p50 / p95 | p95 under 200 ms at 40 users? |
| -------------------------- | ---------------- | ------------------- | ----------------------------- |
| Admin dashboard            | 166 ms / 583 ms  | 225 ms / 5.1 s      | No                            |
| Admin global search        | 48 ms / 89 ms    | 52 ms / 188 ms      | Yes                           |
| Admin unpaid invoices      | —                | — / 78 ms           | Yes                           |
| Client list search (admin) | 41 ms / 52 ms    | 48 ms / 832 ms      | No (tail)                     |
| Client picker (JSON)       | 28 ms / 32 ms    | 30 ms / 102 ms      | Yes                           |
| Client dashboard           | —                | 41 ms / 3.3 s       | No (tail)                     |
| Client invoices            | 32 ms / 39 ms    | 36 ms / 77 ms       | Yes                           |
| Storefront                 | —                | 28 ms / 4.6 s       | No (tail)                     |
| Sign-in                    | —                | 2.4 s / 5.6 s       | No                            |
| All pages                  | —                | 41 ms / 424 ms      | p99 4.2 s                     |

A dash means the comment did not report that figure.

### Background work

| Measure                           | Result                                                                         |
| --------------------------------- | ------------------------------------------------------------------------------ |
| Billing day (`automation:run`)    | 284.5 s                                                                        |
| Renewals                          | 10,515 in 268 s, 39.3 per second                                               |
| Queue drain after the billing day | 11,525 jobs in 64.3 s (179 per second), 0 failed                               |
| Invoice PDF (dompdf, one process) | p50 104 ms, p95 115 ms, 9.5 renders per second                                 |
| Slowest automation query          | the `email_messages` insert: 11,461 calls, 4.2 s in total                      |
| Renewals loop                     | about 1.1 s per 10,000 updates on each of services, invoices and invoice items |

## Memory

The earlier, larger minimum in production.md came from adding up the compose memory limits (5,888 MB at the peak of
a deploy). Limits are ceilings; the measurements say what the stack uses.

Where the numbers come from: the runner figures are from the load-test stack (`loadtest/compose.loadtest.yaml`, one
colour, capped at 2 CPU and 4 GB by a systemd slice, the small profile's per-service limits), not from
`deploy/compose.prod.yaml`; the drill figure is from `deploy/compose.prod.yaml` itself. **4 GB is the smallest size
tested, not a measured floor**: nothing smaller was run, and no run came near the cap.

| Run                                                                                                                                  | Total memory of all containers (without k6)                                                                                                                                                                                                |
| ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Runner, small profile, 50,000 clients, one colour (artifact `load-test-small-large-39472898451`, `docker-stats.ndjson`, 218 samples) | peak 857 MiB (PDF phase, only 2 samples had all 9 containers); 790 MiB at 40 users; 790 MiB during the queue drain. The billing day (764 MiB) is partial: only 6 of the 9 containers ran (the pools were stopped), so it is not comparable |
| Drill, full production stack, demo data (956 invoices), `deploy/bin/deploy` blue then green, VM of 3.6 GB (3,665 MB by `free -m`)    | peak 691 MiB (total of the containers) with both colours' web containers and pools running, sampled every 3 s                                                                                                                              |

Per service on the runner, the highest value of any sample over all phases (MiB): PostgreSQL 340 (`shared_buffers=256MB`),
the web container 185 (PDF phase), the scheduler 179 (billing day), the three pools and the automation worker 45–74
each, RustFS 80, Caddy 27. The kernel and page cache come on top of that, which is why 4 GB, not 1 GB, is the minimum.

So a 4 GB host runs the stack in steady state with room to spare, and the drill's deploy fitted a 3.6 GB VM although
the limits then added up to more than the VM had. What these two runs do not cover, and so what a 4 GB host has not
been shown to survive: a deploy on the 50,000-client database (the drill's database was 29 MB; the runner's 867 MB
was never deployed over), a deploy that waits for a billing day in progress (both colours' schedulers and automation
workers overlap briefly), the `--memory=256` import jobs of up to 192 MB, and swap behaviour. Until someone runs a
deploy at the large volume, treat deploys on a 4 GB host with a database of that size as unproven and watch
`free -m` during one.

## When to grow

| Sign                                                                                                         | What to do                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Sign-in and dashboards have a multi-second p95 while the median is fine (measured at 40 active users: 3–5 s) | More vCPU first, and the PHP thread count that goes with it: the medium profile (4 vCPU, 8 GB, 8 threads), not yet measured |
| Available memory under about 1 GB during a deploy (`free -m`; a guide, not a measurement)                    | Run `deploy/bin/deploy retire` after each deploy, then move to the medium profile (4 vCPU, 8 GB)                            |
| Over 50,000 clients, or more than 40 people in the admin and client area at once                             | Not measured; run the medium or large profile from Actions → Load test before sizing                                        |

## Where the small host runs out

- **The tail, not the median.** At 40 active users every median stays under 50 ms except the admin dashboard
  (225 ms) and sign-in (2.4 s), but the p99 is 4.2 s and the dashboards and the storefront have a p95 of 3–5 s. Cheap
  pages with a multi-second p95 are requests queueing behind the **4 PHP threads** while sign-ins (bcrypt) and cold
  dashboards occupy them. More threads need more CPU, which is what the medium profile adds; it has not been run.
- **Sign-in** is the slowest page under load, p50 2.4 s at 40 users (bcrypt keeps a PHP thread busy).
- **The admin dashboard** is the one page that misses the target even lightly loaded (p95 583 ms at 5 users). The
  codespace re-measure traced this to its receivables, MRR and income aggregates, which read about 150,000 rows and
  are cached for 60 s; the runner run did not profile it separately.
- **The billing day** was measured on its own, after the web levels (`loadtest/README.md`, "Not covered yet": web load
  during the automation run). How the two interact on one small host is not known.

## Codespace comparison (why the runner run is the reference)

The re-measure of 2026-10-20 ran on the shared 4-vCPU codespace with PHP's built-in server (4 workers), no stack cap
and a host load of 8–11 from other work. The same data and the same 40-user mix gave very different numbers:

| Measure                   | Codespace (shared host)                | Runner (2 vCPU cap)                |
| ------------------------- | -------------------------------------- | ---------------------------------- |
| All pages, 40 users       | p50 0.9 s / p95 2.2 s, 12.5–15.3 req/s | p50 41 ms / p95 424 ms, 17.7 req/s |
| Admin dashboard, 40 users | p50 2.6 s / p95 5.0 s                  | p50 225 ms / p95 5.1 s             |
| Client invoices, 40 users | p50 1.1 s / p95 1.8 s                  | p50 36 ms / p95 77 ms              |
| Sign-in, 40 users         | p50 5.2 s / p95 6.9 s                  | p50 2.4 s / p95 5.6 s              |
| Billing day               | 675 s (runs 669, 675, 856)             | 284.5 s                            |
| Renewals                  | 16.6 per second                        | 39.3 per second                    |

The codespace plateau at 0.9–1.2 s per page was the host, not the app: on a dedicated 2 vCPU cap the medians stay flat
from 5 to 40 users. Only runner runs count as sizing evidence. For the record, the first harness run, before the
performance work of #166 and #189, saw an admin dashboard p50 of 28.5 s, a 3.8 s global search, a client invoices p95
of 49.7 s and a 546 s billing day at 20 renewals per second.

## Not measured yet

- **The medium profile** (4 vCPU, 8 GB, 8 PHP threads). It fits the public repository's standard runner
  (4 CPUs, 16 GB); run it from Actions → Load test with `profile: medium`.
- **The large profile** (8 vCPU, 16 GB). It needs a runner with 8 CPUs.
- **Where the small profile saturates.** 40 users was still comfortable; a dispatch with `levels: "40 80 120"`
  would find the limit.
- **Web load during the billing day**, provisioning against a control panel, and a Redis-backed stack
  (`loadtest/README.md`, "Not covered yet").

The two content errors of that run were harness artefacts, not sizing facts, and are fixed in the harness (the numbers
above were measured before the fix):

- `cart_add` answered without the `?added=` key in 17 of 103 adds at 40 users (1 and 6 at 10 and 20). The scenario
  named its domains by virtual user and iteration, so each level reused the names of the orders the earlier levels
  had placed, and the cart refuses a domain that already belongs to a service (`orders.block_duplicate_domains`).
  Names now include the scenario start time. Found by reading the code, not re-measured on a runner.
- At 5 users 12 of 32 client visits picked a client with no invoice in the seeded year (a one-time or long-cycle
  service). An empty list is valid and is no longer counted as an error.
