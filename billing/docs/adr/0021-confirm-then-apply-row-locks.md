# ADR 0021 — Confirm-then-apply under a database row lock

- Status: Accepted · 2026-10-21 (CTO) · Owner: Principal architect. It describes code that is merged: the client
  importer (#160–#163, #184, #201) and billing reversals (#211).
- Related: ADR 0006 (PostgreSQL gate), ADR 0017 §4 (locks; amended here), ADR 0019 (apply invariants),
  `billing/docs/product/billing.md` (lock order), `billing/docs/product/client-import.md` §5.6, the architecture
  reviews of #184, issue #219.

## Context

- **Staff confirm a plan that others can change.** An import shows rows, staff decide and confirm, and a job applies
  them later. In between, another staff member can confirm a second import, an admin can edit a matched client, and a
  module can add a service in the client's currency. Each of these broke a #184 review probe: Continue bypassed "one
  import at a time" (B2), the undo snapshot was read before the importer's lock, and the currency check ran before a
  service existed (B3).
- **Inserts lock the parent row.** On PostgreSQL, inserting a row with a foreign key to `clients` takes a `FOR KEY
  SHARE` lock on the client. Before #211, `IssueInvoice` and `PlaceOrder` inserted first and locked the client `FOR
  UPDATE` for credit afterwards, so two invoices or checkouts of one client deadlocked (40P01, #192).
- **ADR 0017 §4 says locks go through `Cache::lock`.** `ApplyRun::claim` is the first install-wide slot held with row
  locks, and nothing said which of the two applies when.

## Decision

### 1. Confirm-then-apply

1. **The review produces a token.** `Migration\Support\ReviewDigest` hashes each row's decision, data checksum, matched
   client and login choice, together with the run id, its check generation and the row number. The run's digest is the
   XOR of the row hashes, so one decision updates it in constant time (`replace()`). It is a concurrency token, not a
   MAC. The confirm request sends the digest the page showed.
2. **Confirming claims under row locks.** `ApplyRun::claim` runs one transaction. It locks, in id order, the target run
   and every run that could hold the slot (`ready`, `applying`, `failed`). It checks the state, that no row still
   needs a decision, `hash_equals` on the digest, and that no other run is applying or stopped with rows applied. Then
   it saves the run as `applying`. Start and Continue use the same claim. It is correct only because `ready` runs are in
   the lock set: two staff confirming two runs lock both rows, and the second waits and then sees the first.
3. **Applying re-reads under the lock of the row it writes.** `ApplyChunk` claims each record in the row's transaction
   (`whereNull('outcome')->lockForUpdate()`), so a redelivered or resumed job skips it. `ClientImporter::upsert` locks
   the client (`lockForUpdate()->findOrFail()`), checks again any rule that depends on other modules (currency, through
   `ClientCurrencyUsage`), computes the changes, and reads the undo snapshot under that same lock. It returns the
   snapshot as `ImportOutcome::$before`. Migration stores it and never reads the owner's row itself.

The rule: the review is advisory and the lock is authoritative. Anything decided at review time is decided again at
apply time under the lock. A decision that no longer holds becomes a row issue (`currency_kept`), not a failed run.
Dry runs read without locks and write nothing (ADR 0019).

### 2. Row lock or `Cache::lock`

| Use a row lock (`lockForUpdate()` inside `DB::transaction`)                               | Use `Cache::lock` (ADR 0017 §4)                                                                  |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| The protected state is rows in our database, and the critical section is one transaction. | The critical section includes work outside the transaction: a gateway, driver or registrar call. |
| The lock must end exactly at commit or rollback.                                          | The lock must exist before any row does (checkout idempotency key) or outlive a transaction.     |
| A slot whose state lives in a status column (`ApplyRun::claim`).                          | Mutual exclusion between jobs or scheduled runs (`RunAutomation`).                               |
| Examples: `ApplyCredit`, `MergeClients`, `RecordDisputeWon`, `ReviewExchangeRate`.        | Examples: `CaptureInvoice`, `PaymentBatches`, `ExecuteModuleCommand`, `PlaceOrder` idempotency.  |

- When both are needed, the cache lock is outside and the transaction with its row locks is inside
  (`EloquentServiceRegistry`, `SetServiceStatus`, `PlaceOrder`). Code never waits for a cache lock while it holds row locks.
- A slot recorded in a status column is never guarded by a cache lock alone: a cache lock can expire or be flushed
  while the column still says the slot is taken.
- ADR 0017 §4 is amended: its `Cache::lock` rule covers locks that span work outside one transaction. Row locks follow
  this ADR.

### 3. Lock order

- **Within one table**, several rows are locked in one query in ascending id (`orderBy('id')->lockForUpdate()`), as in
  `ApplyRun::claim`, `MergeClients`, `RecordDisputeWon` and the reversal path in `GatewayCallbackHandler`.
- **Money:** payment (`transactions`), then invoice, then client (the credit ledger). Payments, credit, refunds and
  reversals all follow it. A reversal locks its payments and their invoices before it takes any credit back.
- **Client first for new rows.** A transaction that inserts a row referencing a client, and may then lock that client
  `FOR UPDATE`, locks the client before the insert: `IssueInvoice` when the invoice may take credit, and `PlaceOrder`
  for an existing client before the order row. This does not contradict the money order: the new invoice is not yet
  visible, so no other transaction can hold it.
- **Import:** run rows (claim), then the migration record, then the target row (client).
- A new path that locks more than one table states its order in the action's docblock and is added to this list.

### 4. Test obligation

A PR that adds a multi-row lock path, or changes an order above, ships a two-process PostgreSQL race test:

- The workers are separate PHP processes, each with its own connection and committed transaction, started through
  `runRaceWorkers()` in `billing/tests/Feature/Billing/Support/race.php`. The seed is committed with
  `useSeededDatabase()`. The test is skipped on SQLite, where one database file cannot host the race.
- Delays and pauses force the interleaving at the point where the race lands. The test asserts the outcome per worker
  label: one wins, the other waits and sees its result. A deadlock, a lost update or a negative balance fails it.
- A re-read under lock is proven in one process too: the test binds a subclass that commits a competing edit just
  before the parent takes its lock (the B2 probe in `ImportApplyReReviewProbesTest`). It runs on both databases.
- The race test fails with the lock or the ordering removed; the PR says how that was checked.
- `runRaceWorkers()` starts `credit-worker.php` only. A second scenario adds a mode to it. The helper moves to
  `tests/Support` and takes the worker script as a parameter when a module other than Billing needs it.

## Consequences

- One rule for every module: review, claim under lock, re-read under lock. Packages that implement `RecordImporter`
  produce their own snapshot under their own lock.
- Row locks hold for the transaction only, so an apply transaction stays one row long. Remote calls never run under
  row locks (ADR 0017 §2).
- Known gaps, tracked as issues and not changed here:
    - the claim's lock set grows with every purged `failed` run; one install-wide row would bound it;
    - `CheckRun` writes the digest in a second save after the `ready` transition;
    - service and domain creation do not lock the client, so a service created at the same instant as an apply can
      miss the currency check. This is accepted for imports.
