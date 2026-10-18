# ADR 0014 — Observability baseline and queue topology

- Status: Proposed · 2026-10-18 · Owner: Principal architect
- Related: `product-vision.md` (OpenTelemetry), `docs/handoff/state.md` "Tracked gaps", roadmap wave 4 (system health
  and automation watchdog).

## Context

- **No correlation.** Nothing sets a request id or uses Laravel's `Context`. A failed provisioning job cannot be tied
  to the order request that queued it.
- **Logs** are the default `single` channel as plain text. Redaction exists in several places (Services `Redactor`,
  Domains `RegistrarGateway`, `GatewayConfig::secretValues()`), with no shared rule.
- **Integration calls** are logged in five tables, each with its own shape: `module_commands`,
  `registrar_command_log`, `gateway_logs`, `webhook_deliveries` and `email_messages` (#28). The fields are not shared.
- **Health checks** live only on the dashboard (`Dashboard::health()`, #41). The wave-4 watchdog will need the same
  checks without a page view.
- **Queues are implicit.** Services uses `provisioning`, Notifications uses `notifications` and `webhooks` (#28), and
  Domains, Orders and Platform jobs use `default`. The only worker definitions are `DevCommands` registered by
  Services (`provisioning,default`) and Notifications (`notifications,webhooks`). With `MODULES=` excluding Services,
  nothing works the `default` queue that Domains relies on. Production topology is not documented.

## Decision

### Correlation

- A global middleware sets `Context::add('request_id', …)`. It takes the value from `X-Request-Id` when the request
  comes through a trusted proxy, and otherwise generates a UUIDv7. It echoes the id in the response header.
- The middleware also adds `actor` (user id, staff flag, impersonator) and `client_id` once they are known.
- Laravel propagates `Context` into queued jobs and into every log record. Jobs keep the id of the request or
  automation run that started them.
- `automation:run` adds `automation_run` and the current task key. `ExecuteModuleCommand`, `RegistrarGateway` and
  the payment reconciler add `integration`, `integration_key` and their log-row id.
- Secrets never go into `Context`, hidden or visible.

### Logs

- In production, `LOG_STACK=json_stderr`: Monolog `JsonFormatter` to stderr, collected by the platform.
- Unexpected exceptions go through `report()`. Expected outcomes (declines, retryable failures) are not exceptions in
  logs. They are rows in the owning integration log.
- One host-side `Redactor` (ADR 0009) is applied by every integration log writer.

### Integration call log contract

Each owning table keeps its schema but must have these columns or their equivalents:

- `integration` (gateway, provisioning, registrar, rates, webhook, mail)
- `integration_key`
- `operation`
- `outcome` (completed, pending, rejected, retryable, unknown: the ADR 0009 vocabulary)
- `duration_ms`
- `attempt`
- `request_id`
- redacted request and response

Retention is a setting per table. The fields are what an OpenTelemetry span will need, so later instrumentation adds
nothing to the schema.

### Telemetry seam (no dependency now)

The kernel contract `App\Contracts\Observability\Telemetry` has three methods:

```php
public function span(string $name, array $attributes, Closure $work): mixed;
public function count(string $metric, int $by = 1, array $attributes = []): void;
public function timing(string $metric, int $milliseconds, array $attributes = []): void;
```

- Core binds a no-op implementation. The observability workstream binds an OpenTelemetry one later, adding packages
  under ADR 0007 with CTO approval and the 7-day cooldown.
- The first instrumented points: automation tasks, integration calls, webhook and mail sends, queue job
  duration and wait time.
- Metric names are `mohar.<area>.<thing>` with low-cardinality attributes: never ids, never emails.

### Health checks

- A kernel registry, `App\Support\Health\HealthChecks`, follows ADR 0012:
  `register(key, class)` with `check(): HealthResult(status, label, detail, ?href)`.
- `Dashboard::health()` (#41) reads from it once the watchdog lands. Until then the dashboard registration stays,
  and the migration is a pure move.
- Initial checks:
    - scheduler heartbeat
    - last automation run
    - queue oldest-job age per queue
    - provisioning, registrar and gateway failure rates over 7 days
    - mail and webhook failures
    - storage disk reachability

### Queue topology

- Queue names are constants in `App\Support\Queues`:

    | Constant        | Queue           |
    | --------------- | --------------- |
    | `DEFAULT`       | `default`       |
    | `PROVISIONING`  | `provisioning`  |
    | `REGISTRAR`     | `registrar`     |
    | `PAYMENTS`      | `payments`      |
    | `NOTIFICATIONS` | `notifications` |
    | `WEBHOOKS`      | `webhooks`      |

    Jobs use the constants, and no other queue names are allowed.

- Domains jobs move to `registrar`, so a registrar outage cannot starve other `default` work.
- Core alone registers one `DevCommands` worker over all queues in priority order:
  `payments,provisioning,registrar,notifications,webhooks,default`. Modules no longer register workers.
- `docs/devops/production.md` (to be written) describes the production worker set:
    - one worker pool for `payments,provisioning,registrar`, with job timeouts above the drivers' HTTP timeouts;
    - **invariant (amended 2026-10-18, ADR 0009 PR B):** each connection's `retry_after` exceeds the longest job
      timeout on any queue it carries plus its lock slack (provisioning: `max_job_timeout_seconds` 180 s + 20 s, so
      `retry_after` defaults to 240 s); a pool's `--timeout` is at least that cap; on SQS, set the visibility timeout
      per queue. A dedicated provisioning connection is revisited only if a job needs more than 300 s;
    - one pool for `notifications,webhooks`;
    - one pool for `default`.
- Every job that calls a provider is `ShouldBeUnique` or protected by a lock, sets `$tries` and `$backoff`, uses
  `ShouldBeEncrypted` if its payload holds personal data, and is idempotent (ADR 0011).

### Performance budgets

Query-count tests, as `DashboardQueryCountTest` does for the dashboard in #41, are required for:

- the admin dashboard;
- client and admin invoice, service and domain lists;
- checkout.

The CI budget per page is set when each test is added. A new N+1 query fails the build.

## Consequences

- One id follows a click through jobs, driver calls and logs, so support can answer "what happened to this order".
- OpenTelemetry becomes a binding plus configuration, not a refactor.
- Three small Core PRs (correlation and logs, the `Queues` constants and worker, the `HealthChecks` registry), plus a
  devops doc. No new dependencies until the OpenTelemetry PR.
