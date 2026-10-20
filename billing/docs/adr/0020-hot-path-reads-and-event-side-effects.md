# ADR 0020 — Hot-path reads and event side effects

- Status: Proposed · 2026-10-20 · Owner: Principal architect
- Related: ADR 0011 (events carry ids, after-commit dispatch), ADR 0014 (performance budgets, queues), ADR 0017
  (background work), load test #108, performance PR #166, architecture review `docs/reviews/2026-10-20-architecture.md`
  (F5).

## Context

The 50k-client load test found the admin dashboard at p50 28.5 s, invoice search at 3.8 s, and renewals at 20/s. #166
fixes the dashboard and search symptoms. Its renewal measurement shows 42.7 queries and 104 ms per renewal, and
three causes that come from the architecture, not from one module:

- **Side effects run in the producer.** Notifications' `SendEventEmails` is a synchronous listener for about 40
  events. For each one it resolves the template, translations and preferences, resolves merge fields, dedupes, and
  writes `email_messages`, all before it queues the send. `PublishEventWebhooks` reads the webhook endpoints for each
  event. These run after commit, but in the same process: an automation run pays about 10 queries and a template
  render for every invoice it issues.
- **Every consumer re-reads.** ADR 0011 rightly makes events carry ids, not models. Each consumer then loads the
  entity again: one renewal reads the same client 6 times (Billing 3; Notifications 3, of which `MergeContext::client()`
  calls `Client::find` and the merge-field resolver loads it again).
- **Read registries have no budget.** `Dashboard` and `GlobalSearch` take bare closures. Each widget or provider
  decides alone whether to cache, how much to load and how long to take. #166 had to add a 60-second MRR cache inside
  Billing, which is a second caching scheme next to the Billing dataset's own.

## Decision

1. **Event side effects are queued.** A listener that renders, sends, calls out or writes more than one row
   implements `ShouldQueueAfterCommit` and runs on its family's queue (`Queues::NOTIFICATIONS`, `Queues::WEBHOOKS`).
   The producer pays one job insert per event. Synchronous listeners are allowed only for invariants that must hold
   in the same transaction, with a comment that says why. Dedupe moves into the job and is keyed on the event's
   occurrence id (ADR 0011), so a retried job sends once.
2. **Hot reads are memoised per request or job.** A kernel read service that returns a summary of an entity
   (client first; later service and invoice summaries) is bound `scoped`, as `CachedCurrencyDirectory` already is,
   and memoises by id. Laravel resets scoped instances between jobs and requests. Writers never read through it. Merge
   field resolvers and Notifications' context read clients through it, not through `Client::find`.
3. **Batch callers can pass what they have loaded.** Read services that a loop calls take an optional preloaded
   value or offer a `many(ids)` form. Renewals load their clients once per chunk.
4. **Read registries own caching and budgets.**
    - `Dashboard::widget()` and `card()` gain `cacheSeconds` (default 0). The registry caches the result centrally,
      keyed by area, widget, permission scope and the arguments. Modules stop caching on their own.
    - `GlobalSearch::provider()` gains a per-provider result limit and a minimum term length (default 2), both
      enforced by the registry.
    - Both are trailing defaulted parameters (ADR 0010, compatible).
5. **Budgets are tests.** ADR 0014's query-count tests extend to:
    - renewals: queries per renewal, target 20 or fewer, measured over 10 renewals;
    - the deferred dashboard widgets;
    - global search per provider.

    A regression fails CI.

## Consequences

- Renewal throughput no longer depends on Notifications. Mail templates may grow without slowing billing.
- Mail is sent a little later (one queue hop). The producer does not see send failures, and never did, because
  `SendEventEmails` already swallowed them.
- The scoped memo needs discipline: writers never read through it, and a test checks that a write in the same job is
  not hidden by a stale memo (the service forgets an id on its own writes).
- Small, separable PRs:
    - Notifications listeners become queued;
    - the client summary read service, scoped;
    - the renewal batch path and its budget test;
    - the `Dashboard` and `GlobalSearch` parameters.

  The first and third touch the money path and get the full gate set.
