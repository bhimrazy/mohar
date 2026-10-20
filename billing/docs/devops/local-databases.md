# Local and test databases: options lighter than a Docker PostgreSQL

Status: decision proposal, research only (nothing below was installed or run). Read 2026-10-20.
Owner question: is there something lighter than a Docker container for PostgreSQL, locally and in tests?

## Short answer

No option on the market today is both lighter than our tmpfs container and faithful enough to replace it.
The two "embedded Postgres" projects that look lightest (PGlite and pgrust) are young, and neither can safely
serve several PHP processes at once. A lighter route that is safe is **native PostgreSQL 18 binaries without
Docker** (distro or PGDG packages, or a portable binary bundle). It is the same server, so fidelity is full.
It saves the container layer, but it is not a different class of tool.

| Use                      | Recommendation                                                                                 |
| ------------------------ | ---------------------------------------------------------------------------------------------- |
| (a) Owner's preview      | The `postgres` service in `billing/compose.yaml` (persistent `postgres-data` volume, same pin) |
| (b) Local engineer tests | SQLite for the inner loop; `scripts/pg.sh` + `composer test:pgsql` before pushing (unchanged)  |
| (c) CI                   | Keep the digest-pinned `postgres:18.6-alpine` service; spike tmpfs + `fsync=off` for speed     |
| (d) Tiny self-hosted     | Real PostgreSQL 18: OS/PGDG packages or the compose service. Not SQLite, PGlite or pgrust      |

## What we need from the database

The app runs a web server, queue workers and a scheduler at the same time, all through `pdo_pgsql`. What that
means in practice (grep of `billing/app` and `billing/database`, 2026-10-20):

- **Row locks.** `lockForUpdate()` appears in 56 files. Laravel's `database` queue (our default
  `QUEUE_CONNECTION`) pops jobs with `FOR UPDATE SKIP LOCKED` on PostgreSQL. Cache locks (ADR 0017) and
  sessions also use the database store.
- **`CREATE INDEX CONCURRENTLY`** in index migrations (skipped on SQLite).
- **Not in the code yet, but expected:** advisory locks, `pg_trgm` trigram indexes for search, JSONB
  columns and `ILIKE`. Each candidate is scored on these too, so a choice made now does not block them.
- **Real concurrency.** Several connections must have their own sessions, so that lock waits, `SKIP LOCKED`,
  deadlocks and aborted transactions behave as they do in production. The load-test work (PR #108) and the
  findings from it are about PostgreSQL behaviour, so they only reproduce on PostgreSQL.

ADR 0006 already rejected PGlite (2026-10-17) because `pglite-socket` served one connection at a time. This
document checks again with current releases and widens the field.

## Options

### 1. PGlite and pglite-socket

PGlite is PostgreSQL compiled to WebAssembly. It runs inside a JavaScript runtime (browser, Node, Bun, Deno).
Since `@electric-sql/pglite` 0.5.9 (2026-10-12) it is built on **PostgreSQL 18.6**. The latest release is
0.5.10 (2026-10-14). The README still labels it "Alpha". Its licence is Apache-2.0 or the PostgreSQL licence.
It has about 16.2k GitHub stars, and its last commit was 2026-10-19. 33 contrib extensions are bundled,
`pg_trgm`, `btree_gin`, `pgcrypto` and `uuid-ossp` among them, and pgvector ships as a separate package.

- **PHP cannot load it directly.** It is a JS library. The only way in is `@electric-sql/pglite-socket`
  (0.2.13, 2026-10-14), a Node TCP server that speaks the Postgres wire protocol in front of one PGlite
  instance. SSL is not supported, so you set `sslmode=disable`. Running it means adding a Node package and
  a long-running Node process, which needs approval under ADR 0007.
- **One session for all clients.** PGlite runs Postgres in single-user mode: "PGlite is single
  user/connection" (README). pglite-socket can accept several TCP connections by multiplexing them onto
  that one session, and its docs warn that "not all use cases are guaranteed to work". Open bugs confirm it:
  #1046 (extended-protocol messages from two connections interleave, which gives wrong rows or a deadlocked
  queue), #985 (the queue deadlocks when a connection leaves mid-transaction) and #958 (an early
  `ReadyForQuery` breaks Prisma).
- **What that means for us (inferred from the code, not tested).** `pdo_pgsql` prepares statements on the
  server and names them `pdo_stmt_%08x` with a counter that each connection keeps for itself (php-src,
  `ext/pdo_pgsql/pgsql_driver.c`). Two PHP processes sharing one session would both create
  `pdo_stmt_00000001`. That is the #1046 failure, and worse. Even when it works, everything is serialised,
  so the semantics are wrong:
    - `SKIP LOCKED` never skips anything.
    - An advisory lock taken by "worker A" is re-entrant for "worker B", because both are the same backend.
    - A long transaction in a queue worker stalls every web request.
- **Performance.** The PGlite benchmark page shows it roughly 2 to 2.5 times slower than native Postgres on
  simple statements, and far slower than in-memory SQLite. Startup is fast, but memory figures are not
  published.

Verdict: good for JS unit tests and browser apps. It is not a fit for (a) to (d) here. Revisit only if
pglite-socket gains a real multi-session mode, which would need upstream Postgres changes to WASM process
handling, and nothing on the roadmap shows that.

### 2. pgrust

pgrust is a from-scratch rewrite of PostgreSQL in Rust. It speaks the wire protocol, uses the PostgreSQL
dialect and reports `PostgreSQL 18.6 (pgrust 0.3)`. The repository was created on 2026-04-20. v0.3.1 came
out on 2026-10-19. It has about 5.3k stars and 96 issues, a single maintainer, and it does not accept pull
requests. Licence: **AGPL-3.0**, with the parts derived from PostgreSQL under the PostgreSQL licence.

- It claims to pass all 46,066 tests in the PostgreSQL regression suite. It runs connections as threads, so
  several sessions work, and real row locks are plausible: there is a `nodelockrows` executor crate. It
  ports contrib modules, `pg_trgm` included, but external extensions do not load.
- The README is honest about the limits: "still has a lot of bugs", not recommended for production. It is
  tuned for AWS Graviton4, and its JIT targets only that CPU.
- **It is not lighter.** Issue #137 (2026-09-29) measured a Django suite: idle memory 224 MiB against 57 MiB
  for PostgreSQL 18, peak 2.0 to 2.3 GiB against about 600 MiB, and 10 to 55% slower. It ships as a Docker
  image or as native binaries, so the delivery is the same as PostgreSQL itself.

Verdict: interesting engineering, wrong tool here. The fidelity risk is high and nothing is gained over
real PostgreSQL. The AGPL licence would also matter if we ever bundled it in (d).

### 3. Native PostgreSQL 18 without Docker

- **Distro or PGDG packages.** The devcontainer is Ubuntu 24.04. Its own archive offers PostgreSQL 16, and
  the PGDG apt repository (apt.postgresql.org) adds 18.x. This is the real server, so fidelity and
  concurrency are full and every contrib extension is there. Idle memory is tens of MiB (57 MiB in the
  container measured in pgrust #137). Startup is about a second after `initdb`; we have not measured it
  here. Each version must be pinned exactly. It needs `sudo apt` in the image, and Codespaces has no
  systemd, so something must start the server after every reboot (the codespace rebooted three times on
  2026-10-19).
- **Portable binary bundles.** These are tarballs that unpack and run, with no root needed:
    - **zonky `embedded-postgres-binaries`**: Apache-2.0, 218 stars, v18.6.0 on 2026-08-28. About 10 MB per
      platform, for testing. It is built with `make install-world-bin` and contrib, so `pg_trgm` is
      included. It is published as Maven artifacts.
    - **theseus-rs `postgresql-binaries`**: PostgreSQL licence, 80 stars, tag 18.6.1. GitHub release
      tarballs for glibc and musl.
    - **Wrappers** that download these bundles: the `embedded-postgres` npm package (MIT, 148 stars, still
      `18.4.0-beta.17`), Go `fergusstrange/embedded-postgres` (MIT, 1.25k stars, v2.0.1 on 2026-10-19) and
      Java zonky. None is for PHP. A 30-line shell script that downloads one pinned, checksummed tarball would
      do the same job.
    - Windows "PostgreSQL portable" builds are irrelevant on Linux.
- **pg_tmp (ephemeralpg).** A shell script that runs `initdb` and starts a throwaway server on a random port
  or socket with fsync off, and can pre-initialise one in the background. ISC-style licence, 233 stars,
  v3.4, last commit 2026-06-25. It still needs the binaries above.

Verdict: the only lighter option that is also faithful. It does not run all PHP processes against one
shared server any better than `pg.sh` does. The gain is no Docker daemon, on a machine where Docker
already runs RustFS and the simulators.

### 4. Neon (Neon Local, branching)

Neon Local is a Docker proxy to a **Neon cloud** branch, not a local Postgres. It needs an API key and a
network connection, and it creates or deletes a branch when the container starts or stops. Self-hosting the
Neon stack (Apache-2.0, 23k stars, last push 2026-08-31) means a pageserver, safekeepers and compute, which
is heavier than one container. Branching is a real advantage for previews of production-sized data. It
does not make anything lighter, and it would send data off the machine. Not a fit for (a) to (d).

### 5. Docker PostgreSQL on tmpfs (what we have)

`scripts/pg.sh` runs one shared `postgres:18.6-alpine` (digest-pinned, the same image as CI and
`compose.yaml`) on port 55432. It has a 2 GB tmpfs data directory, one database per worktree and
`max_locks_per_transaction=256`. It does **not** set `fsync=off`. On tmpfs, fsync costs almost nothing, so
adding `-c fsync=off -c synchronous_commit=off -c full_page_writes=off` is a small, harmless gain. Today
it uses about 595 MiB (`docker stats`), and that includes the tmpfs data of every worktree's test databases.
It has full fidelity and is already approved.

### 6. Testcontainers

`testcontainers/testcontainers-php` (MIT, 214 stars, 1.1.0 on 2026-09-14, PHP 8.1+) starts a container per
test run from PHP code. It is still Docker underneath, so it is no lighter. It adds a Composer dependency to
do what `pg.sh` already does. Not worth it.

### 7. SQLite (what we already use for speed)

SQLite is in-process and has no server, so it starts instantly. That is why it is the fast local suite. What
it does not test:

- **Row locks.** `lockForUpdate()` compiles to nothing, there is no `SKIP LOCKED`, and the whole database
  has one writer. Lock waits and deadlocks never happen, so the queue's pop logic is a different code path.
- **No advisory locks** and **no `CREATE INDEX CONCURRENTLY`** (our migrations branch around it).
- **Text search.** There is no `ILIKE` and no trigram GIN index. `LIKE` is case-insensitive for ASCII
  only, so case bugs hide (architecture rule 1).
- **JSON** is stored as text with JSON1 functions. There are no JSONB operators or GIN indexing.
- **Types are loose.** Length limits are not enforced and type affinity accepts wrong types (rules 5, 6 and
  9). Enum `CHECK`s exist, but changing one rebuilds the table.
- **A failed statement does not abort the transaction** (rule 7).
- Load-test results do not carry over.

For a preview with web, workers and scheduler all writing, expect `database is locked` waits under load,
even with WAL.

## Comparison

"Full" means it is the real PostgreSQL 18.6 server. Start times are typical figures, not measured here;
memory comes from the cited source or `docker stats`.

| Option                 | Works with pdo_pgsql      | Fidelity to PG 18         | Several sessions at once   | Start / memory                  | Licence       | Maturity (2026-10-20)                     |
| ---------------------- | ------------------------- | ------------------------- | -------------------------- | ------------------------------- | ------------- | ----------------------------------------- |
| Docker tmpfs (`pg.sh`) | yes                       | full                      | yes                        | a few s / ~600 MiB shared now   | PostgreSQL    | in use, pinned image                      |
| Native PGDG packages   | yes                       | full                      | yes                        | ~1 s / tens of MiB idle         | PostgreSQL    | upstream, decades                         |
| Portable bundles       | yes                       | full (contrib included)   | yes                        | ~1 s / tens of MiB idle         | Apache / PG   | zonky v18.6.0 2026-08-28; theseus 18.6.1  |
| pg_tmp                 | yes (needs binaries)      | full                      | yes                        | fast with pre-init (unmeasured) | ISC-style     | v3.4, last commit 2026-06-25              |
| PGlite + pglite-socket | through a Node proxy only | PG 18.6 core, single-user | **no**: one shared session | fast / not published            | Apache / PG   | "Alpha", 0.5.10 2026-10-14; open mux bugs |
| pgrust                 | yes (wire compatible)     | claimed; "lots of bugs"   | yes (threads)              | 224 MiB idle, 3–4× PG (#137)    | **AGPL-3.0**  | v0.3.1 2026-10-19, one maintainer         |
| Neon Local             | yes (to a cloud branch)   | Neon Postgres             | yes                        | Docker + network                | cloud service | needs a Neon account                      |
| Testcontainers PHP     | yes                       | full (it's Docker)        | yes                        | same as Docker                  | MIT           | 1.1.0 2026-09-14                          |
| SQLite                 | no (pdo_sqlite)           | none                      | one writer                 | instant / in-process            | public domain | mature                                    |

| Option                 | (a) preview        | (b) local tests          | (c) CI             | (d) tiny install          |
| ---------------------- | ------------------ | ------------------------ | ------------------ | ------------------------- |
| Docker tmpfs / compose | **yes** (volume)   | **yes** (`pg.sh`)        | **yes** (service)  | **yes** (compose)         |
| Native packages        | yes, if no Docker  | possible                 | no gain            | **yes** (bare metal)      |
| Portable bundles       | possible           | spike candidate          | no gain            | no (use OS packages)      |
| PGlite                 | no                 | no                       | no                 | no                        |
| pgrust                 | no                 | no                       | no                 | no (immature, AGPL)       |
| Neon Local             | no                 | no                       | no                 | no                        |
| Testcontainers         | no                 | no (duplicates `pg.sh`)  | no                 | no                        |
| SQLite                 | no (moving off it) | **yes** (fast loop only) | **yes** (as today) | no (install.md: dev only) |

## Recommendation

- **(a) Preview.** Move it to the `postgres` service in `billing/compose.yaml`: the same digest pin as CI,
  data in the `postgres-data` named volume so it survives the codespace reboots, and a database of its own
  (not the shared tmpfs server, which loses its data on every stop). Do not move it to `pg.sh`: it is test
  data on tmpfs.
- **(b) Local tests.** No change. SQLite stays the fast inner loop. `eval "$(scripts/pg.sh <slug>)"` plus
  `composer test:pgsql` runs before pushing anything that touches queries, locks or migrations. Small
  change worth making: add `-c fsync=off -c synchronous_commit=off -c full_page_writes=off` to `pg.sh`
  (test data only).
- **(c) CI.** Keep the pinned service container, which is the gate defined in ADR 0006. The PostgreSQL step
  has run close to its time limit, so a measured spike is worth doing (next section).
- **(d) Tiny self-hosted.** Real PostgreSQL 18, from OS or PGDG packages on bare metal, or the compose
  service. SQLite stays development-only, as `install.md` says. PGlite and pgrust are ruled out on fidelity,
  concurrency and, for pgrust, the licence.

## Worth a later spike (each needs owner approval first)

1. **CI speed (small, no new dependency).** Add `--tmpfs /var/lib/postgresql` to the service `options`, and
   run `ALTER SYSTEM SET fsync = off` plus `full_page_writes = off` and `pg_reload_conf()` before the suite.
   Compare the PostgreSQL job time over five runs before and after.
2. **Docker-free PostgreSQL for engineers (optional).** A `scripts/pg-native.sh` that downloads one pinned,
   checksummed zonky or theseus 18.6 tarball. It would run `initdb` once and start on a Unix socket with
   fsync off. Measure startup, idle memory and suite time against `pg.sh`. Adopt it only if it clearly beats
   the container, since it adds a binary supply-chain item under ADR 0007.
3. **Watch, do not adopt.** Review again in about six months:
    - PGlite, if pglite-socket gets true per-connection sessions and #1046 and #985 are closed;
    - pgrust, if it reaches a stable 1.0 with external audits and its memory use comes down.

## Sources (all read 2026-10-20)

- PGlite: https://pglite.dev/docs/about, https://pglite.dev/docs/pglite-socket, https://pglite.dev/extensions/,
  https://pglite.dev/benchmarks, https://github.com/electric-sql/pglite (README, releases 0.5.9 and 0.5.10,
  issues #958, #985, #1046)
- pgrust: https://github.com/malisper/pgrust (README, RELEASE-NOTES.md, `crates/contrib`, issue #137)
- php-src `ext/pdo_pgsql/pgsql_driver.c` (PHP-8.4 branch): statement naming `pdo_stmt_%08x`
- zonky: https://github.com/zonkyio/embedded-postgres-binaries (README, `scripts/build-postgres-debian.sh`,
  releases); https://github.com/zonkyio/embedded-postgres
- https://github.com/theseus-rs/postgresql-binaries; https://github.com/leinelissen/embedded-postgres;
  https://github.com/fergusstrange/embedded-postgres
- pg_tmp: https://eradman.com/ephemeralpg/, https://github.com/eradman/ephemeralpg
- Neon Local: https://neon.com/docs/local/neon-local; https://github.com/neondatabase/neon
- Testcontainers: https://github.com/testcontainers/testcontainers-php
- PGDG apt repository: https://apt.postgresql.org (Ubuntu 24.04 archive offers PostgreSQL 16)
- In repo: ADR 0006, ADR 0007, ADR 0017, `scripts/pg.sh`, `billing/compose.yaml`, `.github/workflows/ci.yml`,
  `docs/devops/install.md`, `docs/architecture.md` (PostgreSQL portability rules)
