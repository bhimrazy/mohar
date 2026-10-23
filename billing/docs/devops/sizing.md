# Sizing guide

How much a single Docker host running the production stack ([production.md](production.md)) can serve, measured
with the load-test harness in `loadtest/` (`loadtest/README.md`). Every number here comes from a posted run; the
guide says where each one is from and what has not been measured yet. Hosts in Nepal are mostly small, so the
small-host guidance comes first.

Sources:

- **the reference:** the GitHub runner run of 2026-10-23,
  [actions/runs/40000321793](https://github.com/bhimrazy/mohar/actions/runs/40000321793), the `load-test` label on
  PR #433 (commit `c5c0ffa`, the PR's head `dbf92da5` merged into `develop`), with virtual users arriving over 20 s;
- history, before PR #433 (every virtual user started in the same second, see "Where the small host runs out"): the
  runner run of 2026-10-23 on the PR #374 branch,
  [actions/runs/39944684180](https://github.com/bhimrazy/mohar/actions/runs/39944684180) (commit `3151b0d`), and the
  GitHub runner run of 2026-10-21, [actions/runs/39472898451](https://github.com/bhimrazy/mohar/actions/runs/39472898451),
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
| **Small: 2 vCPU, 4 GB** (the profile)         | under 2,000  | Enough, with a wide margin (inference). The small profile was measured at 50,000 clients, 25 times this volume, with 40 active users: median 120 ms per request, no errors.                                                  |
| Small: 2 vCPU, 4 GB                           | 2,000–20,000 | Enough for the pages and the billing day (inference from the 50,000-client run, which is the larger data set). The tail at 40 active users (sign-in, dashboards) is the thing to watch; see "Where the small host runs out". |
| Small: 2 vCPU, 4 GB (minimum and recommended) | 50,000       | Measured. At 40 active users: median 120 ms, p95 704 ms and p99 1.5 s over all pages, no errors; most pages have a p95 of 0.2–0.65 s, checkout 2.1 s and the admin dashboard 3.1 s; billing day 539 s.                       |
| Medium: 4 vCPU, 8 GB                          | any          | Not measured. The next step when the small host's tail latency matters: 8 PHP threads instead of 4. It fits the public repository's runner and can be run from Actions.                                                      |
| Large: 8 vCPU, 16 GB                          | above 50,000 | Not measured. The large profile needs 8 vCPU and the standard GitHub runner has 4, so it needs a larger or self-hosted runner (`loadtest/README.md`).                                                                        |

"Active users" are k6 virtual users: each one loads a page, waits 1–3 seconds, loads the next, so one virtual user is
roughly one person clicking through the site. 40 virtual users produced 16.4 requests per second.

## What was measured and how

- **Host:** a GitHub-hosted `ubuntu-24.04` runner with 4 CPUs, 15,988 MB and Docker 28.0.4. The whole stack ran
  inside a systemd slice capped at the **small profile: 2 vCPU and 4 GB**, with the profile's per-service limits
  (`loadtest/profiles/small.env`): FrankenPHP with **4 PHP threads** behind Caddy, PostgreSQL with
  `shared_buffers=256MB`. k6, the load generator, ran outside the cap.
- **Data:** the large seed volume, **50,000 clients, 100,000 services and 359,045 invoices** with a year of payments
  and tickets, seeded in 160 s; database size 883 MB; all seed invariants held. 25% of the recurring services renew on
  the measured day, so the automation run is a billing day, not an average day.
- **Web load:** four 2-minute levels of **5, 10, 20 and 40 virtual users** with the scenario mix in
  `loadtest/README.md` (client area 50%, storefront 30%, admin 10%, JSON endpoints 10%). Each virtual user arrives at
  a random moment in the first 20 s of a level (`--start-spread 20`, PR #433).
- **Background work:** invoice PDFs from one process, the billing-day `automation:run` with the workers stopped, then
  the queue drain once they start again.
- **Code:** commit `c5c0ffa` (PR #433 on `develop` of 2026-10-23), which includes the one-check sign-in of #374. The
  run took 25 minutes; artifact `load-test-small-large-40000321793`.

Two limits appear in the results. The harness calls a level "comfortable" when the p95 of all requests is under 1 s
with under 1% errors (`loadtest/bin/summarize.py`); that is the line the "fits" verdicts below use. The product
target for a page is p95 under 200 ms, and the per-page table says which pages meet it.

## Results: small profile, 50,000 clients

From run 40000321793 (2026-10-23, commit `c5c0ffa`, users arriving over 20 s).

### Web load per level

| Virtual users | req/s | p50    | p95    | p99    | Errors |
| ------------: | ----: | ------ | ------ | ------ | -----: |
|             5 |   3.3 | 48 ms  | 105 ms | 307 ms |      0 |
|            10 |   4.7 | 50 ms  | 119 ms | 356 ms |      0 |
|            20 |   9.0 | 55 ms  | 236 ms | 470 ms |      0 |
|            40 |  16.4 | 120 ms | 704 ms | 1.5 s  |      0 |

No request errors, no 429 answers and no content errors at any level. 40 virtual users was still "comfortable" (p95
under 1 s). During the 40-user level the app container averaged 75% of one core (peak 129%) and PostgreSQL 66%
(peak 137%), together about 70% of the 2 vCPU cap on average.

### Per page

"Light" is the 5-user level, the closest the harness has to a single request.

| Page                       | Light: p50 / p95 | 40 users: p50 / p95 | p95 under 200 ms at 40 users? |
| -------------------------- | ---------------- | ------------------- | ----------------------------- |
| Admin dashboard            | 219 ms / 960 ms  | 828 ms / 3.1 s      | No                            |
| Admin global search        | 63 ms / 75 ms    | 150 ms / 596 ms     | No                            |
| Admin unpaid invoices      | 67 ms / 99 ms    | 113 ms / 366 ms     | No                            |
| Client list search (admin) | 58 ms / 67 ms    | 259 ms / 455 ms     | No                            |
| Client picker (JSON)       | 38 ms / 47 ms    | 93 ms / 635 ms      | No                            |
| Client dashboard           | 47 ms / 61 ms    | 138 ms / 546 ms     | No                            |
| Client invoices            | 44 ms / 55 ms    | 100 ms / 586 ms     | No                            |
| Storefront                 | 37 ms / 54 ms    | 74 ms / 644 ms      | No                            |
| Sign-in                    | 297 ms / 313 ms  | 671 ms / 1.4 s      | No                            |
| All pages                  | 48 ms / 105 ms   | 120 ms / 704 ms     | p99 1.5 s                     |

Lightly loaded, every page meets the 200 ms target except four: the admin dashboard (p95 960 ms), checkout (411 ms,
one request), sign-in (313 ms) and the invoice PDF download (p50 224 ms, p95 239 ms). At 40 users most pages have a
p95 of 0.2–0.65 s: requests waiting for one of the 4 PHP threads and for PostgreSQL on a host that is about 70% busy.
Above that are the admin dashboard (3.1 s), checkout (2.1 s, 12 requests), sign-in (1.4 s), the invoice PDF
(934 ms), the order confirmation (880 ms) and the sign-in form (817 ms). The admin dashboard is the outlier at every
level (see "Where the small host runs out").

### Background work

| Measure                           | Result                                                                                                    |
| --------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Billing day (`automation:run`)    | 539.3 s                                                                                                   |
| Renewals                          | 12,055 in 486.7 s, 24.8 per second                                                                        |
| Reminders                         | 2,213 in 47.9 s                                                                                           |
| Queue drain after the billing day | 14,394 jobs in 122.4 s (117.6 per second), 0 failed                                                       |
| Invoice PDF (dompdf, one process) | p50 161 ms, p95 171 ms, 6.2 renders per second, 858 KB per file                                           |
| Slowest automation query          | the `email_messages` insert: 14,289 calls, 7.2 s in total                                                 |
| Slowest web queries               | the admin dashboard's income, receivables and MRR aggregates: means of 493, 486 and 397 ms, 8–9 runs each |

### Compared with the runs before PR #433

Not like for like. Besides the arrival spread, the code moved on from `bf97103` to `c5c0ffa`, and the runner may
differ: on the same seed volume the one-process PDF bench, which no web load touches, took 161 ms against 104 ms,
the renewals ran at 24.8 per second against 39.3 and the billing day took 539 s against 284.5 s, while the stack
used about 1.6 times the CPU for the same 40-user mix (app 75% and PostgreSQL 66% against 42% and 45%). How much of
that is a slower runner and how much is code added since (renewals now also write the fiscal register fields) has
not been separated; a second run of the same commit would show the runner's share.

| 40 users             | 39472898451 (2026-10-21, `bf97103`) | 39944684180 (2026-10-23, `3151b0d`) | 40000321793 (2026-10-23, `c5c0ffa`) |
| -------------------- | ----------------------------------- | ----------------------------------- | ----------------------------------- |
| Arrival              | all at once                         | all at once                         | over 20 s                           |
| Password checks      | two per sign-in                     | one                                 | one                                 |
| All pages p50 / p95  | 41 ms / 424 ms                      | 70 ms / 586 ms                      | 120 ms / 704 ms                     |
| All pages p99        | 4.2 s                               | 3.0 s                               | 1.5 s                               |
| Sign-in p50 / p95    | 2.4 s / 5.6 s                       | 1.8 s / 3.8 s                       | 671 ms / 1.4 s                      |
| Client dashboard p95 | 3.3 s                               | 2.7 s                               | 546 ms                              |
| Storefront p95       | 4.6 s                               | 2.9 s                               | 644 ms                              |
| Admin dashboard p95  | 5.1 s                               | 3.2 s                               | 3.1 s                               |
| req/s                | 17.7                                | 17.4                                | 16.4                                |

<details><summary>History: the full tables of run 39472898451 (2026-10-21), before PR #433</summary>

Every virtual user started in the same second and the sign-in checked the password twice (fixed in #374), so the
tail here is mostly the sign-in burst ("Where the small host runs out").

**Web load per level**

| Virtual users | req/s | p50   | p95    | p99    | Errors |
| ------------: | ----: | ----- | ------ | ------ | -----: |
|             5 |   3.5 | 36 ms | 102 ms | 629 ms |      0 |
|            10 |   4.8 | 36 ms | 144 ms | 779 ms |      0 |
|            20 |   9.2 | 37 ms | 188 ms | 1.8 s  |      0 |
|            40 |  17.7 | 41 ms | 424 ms | 4.2 s  |      0 |

No request errors and no 429 answers at any level (two content errors are listed at the end). 40 virtual users was still "comfortable" (p95 under 1 s), so the small
profile was not saturated by its own levels: during the 40-user level the app container averaged 42% of one core
(peak 180%) and PostgreSQL 45%.

**Per page**

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

**Background work**

| Measure                           | Result                                                                         |
| --------------------------------- | ------------------------------------------------------------------------------ |
| Billing day (`automation:run`)    | 284.5 s                                                                        |
| Renewals                          | 10,515 in 268 s, 39.3 per second                                               |
| Queue drain after the billing day | 11,525 jobs in 64.3 s (179 per second), 0 failed                               |
| Invoice PDF (dompdf, one process) | p50 104 ms, p95 115 ms, 9.5 renders per second                                 |
| Slowest automation query          | the `email_messages` insert: 11,461 calls, 4.2 s in total                      |
| Renewals loop                     | about 1.1 s per 10,000 updates on each of services, invoices and invoice items |

</details>

## Memory

The earlier, larger minimum in production.md came from adding up the compose memory limits (5,888 MB at the peak of
a deploy). Limits are ceilings; the measurements say what the stack uses.

Where the numbers come from: the runner figures are from the load-test stack (`loadtest/compose.loadtest.yaml`, one
colour, capped at 2 CPU and 4 GB by a systemd slice, the small profile's per-service limits), not from
`deploy/compose.prod.yaml`; the drill figure is from `deploy/compose.prod.yaml` itself. **4 GB is the smallest size
tested, not a measured floor**: nothing smaller was run, and no run came near the cap.

| Run                                                                                                                                                         | Total memory of all containers (without k6)                                                                                                                                                                                                |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Runner, small profile, 50,000 clients, one colour (artifact `load-test-small-large-40000321793`, `docker-stats.ndjson`, 2,293 samples of single containers) | peak 928 MiB (queue drain, all 9 containers); 924 MiB at 40 users; 905 MiB in the PDF phase. The billing day (827 MiB) is partial: only 6 of the 9 containers ran                                                                          |
| Earlier runner run, before PR #433 (artifact `load-test-small-large-39472898451`, 218 samples)                                                              | peak 857 MiB (PDF phase, only 2 samples had all 9 containers); 790 MiB at 40 users; 790 MiB during the queue drain. The billing day (764 MiB) is partial: only 6 of the 9 containers ran (the pools were stopped), so it is not comparable |
| Drill, full production stack, demo data (956 invoices), `deploy/bin/deploy` blue then green, VM of 3.6 GB (3,665 MB by `free -m`)                           | peak 691 MiB (total of the containers) with both colours' web containers and pools running, sampled every 3 s                                                                                                                              |

Per service in run 40000321793, the highest value of any sample over all phases (MiB): PostgreSQL 347
(`shared_buffers=256MB`), the web container 210, the scheduler 205 (billing day), the three pools and the automation
worker 46–64 each, RustFS 99, Caddy 32 (run 39472898451: 340, 185, 179, 45–74, 80 and 27). The kernel and page cache come on top of that, which is why 4 GB, not 1 GB, is the minimum.

So a 4 GB host runs the stack in steady state with room to spare, and the drill's deploy fitted a 3.6 GB VM although
the limits then added up to more than the VM had. What these runs do not cover, and so what a 4 GB host has not
been shown to survive: a deploy on the 50,000-client database (the drill's database was 29 MB; the runner's 883 MB
was never deployed over), a deploy that waits for a billing day in progress (both colours' schedulers and automation
workers overlap briefly), the `--memory=256` import jobs of up to 192 MB, and swap behaviour. Until someone runs a
deploy at the large volume, treat deploys on a 4 GB host with a database of that size as unproven and watch
`free -m` during one.

## When to grow

| Sign                                                                                                                                                           | What to do                                                                                                                                                        |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign-in and dashboards have a multi-second p95 while the median is fine, in steady use                                                                         | More vCPU first, and the PHP thread count that goes with it: the medium profile (4 vCPU, 8 GB, 8 threads), not yet measured                                       |
| Many people sign in at once (measured: 28 at once gave a sign-in p50 of 1.3–1.8 s and a dashboard and store p95 of 1.2–3.0 s, "Where the small host runs out") | The same: more vCPU and PHP threads if those bursts matter to you, otherwise accept a few slow seconds. The bcrypt cost is a security setting and stays unchanged |
| Available memory under about 1 GB during a deploy (`free -m`; a guide, not a measurement)                                                                      | Run `deploy/bin/deploy retire` after each deploy, then move to the medium profile (4 vCPU, 8 GB)                                                                  |
| Over 50,000 clients, or more than 40 people in the admin and client area at once                                                                               | Not measured; run the medium or large profile from Actions → Load test before sizing                                                                              |

## Where the small host runs out

- **The tail, not the median, and most of it was the harness.** Until issue #205 every virtual user of a level started
  in the same second (k6 `constant-vus`): at 40 users, 28 of them (client, admin and JSON) signed in at once and the 12
  storefront users about 10 seconds later, on 2 vCPU and 4 PHP threads. Two runner runs show it: 39472898451 (commit
  `bf97103`, the history tables above) and
  [39944684180](https://github.com/bhimrazy/mohar/actions/runs/39944684180) (commit `3151b0d`, the PR #374 branch). In
  both, the app container was at 168–180% of its 200% cap in the first second sampled, again at 126–141% about 11
  seconds in, and never above 54–82% for the rest of the level; the queue workers and scheduler were idle. The client
  dashboard, store and admin dashboard each took their multi-second p95 from their first request per user, which
  landed in that burst. A local reproduction put every one of the slowest 5% of client dashboard and store requests in
  the first 10 seconds; after them, the client dashboard had a p95 of 122 ms and the store 111 ms. It differs from the
  runner: only the app was capped (FrankenPHP 1.13.2, 2 CPUs, 4 threads), PostgreSQL 18 was not, invoice PDFs were
  off (`PDF_SHARE=0`), and the seed had 20,000 clients against the runner's 50,000. The pages themselves are cheap
  (client dashboard 12 queries, about 10 ms; store 10 queries, about 10 ms; all on indexes). Since issue #205 each user
  arrives at a random moment in the first 20 seconds of a level (`loadtest/bin/run --start-spread`), a modelling
  choice: 40 users arriving over 20 s is two arrivals a second. Run 40000321793 is the first runner run with it: the
  all-page p99 at 40 users fell from 3.0–4.2 s to 1.5 s, and the client dashboard and store p95 from 2.7–4.6 s to
  0.55–0.65 s ("Compared with the runs before PR #433").
- **Sign-in** was two causes at once in the 2.4 s p50 of run 39472898451, which predates PR #374 and checked the
  password twice per sign-in (460 ms on the Mac); #374 made it one bcrypt check at cost 12 (260 ms). On the #374
  branch (run 39944684180) the 40-user sign-in p50 was 1.8 s, and what remained was the burst: almost every sign-in of
  a level happened in it. Spread out, a sign-in took 270–300 ms locally and had a p50 of 671 ms on the runner at 40
  users (run 40000321793).
- **A real burst of sign-ins still queues.** 28 sign-ins at once on 2 vCPU and 4 threads gave a sign-in p50 of
  1.3–1.6 s locally (1.8 s on the runner), and the dashboard and store requests behind them a p95 of 1.2–2.2 s locally
  (2.7–3.0 s on the runner). If such bursts happen (a mail campaign, the start of a working day), see "When to grow".
- **The admin dashboard** is the one page that misses the target even lightly loaded: p50 219 ms and p95 960 ms at 5
  users, 828 ms and 3.1 s at 40 (run 40000321793). Profiled locally for issue #441 (PostgreSQL 18, 20,000 clients):
  a visit with the cached figures takes about 100 ms and 65 queries, each under 5 ms. A visit that finds them
  expired also runs the income-per-day, receivables and MRR aggregates, which read most of `transactions`,
  `invoices` and `services` (sequential and parallel scans, 34–62 ms each locally, means of 397–493 ms on the runner),
  and took 218–290 ms locally. Until #441 the figures were cached for 60 s, so every minute one admin visit, or
  several at once, waited for them. Since #441 they stay fresh for 60 s and are then served for up to 10 minutes
  while the visit that found them stale recomputes them after its response has been sent (`Cache::flexible`; on
  FrankenPHP and PHP-FPM the response is flushed first). Locally that visit took 100–106 ms, with the recompute
  (122–126 ms) after it. The runner has not been re-run with it.
- **The billing day** was measured on its own, after the web levels (`loadtest/README.md`, "Not covered yet": web load
  during the automation run). How the two interact on one small host is not known.

## Codespace comparison (why the runner run is the reference)

This compares the codespace with the first runner run, both before PR #433 and #374. The re-measure of 2026-10-20 ran on the shared 4-vCPU codespace with PHP's built-in server (4 workers), no stack cap
and a host load of 8–11 from other work. The same data and the same 40-user mix gave very different numbers:

| Measure                   | Codespace (shared host)                | Runner (2 vCPU cap), 39472898451   |
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
