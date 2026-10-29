# ADR 0019 — Import pipeline extension points

- Status: Accepted · 2026-10-21 (CTO) · Owner: Principal architect. Acceptance covers Vocabulary, Rules and Events;
  Sources and Importer capabilities are a direction, decided when their first consumer lands (ADR 0010).
- Related: ADR 0010 (contract evolution), ADR 0011 (events), ADR 0012 (capability interfaces and registries),
  ADR 0014 (queues), ADR 0017 (background work), `billing/docs/product/migration.md` §12,
  `billing/docs/product/client-import.md` §16, PR #160 and #184 (architecture reviews).

## Context

PR #160 added the record-importer kernel: `RecordImporter` (a provider contract), the `ImportRegistry` (ADR 0012
shape, keyed by entity), and the DTOs `ImportRecord`, `ImportContext`, `ImportOutcome` and `ImportIssue`. The CSV
client import is its first consumer. The WHMCS API connector (client-import PR C), importers for services, invoices
and domains (migration.md phase 2), and possibly the cPanel account import (#95, #133) will need more than that. This
ADR names the extension points now, so each can be added later without changing a provider contract.

## Decision

### Vocabulary

- **`sourceSystem`**: the kind of source, an open `[a-z0-9_]` key (`csv`, `whmcs_api`). The same name is used in the
  DTOs, the events and the `migration_runs` and `migration_sources` tables.
- **`entity`**: the importer's registry key (`client`, `client_group`, `user`, `contact`, `credit_balance`). It is
  `ImportRecord::$entity`, the argument of `ImportIdResolver::targetId()`, and the id map's `entity` column.

### Rules every importer follows

- **Quiet imports.** Importers dispatch no domain event other than the run events in `App\Events\Migration`, send no
  mail or notification, and call no driver (Arch rule in `tests/Arch/ExtensionsTest.php`).
- **Dry runs write nothing.** With `ImportContext::$dryRun`, `upsert()` returns the outcome it would produce (action,
  changes, issues). Callers never rely on a rollback, which would not undo sequences or stored files. This includes
  reference data an importer would create (client groups), and links to records the same dry run has not created; an
  importer resolves those only when writing.
- **Apply invariants.** One applying run per install, checked in one place for start and resume (a run stopped
  partway still holds the slot); chunk jobs claim each row inside its transaction, so a redelivered or resumed job
  never applies a row twice.

### Sources (direction, decided when the first consumer lands)

`SourceConnector` (today internal to the Migration module) becomes a kernel provider contract with an ADR 0012
registry, so a package (Blesta, HostBill) or another module (the WHM account list) can add a source without editing
Migration. Shape to be settled with the WHMCS connector (client-import PR C).

### Importer capabilities (optional interfaces, ADR 0012; direction, decided when the first consumer lands)

- `PreparesChunk` (batch validation) and a batch `ImportIdResolver::targetIds()`, so validating 50,000 invoices keeps
  one query per lookup per chunk (client-import spec §13).
- `MatchesExisting`: the owning module matches an incoming record to one it holds (a domain by name, a cPanel account
  by server and username), because Migration cannot read those tables.
- `RevertsImport`, for undo of every entity, Core's included. The snapshot travels in the trailing
  `ImportOutcome::$before` (added with #184): the owning module produces it under its own lock, and Migration stores it
  without reading the owner's tables.
- `FinalizesImport` and `AppliesCutover`, if most importers turn out to implement `finalize()` and `cutover()` as
  empty methods.

### Events

Each run event lands with the code that dispatches it, and implements `ShouldDispatchAfterCommit`:

- `MigrationRunCompleted(int $runId, string $sourceSystem, array $counts)`, `$counts` keyed by entity, each with
  `created`, `updated`, `unchanged`, `skipped` and `failed`. Dispatched once, on the applying → completed transition,
  after commit; a stopped run dispatches `MigrationRunPaused`, never `Completed`.
- `MigrationRunPaused(int $runId, ImportStopReason $reasonCode)`, when a run stops and waits for staff
  (`App\Enums\ImportStopReason`, an `@open` enum; `ApplyFailed` today).
- `MigrationRunUndone(int $runId, int $reverted, int $kept)`, with undo.

Run events are kernel events from their first dispatch, an exception to ADR 0008's promote-on-consume rule, because
their consumers (Notifications, webhooks, packages) are outside Migration.

## Addendum (2026-10-22, importer B2b, #259): undo and outcomes per entity

Accepted on merge of #259.

- **`App\Contracts\Migration\RevertsImport extends RecordImporter`**, with
  `revert(ImportRecord $record, ImportOutcome $applied, ImportContext $context): ImportOutcome`. `$record` is what was
  written, and `$applied` is the importer's own stored outcome (action, target, changes and `before`), so an importer
  can restore a field only while it still holds the imported value and can tell a created target from an updated one.
  `revert()` opens its own transaction and locks what it writes; a dry run writes nothing.
- **Outcomes of `revert()`:** `ImportAction::Reverted` (new case of the open enum) when the write was taken back,
  `Skipped` with the reason in the issues when it was kept, `Unchanged` when there was nothing of this run's to take
  back. `MigrationRunUndone(reverted, kept)` counts `Reverted` and `Skipped`.
- **Outcomes are stored per entity.** A record that several importers write (the client, its owner login, its opening
  balance) keeps one outcome and one snapshot per entity: `migration_records.entities` holds
  `{entity: {action, target_id, changes}}` and `migration_records.before` (encrypted) holds `{entity: snapshot}`. The
  record's `outcome` and `changes` stay the combined view for the review and report. Every entity a row wrote, except
  logins (reached through their client), has its own id-map row, and `MigrationRunCompleted` counts every entity.
  Runs applied before #259 stored the client's snapshot as a flat map; undo reads a flat map as the client's.
- **Undo order across runs.** Undo takes back a run only when no later run wrote to the same id-map entries
  (`last_run_id` is that run); otherwise it refuses with the later run named, so staff undo the later run first.
  Within a run, entities are reverted in the reverse of `ImportRegistry::ordered()` (opening balances and logins
  before the client), each in its own transaction.

## Amendment (#527) — 2026-10-29, capabilities and ordering for invoice and domain import

Accepted on merge of #527, the kernel PR of `billing/docs/product/invoice-domain-import.md` (§13, PR 0; architecture
review on #512). It decides the capabilities this ADR left as a direction.

- **Accepted capabilities** (optional interfaces extending `RecordImporter`; an importer without them works as before).
  No pipeline job calls them yet; PR 0b wires them in.
    - `PreparesChunk::prepare(list<ImportRecord> $records, ImportContext $context): void` loads what a chunk needs in
      one go. Prepared state lives on the instance only; `prepare()` writes nothing, and `upsert()` re-reads under its
      lock what it writes. An importer is registered so that `ImportRegistry::get()` builds a new instance on each
      call (a class not bound as a singleton, or a closure that constructs one); a test checks this for every
      registered importer.
    - `MatchesExisting::matchExisting(list<ImportRecord>, ImportContext): array<string, int>`, batch, keyed by source id.
      A match is a proposal: Migration asks only for records the id map does not resolve (the id map wins), and update
      or skip stays a Migration row decision. When two records propose the same target, both rows are a conflict for
      staff, and the target is never updated twice in one run.
    - `DeclaresValueMaps::valueMaps(): array<string, list<array{value, label}>>` lists the targets of each value map
      (invoice statuses, tax rules, registrars, extensions), so Migration's mapping step shows them without reading
      the owner's tables. A map key is the path of the attribute it applies to (`status`, `items.tax_rule`); these
      keys replace the map names first written in the spec (`status.invoice`). Keys are per entity, and Migration
      stores the choices by entity and key. `validate()` returns an `ImportIssue` for a value that is not a listed
      target. No module adds a separate lookup contract for Migration.
    - **Per chunk, in this order:** Migration replaces mapped values, takes a new instance from `get()`, calls
      `prepare()` once, then `matchExisting()`, then `validate()` or `upsert()` per record. Every one of these sees
      mapped values only.
- **`ImportIdResolver::targetIds(string $entity, list<string> $sourceIds): array<int|string, int>`**, the batch form of
  `targetId()`, for `prepare()`. A numeric source id comes back as an int key, as PHP stores it, so callers look ids up
  by the source id and never compare keys strictly. `ImportIdResolver` is a service contract: only Migration
  implements it (`SourceIds`, one query per 1,000 ids), so adding a method is compatible (ADR 0010).
- **Soft ordering.** `ImportRegistry::register(..., after: [...])` orders an entity after those entities when they are
  part of the same import and ignores them otherwise. It never adds an entity to `ordered()` and never makes one
  required, so `invoice` can name `after: ['domain', 'service']` and still run under `MODULES=` without Domains and
  before a `service` importer exists. `dependsOn` stays the hard form. An `after` edge counts only while both of its
  entities are in the import, so a cycle is refused when every entity on it is in the import; a cycle that needs an
  `after` edge to an entity outside the import is no cycle for that import. Decision for undo (not built yet,
  invoice-domain-import PR 2): its reverse order follows `ordered()`, `after` included.
- **Vocabulary.** New entities `invoice` and `domain`. An invoice's lines and payments travel as
  `ImportRecord::$children['items']` and `['payments']`. The parent's importer writes its children, and its `revert()`
  takes them back. `invoice_item` and `payment` are reserved entity names: no importer registers them, and they may
  appear as keys of `MigrationRunCompleted::$counts`. Consumers ignore entity keys they do not know.
- **Owning modules may react to `MigrationRunCompleted`** to bring their own projections up to date, because imported
  records dispatch no domain event (for example, Domains will link the open invoices of imported domains, PR 2). Such
  a listener follows the importers' rules: no mail or notification, no domain event, no driver call, and running it
  twice for a run changes nothing. It finds the run's records through its own tables.
- **Reference data is created on apply only.** A decision that creates reference data (a retired extension for an
  unknown TLD) is carried out by `upsert()` only when the run applies; a dry run reports what it would create.
- **Arch rule.** Besides kernel driver contracts and events, an importer in `App\Modules\*\Import` may not use any
  module's `Drivers`, `Jobs`, `Mail` or `Notifications` namespace; the `Bus`, `Event`, `Queue`, `Mail`,
  `Notification` or `Http` facades; the `Illuminate\Contracts\Bus`, `Queue`, `Mail` or `Notifications` contracts; the HTTP client;
  `dispatch()`, `dispatch_sync()` or `event()`; `GatewayRegistry`; or the integrations `DriverRegistry`
  (`tests/Arch/ExtensionsTest.php`, with a refused probe importer for six of these routes).
- **Moved to later PRs:** `InvoiceQueries::openLines()` and a cross-source client key (spec §13 items 4 and 5) go to
  PR 2, and the ADR 0024 amendment to PR 1.
- **Open question for PR 2: per-child outcomes and counts.** `upsert()` returns one `ImportOutcome`, and children
  carry no source id of their own. PR 2 decides how child outcomes are reported, counted under `invoice_item` and
  `payment`, and given id-map rows (the B2b addendum gives every written entity its own row), either through a
  trailing defaulted field on `ImportOutcome` or a documented child `source_id`.

## Open questions

- Whether the cPanel account import moves onto these contracts. It needs `SourceConnector` in the kernel, an
  `ImportContext` that does not require a migration run, and a decision on `ServiceImported`, which it dispatches per
  service against the quiet-import rule. Tracked as a later issue.

## Consequences

- PR B and PR C add capabilities as interfaces; `RecordImporter` itself never gains a method.
- The vocabulary is fixed before the first API connector, so its parameter names are stable (ADR 0010).
