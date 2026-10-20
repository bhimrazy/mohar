# ADR 0019 — Import pipeline extension points

- Status: Proposed · 2026-10-20 · Owner: Principal architect
- Related: ADR 0010 (contract evolution), ADR 0011 (events), ADR 0012 (capability interfaces and registries),
  ADR 0014 (queues), `billing/docs/product/migration.md` §12, `billing/docs/product/client-import.md` §16, PR #160
  (architecture review).

## Context

PR #160 added the record-importer kernel: `RecordImporter` (a provider contract), the `ImportRegistry` (ADR 0012
shape, keyed by entity), and the DTOs `ImportRecord`, `ImportContext`, `ImportOutcome` and `ImportIssue`. The CSV
client import is its first consumer. The WHMCS API connector (client-import PR C), importers for services, invoices
and domains (migration.md phase 2), and possibly the cPanel account import (#95, #133) will need more than that. This
ADR names the extension points now, so each can be added later without changing a provider contract.

## Decision (proposed)

### Vocabulary

- **`sourceSystem`**: the kind of source, an open `[a-z0-9_]` key (`csv`, `whmcs_api`). The same name is used in the
  DTOs, the events and the `migration_runs` and `migration_sources` tables.
- **`entity`**: the importer's registry key (`client`, `client_group`, `user`, `contact`, `credit_balance`). It is
  `ImportRecord::$entity`, the argument of `ImportIdResolver::targetId()`, and the id map's `entity` column.

### Rules every importer follows

- **Quiet imports.** Importers dispatch no domain event other than the run events in `App\Events\Migration`, send no
  mail or notification, and call no driver (Arch rule in `tests/Arch/ExtensionsTest.php`).
- **Dry runs write nothing.** With `ImportContext::$dryRun`, `upsert()` returns the outcome it would produce. Callers
  never rely on a rollback, which would not undo sequences or stored files.

### Sources

`SourceConnector` (today internal to the Migration module) becomes a kernel provider contract with an ADR 0012
registry, so a package (Blesta, HostBill) or another module (the WHM account list) can add a source without editing
Migration. Shape to be settled with the WHMCS connector (client-import PR C).

### Importer capabilities (optional interfaces, ADR 0012)

- `PreparesChunk` (batch validation) and a batch `ImportIdResolver::targetIds()`, so validating 50,000 invoices keeps
  one query per lookup per chunk (client-import spec §13).
- `MatchesExisting`: the owning module matches an incoming record to one it holds (a domain by name, a cPanel account
  by server and username), because Migration cannot read those tables.
- `RevertsImport`, with a trailing `ImportOutcome::$before`, for undo of entities outside Core.
- `FinalizesImport` and `AppliesCutover`, if most importers turn out to implement `finalize()` and `cutover()` as
  empty methods.

### Events

Each run event lands with the code that dispatches it: `MigrationRunCompleted(int $runId, string $sourceSystem, bool
$failed, array $counts)` with counts per entity, `MigrationRunUndone(int $runId, int $reverted, int $kept)`, and
`MigrationRunPaused(int $runId, string $reasonCode)`.

## Open questions

- Whether the cPanel account import moves onto these contracts. It needs `SourceConnector` in the kernel, an
  `ImportContext` that does not require a migration run, and a decision on `ServiceImported`, which it dispatches per
  service against the quiet-import rule. Tracked as a later issue.

## Consequences

- PR B and PR C add capabilities as interfaces; `RecordImporter` itself never gains a method.
- The vocabulary is fixed before the first API connector, so its parameter names are stable (ADR 0010).
