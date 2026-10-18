# ADR 0011 — Domain event conventions

- Status: Proposed · 2026-10-18 · Owner: Principal architect

## Context

`app/Events` holds 98 classes on `develop`; with #28 there are 107. Some conventions already hold: they are final,
use `Dispatchable`, carry ids not models, and are promoted to the kernel when another module consumes them
(ADR 0008). Others have drifted:

- **After-commit dispatch.** There are four mechanisms:
    - `Billing\Support\AfterCommit::dispatch()`
    - `Domains\Services\DomainEvents`
    - inline `DB::afterCommit()` in Catalog
    - `ShouldHandleEventsAfterCommit` on one listener (#28)

    Every other event dispatches immediately, inside or outside a transaction. `config/queue.php` keeps
    `after_commit => false`.

- **Occurrence identity.** #28 adds `IdentifiesOccurrence` to every event. Notifications detects it with
  `method_exists($event, 'occurrenceId')`, and no arch rule keeps future events from leaving it out.
  `ExchangeRatesUpdated` (#43) does not have it.
- **Dates and enums.** Dates are strings (`ServiceActivated::$nextDueDate`, `AutomationRunCompleted::$runDate`,
  `PasswordResetRequested::$expiresAt` as `Y-m-d H:i`). Closed sets are docblock strings
  (`ExchangeRatesUpdated::$source`).
- **Secrets.** `PasswordResetRequested` carries the raw reset token and URL (#28).
- **Events used as commands.** `User::sendPasswordResetNotification()` checks `Event::hasListeners()` to decide
  whether Laravel or Notifications sends the mail (#28).
- **Synchronous heavy work.** Catalog and Domains rewrite every converted price inside a synchronous
  `ExchangeRatesUpdated` listener (#43).

## Decision

### Shape

- A kernel event is named `App\Events\<Area>\<Entity><PastTenseVerb>`. It is `final`,
  `use Dispatchable, IdentifiesOccurrence`, and implements `App\Contracts\Events\DomainEvent`
  (`occurrenceId(): string`, `occurredAt(): CarbonImmutable`). Consumers type against the interface, never against
  `method_exists`.
- **Payload.**
    - Allowed: entity ids (`int`), scalars, kernel enums, kernel DTOs, and `App\Support\Money`.
    - Include `clientId` whenever the entity belongs to a client.
    - Never models, closures or resources.
    - Never secrets: no tokens, signed URLs, passwords, card data or API keys.
- **Times.** Calendar dates (company calendar, ADR 0013) are `Y-m-d` strings. Instants are `CarbonImmutable` in UTC.
- **Closed sets** are backed enums in `app/Enums` (for example `ExchangeRatesUpdated::$source` becomes
  `RateChangeSource`).
- **Identity.**
    - The occurrence id is a UUIDv7 (`Str::uuid7()`): time-ordered, so it works as a webhook event id and a log key.
    - The id and `occurredAt` are assigned on first read or on serialisation, whichever comes first, and every copy of
      one occurrence shares them. The properties are `readonly`: initialised once, in class scope.

### Dispatch

- Every kernel event implements `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`. Outside a transaction it
  dispatches immediately. Inside one it waits for the outermost commit and is dropped on rollback. This replaces the
  four mechanisms above, which are removed once the events carry the interface.
- An event reports a fact. It never decides whether something happens. When exactly one handler must act (send the
  reset mail, compute a price), use a contract with a Core default, rebound by the owning module (the pattern of
  `PaymentGuard` and `BrandProvider`). `Event::hasListeners()` is never used to choose behaviour.

### Listeners

- A synchronous listener must be cheap, and it must not fail the dispatcher's action: it wraps non-critical work in
  `report()`, or it queues.
- Remote calls, bulk writes and anything slower than about 50 ms go in a queued listener or a job. The job is
  `ShouldBeUnique`, keyed by the occurrence id or a natural key, and runs on a named queue (ADR 0014).
- Listeners are idempotent: they key on the occurrence id or a natural key, and they make no assumption about the
  order of events (ADR 0008).

### Exposure

- Kernel events are public API (ADR 0010).
- Exposure outside the process (webhooks, the future API event feed) goes through an explicit allow-list, which is
  Notifications' `WebhookEventTypes` today. A module or package adds an entry through a registration method rather
  than by editing that map: `WebhookEvents::register(InvoicePaid::class, 'invoice.paid', resource: InvoiceWebhookResource::class)`.

### Enforcement (arch tests)

- `App\Events` classes implement `DomainEvent` and `ShouldDispatchAfterCommit`, and use `IdentifiesOccurrence`.
- `App\Events` does not use `Illuminate\Database\Eloquent\Model`.
- A unit test reflects over every event constructor and fails on parameters named like secrets
  (`token`, `password`, `secret`, `*_url` when the URL is signed). Exceptions need an architect sign-off.

## Consequences

- One dispatch path. A rolled-back transaction can no longer email a customer about an invoice that does not exist.
- Some behaviour changes: listeners that today run inside the dispatcher's transaction will run after it. Billing,
  Domains and Catalog already use this order. For the rest, the adopting PR runs the full suite on SQLite and
  PostgreSQL, and fixes any listener that relied on seeing uncommitted rows.
- Auth mail moves from events to a contract (see the #28 review). The events can stay as observations without
  secrets.
