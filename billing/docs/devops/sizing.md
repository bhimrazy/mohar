# Sizing guide

How much a single Docker host running the production stack ([production.md](production.md)) can serve, measured
with the load-test harness in `loadtest/` (`loadtest/README.md`). Every number here comes from a posted run; the
guide says where each one is from and what has not been measured yet. Hosts in Nepal are mostly small, so the
small-host guidance comes first. Labels used below: **measured** (read from a run's artifact), **inferred**
(worked out from measured numbers) and **not measured**.

Sources:

- **capacity (the reference for how many people a host serves):** two runs of 2026-10-31 on PR #543, the `load-test`
  label running three jobs each from `loadtest/label-runs.json`:
  [actions/runs/41193623951](https://github.com/bhimrazy/mohar/actions/runs/41193623951) (commit `e33b108`: small and
  medium ramps, small with 10–30 s think time) and
  [actions/runs/41198972155](https://github.com/bhimrazy/mohar/actions/runs/41198972155) (commit `450982e`: small at
  10–50 users, small and medium with 10–30 s think time). Both commits are the PR's head merged into `develop` and
  differ only in `loadtest/label-runs.json`; the two plans are that file at commits `17571c91` (round 1) and
  `ed10eee2` (round 2) of PR #543. After them the label runs one cheaper job (small, 10–30 s per page);
- **per-page detail at light load and background work, and history:** the runner run of 2026-10-23,
  [actions/runs/40000321793](https://github.com/bhimrazy/mohar/actions/runs/40000321793), the `load-test` label on
  PR #433 (commit `c5c0ffa`, the PR's head `dbf92da5` merged into `develop`), with virtual users arriving over 20 s;
- a re-run of 2026-10-31, [actions/runs/41200682198](https://github.com/bhimrazy/mohar/actions/runs/41200682198),
  the `load-test` label on PR #552 (the database review's indexes, commit `f9fe390`), reported in "Re-run of
  2026-10-31 (PR #552)" under History; the capacity verdicts rest on the PR #543 runs;
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
([production.md](production.md)), up to the 50,000 clients measured. Every doc states these numbers: production.md,
[install.md](install.md), [deployment-options.md](deployment-options.md) and the docs site's requirements page.

At 50,000 clients the small host (2 vCPU, 4 GB) stays comfortable (p95 of all requests under 1 s, under 1% errors)
up to **about 15 requests per second**. That is **20–40 fast virtual users** (1–3 s per page; 40 on two runners, 20 on a third), or **200 to 350 people
signed in at once** who read each page for 10–30 s (measured). The medium host (4 vCPU, 8 GB) stays comfortable up to
**50 fast virtual users, or 400 people at once** (measured on a runner whose 4 CPUs it shares with the load generator,
so a lower bound). What runs out first is CPU, split about evenly between PHP and PostgreSQL; memory is not close to
a limit (see "What limits first" and "Memory").

| Host                                          | Clients      | What the measurements say                                                                                                                                                                                                                                                                     |
| --------------------------------------------- | ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Small: 2 vCPU, 4 GB** (the profile)         | under 2,000  | Enough, with a wide margin (inference). Every measurement below is at 50,000 clients, 25 times this volume.                                                                                                                                                                                   |
| Small: 2 vCPU, 4 GB                           | 2,000–20,000 | Enough for the pages (inference from the 50,000-client runs, the larger data set). The billing day on the small profile did not finish in the 2026-10-31 runs (see "Background work").                                                                                                        |
| Small: 2 vCPU, 4 GB (minimum and recommended) | 50,000       | Measured. Comfortable at 40 fast virtual users (15 req/s, p95 669 ms), past the limit at 50 (p95 1.6–2.6 s); a third runner was past it at 40. With 10–30 s per page: comfortable at 200 people (and at 350 on a faster runner with slower arrivals), past the limit at 300 (see "Capacity"). |
| Medium: 4 vCPU, 8 GB                          | 50,000       | Measured, as a lower bound. Comfortable at 50 fast virtual users (20 req/s, p95 315 ms) and at 400 people with 10–30 s per page (17 req/s, p95 353 ms); past the limit at 100 fast users and at 500 people. Billing day 3,059–3,078 s.                                                        |
| Large: 8 vCPU, 16 GB                          | above 50,000 | Not measured. The large profile needs 8 vCPU and the standard GitHub runner has 4, so it needs a larger or self-hosted runner (`loadtest/README.md`).                                                                                                                                         |

"Virtual users" are k6 virtual users; "people" are virtual users with 10–30 s per page (`loadtest/bin/run --think "10 30"`),
closer to someone reading an invoice or a ticket. A fast virtual user sends 0.38–0.41 requests per second when the
host is comfortable, a person 0.042–0.051 (measured), so **one fast virtual user is about 7–10 people** (inferred from
those rates). The earlier estimate of 200–600 people for 40 virtual users narrows to **about 280–400 from the request
rates, and 200–350 measured directly** on the small host.

## What was measured and how

- **Host:** GitHub-hosted `ubuntu-24.04` runners with 4 CPUs, 15,701–15,983 MB and Docker 28.0.4. Each run capped the
  whole stack with a systemd slice at the profile's totals: **small, 2 vCPU and 4 GB** (FrankenPHP with **4 PHP
  threads**, PostgreSQL `shared_buffers=256MB`), or **medium, 4 vCPU and 8 GB** (8 PHP threads, `shared_buffers=640MB`),
  with the per-service limits of `loadtest/profiles/<profile>.env`. k6 ran outside the cap on the same runner. For
  medium the cap is the whole runner, so k6 (4–7% of one core), Docker and the runner agent took from the same 4 CPUs.
- **Data:** the large seed volume, **50,000 clients, 100,000 services and 355,350 invoices** with a year of payments
  and tickets, seeded in 167–208 s; database 879 MB; all seed invariants held.
- **Web load:** the scenario mix in `loadtest/README.md` (client area 50%, storefront 30%, admin 10%, JSON endpoints
  10%), in steps of virtual users. Each level ran 3 minutes with users arriving over its first 60 s (fast ramps), or
  5–6 minutes with users arriving over 120–240 s (10–30 s per page). The ramp stops one level after the first level
  past the comfortable limit (`loadtest/bin/run`, PR #543).
- **Comfortable** means p95 of all requests under 1 s and under 1% errors (`loadtest/bin/summarize.py`); the "fits"
  verdicts use it. The product target for a page is p95 under 200 ms, reported per page.

## Capacity: where each host saturates

From runs 41193623951 and 41198972155 (2026-10-31). CPU is the level's average in % of one core (the small cap is 200%,
medium 400%); "stack" is every container except k6, as a share of the cap. "People at once" is measured for the
10–30 s rows and inferred for the fast rows (virtual users × 7–10, from the request rates above).

### Small: 2 vCPU, 4 GB, fast virtual users (1–3 s per page)

| Virtual users | People at once (inferred) | req/s | p50    | p95    | p99    | Errors | Stack CPU of cap | App CPU | PostgreSQL CPU | Run, job                    |
| ------------: | ------------------------: | ----: | ------ | ------ | ------ | -----: | ---------------: | ------: | -------------: | --------------------------- |
|            10 |                    70–100 |   3.9 | 51 ms  | 105 ms | 267 ms |     0% |              14% |     13% |            13% | 41198972155, small-ramp-low |
|            20 |                   140–200 |   8.2 | 58 ms  | 198 ms | 421 ms |     0% |              34% |     31% |            30% | 41198972155, small-ramp-low |
|            30 |                   210–300 |  12.1 | 68 ms  | 329 ms | 694 ms |     0% |              52% |     51% |            46% | 41198972155, small-ramp-low |
|        **40** |               **280–400** |  15.0 | 135 ms | 669 ms | 1.5 s  |     0% |              75% |     75% |            66% | 41198972155, small-ramp-low |
|            50 |                   350–500 |  17.0 | 413 ms | 1.6 s  | 2.4 s  |     0% |              83% |     85% |            72% | 41198972155, small-ramp-low |
|            50 |                   350–500 |  13.9 | 808 ms | 2.6 s  | 3.8 s  |     0% |              82% |     76% |            77% | 41193623951, small-ramp     |
|           100 |                 700–1,000 |  16.1 | 3.8 s  | 4.9 s  | 5.8 s  |     0% |              97% |     94% |            90% | 41193623951, small-ramp     |

**Saturation (measured):** last comfortable level 40 virtual users, first failing level 50, in both runs. Past 50
the request rate stops growing (16 req/s at 100 users) and every page waits: p50 3.8 s on every scenario. 40 is close
to the edge: PR #552's re-run the same day (run 41200682198, commit `f9fe390`, users arriving over 20 s, a different
runner) had 40 users past the limit (p95 1.26 s at 15.1 req/s) and 20 as its highest comfortable level ("Re-run of
2026-10-31 (PR #552)"). Plan on about 15 requests per second, not on 40 users.

### Small: 2 vCPU, 4 GB, people (10–30 s per page)

| People at once (measured) | req/s | p50    | p95    | p99    | Errors | Stack CPU of cap | App CPU | PostgreSQL CPU | Arrival | Run, job                          |
| ------------------------: | ----: | ------ | ------ | ------ | -----: | ---------------: | ------: | -------------: | ------- | --------------------------------- |
|                       100 |   5.1 | 55 ms  | 268 ms | 314 ms |     0% |              23% |     25% |            19% | 120 s   | 41193623951, small-realistic      |
|                   **200** |  10.0 | 63 ms  | 308 ms | 596 ms |     0% |              44% |     52% |            33% | 120 s   | 41193623951, small-realistic      |
|                       300 |  14.7 | 128 ms | 5.5 s  | 7.7 s  |  0.19% |              74% |     82% |            57% | 120 s   | 41193623951, small-realistic      |
|                       400 |  17.6 | 3.0 s  | 7.0 s  | 8.8 s  |  1.67% |              94% |    108% |            71% | 120 s   | 41193623951, small-realistic      |
|                       200 |   8.6 | 34 ms  | 226 ms | 264 ms |     0% |              24% |     29% |            16% | 240 s   | 41198972155, small-realistic-knee |
|                       250 |  10.6 | 36 ms  | 226 ms | 314 ms |     0% |              30% |     36% |            21% | 240 s   | 41198972155, small-realistic-knee |
|                       300 |  12.5 | 37 ms  | 229 ms | 351 ms |     0% |              39% |     45% |            27% | 240 s   | 41198972155, small-realistic-knee |
|                   **350** |  14.6 | 39 ms  | 279 ms | 521 ms |     0% |              48% |     58% |            33% | 240 s   | 41198972155, small-realistic-knee |

**Saturation (measured):** with arrivals over 120 s, last comfortable 200 people and first failing 300. With
arrivals over 240 s on another runner, 350 people were still comfortable (the highest level that run had). The two
differ in two ways at once, and neither has been separated:

- **The runner.** The second runner needed about two thirds of the CPU for the same request rate (14.6 req/s at 48% of
  the cap against 14.7 at 74%), and its medians were lower at every level. Runners of one label have different CPU
  models; the harness records the model from PR #543 on, these runs did not.
- **The arrival.** At 300 people over 120 s the stack was at 90–97% of the 2 vCPU cap from 60 s to 120 s into the level
  (the sign-ins and first full pages), then at 60–85% of it for the rest; the p95 of 5.5 s came from that window, while
  the p50 stayed at 128 ms.

So the small host serves **200 people at once with a margin and about 350 when they do not all sign in within two
minutes** (measured), the same **about 15 requests per second** as the 40 fast virtual users.

### Medium: 4 vCPU, 8 GB

| Virtual users or people | Think time | People at once         | req/s | p50    | p95    | p99    | Errors | Stack CPU of cap | App CPU | PostgreSQL CPU | Run, job                      |
| ----------------------: | ---------- | ---------------------- | ----: | ------ | ------ | ------ | -----: | ---------------: | ------: | -------------: | ----------------------------- |
|                  **50** | 1–3 s      | 350–500 (inferred)     |  20.1 | 102 ms | 315 ms | 655 ms |  0.02% |              56% |    103% |           104% | 41193623951, medium-ramp      |
|                     100 | 1–3 s      | 700–1,000 (inferred)   |  28.9 | 944 ms | 1.7 s  | 2.2 s  |  0.55% |              85% |    164% |           158% | 41193623951, medium-ramp      |
|                     150 | 1–3 s      | 1,050–1,500 (inferred) |  31.2 | 2.4 s  | 3.3 s  | 3.7 s  |  0.43% |              88% |    169% |           163% | 41193623951, medium-ramp      |
|                     300 | 10–30 s    | 300 (measured)         |  13.2 | 81 ms  | 302 ms | 416 ms |     0% |              39% |     79% |            68% | 41198972155, medium-realistic |
|                 **400** | 10–30 s    | **400 (measured)**     |  16.9 | 96 ms  | 353 ms | 585 ms |  0.06% |              50% |    103% |            88% | 41198972155, medium-realistic |
|                     500 | 10–30 s    | 500 (measured)         |  20.6 | 168 ms | 1.8 s  | 2.4 s  |  0.24% |              66% |    137% |           113% | 41198972155, medium-realistic |
|                     600 | 10–30 s    | 600 (measured)         |  24.9 | 606 ms | 2.5 s  | 2.9 s  |  0.35% |              77% |    163% |           129% | 41198972155, medium-realistic |

**Saturation (measured):** fast virtual users, last comfortable 50 and first failing 100; people, last comfortable 400
and first failing 500. At 500 people the level average was 66% of the cap, but from 150 s to 240 s into the level
(the end of the 240 s arrival) the stack used 360–385% of its 400%. The 4 vCPU cap is the runner's whole CPU, which k6,
Docker and the runner agent share, so medium had less than 4 CPUs (inferred) and **these are lower bounds**: the
medium host did about 1.3 times the comfortable request rate of the small one (20 against 15 req/s) for twice the
vCPU. A real 4 vCPU host, or an 8-CPU runner, has not been measured.

At 100 fast users the knowledge base (12% of 104 requests) and announcements (19% of 108) answered with errors, which
is why that level has 0.55% errors; the other pages had none. They were the per-IP limit `support-public`, 60 a
minute shared by both pages (#567, inferred: the run did not record statuses; 212 requests in the 3-minute level is
about 71 a minute, which predicts about 15% refused, and a local burst from one IP was refused with 429 from the 61st
request). So these errors are a harness artefact, not a sizing fact. The pages now have their own limit of 300 a
minute, and the harness counts their 429s apart and lists each page's error statuses.

### Per page at the knee

p50 / p95 at the last comfortable and the first failing level. Checkout (12–47 requests a level) and the invoice PDF
are the slowest pages at every level; the admin dashboard is next.

| Page                | Small, 40 fast users | Small, 50 fast users | Small, 200 people (120 s) | Small, 300 people (120 s) | Medium, 50 fast users | Medium, 100 fast users | Medium, 400 people | Medium, 500 people |
| ------------------- | -------------------- | -------------------- | ------------------------- | ------------------------- | --------------------- | ---------------------- | ------------------ | ------------------ |
| Sign-in             | 345 ms / 804 ms      | 536 ms / 1.4 s       | 291 ms / 559 ms           | 525 ms / 7.7 s            | 296 ms / 441 ms       | 605 ms / 1.6 s         | 319 ms / 544 ms    | 412 ms / 2.1 s     |
| Client dashboard    | 152 ms / 690 ms      | 466 ms / 1.7 s       | 69 ms / 213 ms            | 130 ms / 5.1 s            | 112 ms / 311 ms       | 990 ms / 1.6 s         | 108 ms / 285 ms    | 165 ms / 1.8 s     |
| Client invoices     | 132 ms / 629 ms      | 428 ms / 1.5 s       | 59 ms / 232 ms            | 84 ms / 3.5 s             | 99 ms / 250 ms        | 946 ms / 1.5 s         | 90 ms / 249 ms     | 149 ms / 1.8 s     |
| Store               | 100 ms / 577 ms      | 366 ms / 1.1 s       | 44 ms / 141 ms            | 75 ms / 4.3 s             | 76 ms / 183 ms        | 814 ms / 1.5 s         | 63 ms / 174 ms     | 105 ms / 1.8 s     |
| Add to cart         | 175 ms / 685 ms      | 436 ms / 1.5 s       | 83 ms / 251 ms            | 197 ms / 5.5 s            | 129 ms / 316 ms       | 987 ms / 1.7 s         | 124 ms / 330 ms    | 236 ms / 1.6 s     |
| Checkout            | 1.4 s / 2.7 s        | 1.9 s / 3.7 s        | 771 ms / 2.1 s            | 1.2 s / 2.1 s             | 1.1 s / 1.5 s         | 2.7 s / 3.5 s          | 1.2 s / 1.9 s      | 1.6 s / 3.1 s      |
| Invoice PDF         | 545 ms / 876 ms      | 498 ms / 1.6 s       | 285 ms / 714 ms           | 634 ms / 7.3 s            | 374 ms / 586 ms       | 1.3 s / 2.2 s          | 374 ms / 689 ms    | 557 ms / 2.5 s     |
| Admin dashboard     | 655 ms / 1.3 s       | 798 ms / 1.6 s       | 238 ms / 599 ms           | 442 ms / 6.5 s            | 462 ms / 810 ms       | 1.6 s / 2.2 s          | 370 ms / 794 ms    | 605 ms / 2.0 s     |
| Admin services      | 216 ms / 768 ms      | 538 ms / 1.8 s       | 136 ms / 256 ms           | 330 ms / 2.3 s            | 226 ms / 426 ms       | 1.2 s / 1.9 s          | 242 ms / 501 ms    | 475 ms / 2.0 s     |
| Admin search (JSON) | 202 ms / 679 ms      | 472 ms / 1.6 s       | 83 ms / 227 ms            | 170 ms / 6.3 s            | 130 ms / 316 ms       | 1.1 s / 1.7 s          | 131 ms / 303 ms    | 232 ms / 1.7 s     |

Past the knee the tail grows on every page at once, cheap ones included (the store's p95 goes from 0.14–0.58 s to
1.1–4.3 s): requests queue for CPU and PHP threads, not for one slow page.

### What limits first

- **CPU (measured).** At every knee the stack was at 75–83% of its CPU cap on average (small, fast users), or at the
  cap for part of the level (people arriving), and the request rate stopped growing past it: small 16 req/s at 100
  fast users at 97% of the cap, medium 31 req/s at 150 at 88%. The small host costs about 0.1 core-seconds per request
  (1.5 cores for 15 req/s; inferred).
- **PHP and PostgreSQL share it about evenly (measured).** At the small knee the app used 75% of one core and
  PostgreSQL 66%; at the medium knee 103% and 104%, then 164% and 158%. Neither ran out alone.
- **PHP threads (inferred, not separately measured).** Latency rose on every page at once while CPU neared the cap,
  which is what queueing for 4 (or 8) threads on a busy CPU looks like. No level showed spare CPU with a growing
  queue outside the arrival windows, so the thread count has not been seen to limit before CPU. A run with more
  threads on the same cap would tell.
- **PostgreSQL connections and memory (measured): not a limit.** No connection errors at any level (40 connections
  on small, 60 on medium). PostgreSQL peaked at 344–352 MB (small) and 792–796 MB (medium) within 1,024 and 2,560 MB.
  The heaviest web queries were the staff search (`lower(name) like`, 14–18 ms mean, the most calls), the invoice
  number search (14 ms), the services product filter (`select distinct product_id`, 86–124 ms mean) and the admin
  dashboard aggregates (0.6–1.0 s mean, a few calls).
- **Memory (measured): not close.** The containers together peaked at 991–1,133 MB during the small web levels and
  1,717–1,761 MB during the medium ones.

### Background work in the 2026-10-31 runs

- **Small: the billing day did not finish (measured).** In all four small runs `automation:run` stopped after two
  of its tasks (as in PR #552's run 41200682198) (it ran for 221.6 s in the one checked), and the summary printed 0.0 s because it adds up the tasks
  that finished; PR #543 now records the exit code and says so. The medium scheduler needed 271–272 MB for the same
  billing day, and the small profile then limited the scheduler to 256 MB (production gives it 512 MB), so the failures
  were most likely that limit (inferred until a run with the exit code confirms it). PR #543 raises the small
  profile's scheduler to 512 MB, as in production, and records the exit code.
- **Medium: 3,059 s and 3,078 s (measured),** almost all of it renewals: 11,623 in 2,865–2,874 s, **4.0 per second**,
  against 24.8 per second on 2026-10-23 (small profile, commit `c5c0ffa`). The queue drain took 369–375 s for 12,572
  jobs (34 per second, against 117.6). Not investigated; see "Not measured yet".
- **Invoice PDF (one process, measured):** p50 120–169 ms.

## History: small profile at 5–40 users, run 40000321793

The reference until 2026-10-31. Its 40-user level matches the newer runs (p95 704 ms against 669 ms).

From run 40000321793 (2026-10-23, commit `c5c0ffa`, users arriving over 20 s).

#### Web load per level

| Virtual users | req/s | p50    | p95    | p99    | Errors |
| ------------: | ----: | ------ | ------ | ------ | -----: |
|             5 |   3.3 | 48 ms  | 105 ms | 307 ms |      0 |
|            10 |   4.7 | 50 ms  | 119 ms | 356 ms |      0 |
|            20 |   9.0 | 55 ms  | 236 ms | 470 ms |      0 |
|            40 |  16.4 | 120 ms | 704 ms | 1.5 s  |      0 |

No request errors, no 429 answers and no content errors at any level. 40 virtual users was still "comfortable" (p95
under 1 s). During the 40-user level the app container averaged 75% of one core (peak 129%) and PostgreSQL 66%
(peak 137%), together about 70% of the 2 vCPU cap on average.

#### Per page

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

#### Background work

| Measure                           | Result                                                                                                    |
| --------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Billing day (`automation:run`)    | 539.3 s                                                                                                   |
| Renewals                          | 12,055 in 486.7 s, 24.8 per second                                                                        |
| Reminders                         | 2,213 in 47.9 s                                                                                           |
| Queue drain after the billing day | 14,394 jobs in 122.4 s (117.6 per second), 0 failed                                                       |
| Invoice PDF (dompdf, one process) | p50 161 ms, p95 171 ms, 6.2 renders per second, 858 KB per file                                           |
| Slowest automation query          | the `email_messages` insert: 14,289 calls, 7.2 s in total                                                 |
| Slowest web queries               | the admin dashboard's income, receivables and MRR aggregates: means of 493, 486 and 397 ms, 8–9 runs each |

#### Compared with the runs before PR #433

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

#### Re-run of 2026-10-31 (PR #552)

Run 41200682198: the same harness settings as the reference (small profile, large volume, users arriving over
20 s), on commit `f9fe390`, PR #552 merged into `develop`. PR #552 adds the database review's indexes (open and
cancelled invoices for the dashboard receivables, open invoices and live services by id for the billing day, the
client invoice order, `jobs (queue, id)`). Seed: 50,000 clients, 100,000 services, 355,350 invoices, 900 MB.

| Measure                        | 40000321793 (2026-10-23, `c5c0ffa`) | 41200682198 (2026-10-31, `f9fe390`) |
| ------------------------------ | ----------------------------------- | ----------------------------------- |
| All pages, 40 users: p50 / p95 | 120 ms / 704 ms (p99 1.5 s)         | 342 ms / 1.26 s (p99 1.65 s)        |
| req/s at 40 users              | 16.4                                | 15.1                                |
| Highest comfortable level      | 40 users                            | 20 users                            |
| Admin dashboard, light         | 219 ms / 960 ms                     | 259 ms / 284 ms                     |
| Admin dashboard, 40 users      | 828 ms / 3.1 s                      | 960 ms / 2.0 s                      |
| Client dashboard, light        | 47 ms / 61 ms                       | 69 ms / 95 ms                       |
| Client dashboard, 40 users     | 138 ms / 546 ms                     | 335 ms / 1.14 s                     |
| Client invoices, light         | 44 ms / 55 ms                       | 58 ms / 76 ms                       |
| Client invoices, 40 users      | 100 ms / 586 ms                     | 302 ms / 1.08 s                     |
| Checkout, light p95            | 411 ms (one request)                | 665 ms / 727 ms                     |
| Checkout, 40 users             | p95 2.1 s                           | 1.87 s / 3.06 s                     |
| Receivables aggregate (mean)   | 486 ms                              | 494 ms (7 calls)                    |
| Billing day (`automation:run`) | 539.3 s                             | not measured (see below)            |
| App / PostgreSQL CPU, 40 users | 75% / 66% of one core               | 78% / 82% of one core               |

These two runs cannot isolate what the indexes did. Pages the indexes do not touch are also slower: checkout,
lightly loaded, went from 411 ms to 727 ms. The code moved on by eight days, and the runner may differ (see "Compared
with the runs before PR #433"). The admin dashboard's light p95 of 284 ms is most likely #441 (`Cache::flexible`),
which this runner had not measured before. The receivables aggregate costs the same as before: at this volume its
paid-and-refunded branch matches about a third of the year's invoices and is read by a sequential scan, which
PR #552 does not change. The branches PR #552 indexes went from 12.7 ms to 2.3 ms in its EXPLAIN (PR #552
description).

The billing day was not measured. The harness's `automation:run` recorded only `platform.exchange-rates` and
`domains.renewals`, and its summary shows 0.0 s. Meanwhile the automation phase lasted 144 s and wrote 616 renewal
invoices. PR #543's small-profile runs on `develop` (`e33b108`, run 41193623951) show the same; its medium-profile run
completed a 3,059 s billing day. The small profile gives the scheduler container, where `automation:run` runs,
256 MB, and the highest sample there was 234 MB, so the run may have been killed for memory. That has not been
confirmed.

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
| Runners of 2026-10-31 (runs 41193623951 and 41198972155), sum of each container's peak in a web level, 50,000 clients                                       | small: 991–1,133 MB; medium: 1,717–1,761 MB. Not one moment's total: each container's own peak, added up, so an upper estimate                                                                                                             |
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

| Sign                                                                                                                                                           | What to do                                                                                                                                                         |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sign-in and dashboards have a multi-second p95 while the median is fine, in steady use                                                                         | More vCPU first, and the PHP thread count that goes with it: the medium profile (4 vCPU, 8 GB, 8 threads), measured at 400 people at once against 200–350 on small |
| Many people sign in at once (measured: 28 at once gave a sign-in p50 of 1.3–1.8 s and a dashboard and store p95 of 1.2–3.0 s, "Where the small host runs out") | The same: more vCPU and PHP threads if those bursts matter to you, otherwise accept a few slow seconds. The bcrypt cost is a security setting and stays unchanged  |
| Available memory under about 1 GB during a deploy (`free -m`; a guide, not a measurement)                                                                      | Run `deploy/bin/deploy retire` after each deploy, then move to the medium profile (4 vCPU, 8 GB)                                                                   |
| More than about 200 people signed in at once on the small host, or 400 on the medium one (measured, "Capacity"), or over 50,000 clients                        | Move up one profile; above medium, run the large profile on an 8-CPU runner before sizing (not measured)                                                           |

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

- **The medium profile on a real 4 vCPU host.** It was measured on a 4-CPU runner that also ran k6, so its numbers are a
  lower bound ("Capacity").
- **The large profile** (8 vCPU, 16 GB). It needs a runner with 8 CPUs.
- **Whether runner speed or arrival speed moved the small host's limit** from 200–300 people (run 41193623951) to
  more than 350 (run 41198972155). Recording the runner's CPU model (PR #543) and running both arrivals on one runner
  would separate them.
- **More PHP threads on the same cap,** which would show whether threads ever limit before CPU.
- **The billing day on the small profile** (it did not finish in the 2026-10-31 runs, most likely the scheduler's
  256 MB limit then in `loadtest/profiles/small.env`, now 512 MB; inferred until a run with the exit code confirms it), and **why medium's renewals ran at 4.0 per
  second** against 24.8 on 2026-10-23 (#566).
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
