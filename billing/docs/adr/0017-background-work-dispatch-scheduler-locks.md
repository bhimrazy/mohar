# ADR 0017 — Background work: dispatch, scheduler and locks

- Status: Proposed · 2026-10-19 · Owner: Principal architect
- Related: ADR 0011 (event dispatch after commit), ADR 0014 (queue topology), `docs/devops/deployment-options.md`,
  `docs/devops/install.md` §6, issue #64, architecture review `docs/reviews/2026-10-19-architecture.md` (Q1–Q4, S1,
  S2).

## Context

The deployment plan runs one image as web, three worker pools and exactly one scheduler. The code makes three
assumptions about that topology without writing them down:

- **After-commit dispatch of jobs is done three ways:**
    - `->afterCommit()` in Services and Notifications;
    - `DB::afterCommit` closures in Domains;
    - nothing, in `DomainActionController`, `ResubmitTransfer` and `PlaceOrder`.

    Every connection in `config/queue.php` sets `after_commit => false`. That is correct today only because the
    `database` queue writes the job row inside the caller's transaction. With Redis, which the plan allows, a job can
    run before its rows are committed, or after they were rolled back.

- **The scheduler process calls providers inline.** `services:verify-unknown` and `billing:reconcile-payments` run
  every 5 minutes and `domains:sync` runs hourly, all in the one `schedule:work` process. A slow WHM server, gateway
  or registrar delays every other entry: the heartbeat, webhook retries and `automation:run`.
- **Locks need a shared store.** There are ten `Cache::lock` sites, among them payment capture, checkout idempotency
  and the provisioning service lock. `ShouldBeUnique`, `withoutOverlapping()` and `onOneServer()` rely on the same
  store. Nothing checks that every process shares it (#64).
- **Timeouts are inconsistent.** One job runs longer than the queue's `retry_after` (`RunAutomationJob`, 3600 s
  against 240 s). The install hint prints a pool timeout (85 s) below the documented one (200 s).

## Decision

1. **Jobs dispatch after commit, by configuration.**
    - Every queue connection sets `after_commit => true`.
    - `->afterCommit()` calls and `DB::afterCommit(fn () => Job::dispatch())` closures are removed.
    - A job that must run inside the transaction opts out with `->beforeCommit()` and a comment explaining why.
    - Events follow ADR 0011 (`ShouldDispatchAfterCommit`).
2. **The scheduler only selects and dispatches.**
    - A scheduled entry may read rows and dispatch jobs. It never calls a driver, a gateway, a registrar or any
      other remote host.
    - Sweeps dispatch one job per item. The job is `ShouldBeUnique`, keyed by the item, and runs on the family's
      queue (`provisioning`, `payments`, `registrar`).
    - Every scheduled entry has `->onOneServer()` and `->withoutOverlapping()`.
    - Daily work is an `Automation` task. Sub-daily polling is a schedule entry. Nothing is both.
3. **Every job's timeout is below its queue's `retry_after`.**
    - Each job sets `$timeout` and `$tries` explicitly.
    - The invariant `$timeout + lock slack < retry_after` is checked by one unit test that reads every job class
      under `app/Modules/*/Jobs`.
    - Work that may take longer than a pool allows, such as the automation "Run now" button, gets its own queue
      with its own `retry_after`. Alternatively, it is run by the scheduler process from a "requested" flag.
    - **Chosen in #122 (2026-10-20): its own connection.** "Run now" runs on the `automation` connection and queue
      (`App\Support\Queues`, ADR 0014), with `retry_after` 3,900 s above the job's 3,600 s timeout. The
      "requested" flag option is not used.
4. **One shared lock store is a deployment requirement.**
    - The `app:install` pre-flight and the health check fail when `cache.default` resolves to `array` or `null`.
    - The `file` driver is accepted only for a single-host, single-container install, and the pre-flight warns.
    - Locks are taken through `Cache::lock` on the default store, never on a named local store.
5. **Queue names are constants** (`App\Support\Queues`, ADR 0014). The worker hints in `InstallCommand`,
   `install.md` and `deploy/` are generated from, or tested against, those constants.

## Consequences

- One rule for every module, and it holds on Redis and on SQS. The adopting PR runs the full suite on SQLite and on
  PostgreSQL, because jobs that ran inside the transaction now run after it.
- The scheduler stays responsive whatever a provider does. Provider calls get the queue's retry, timeout and
  failed-job handling.
- `services:verify-unknown` moves its per-item check into a job on `provisioning`, built with a `deadline` like any
  other command.
- Shared hosting in "cron mode" keeps working: the default `database` cache store is shared by every process.
- Enforcement:
    - an Arch rule forbids `App\Contracts\*\Provisioning`, registrar and gateway driver types in
      `App\Modules\*\Console\Commands`;
    - the unit test from decision 3 checks job timeouts;
    - an `EnvironmentChecks` test checks the lock store.
