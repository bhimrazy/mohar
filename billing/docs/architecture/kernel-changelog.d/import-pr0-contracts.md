### Importer capabilities, batch id lookup and soft import ordering (#527)

- New capabilities extending `App\Contracts\Migration\RecordImporter` (ADR 0019 Amendment (#527)):
  `PreparesChunk::prepare(array $records, ImportContext $context): void`,
  `MatchesExisting::matchExisting(array $records, ImportContext $context): array` (target id keyed by source id) and
  `DeclaresValueMaps::valueMaps(): array` (targets of each value map, `list<array{value: string, label: string}>` by
  map key). Additive: existing importers need no change.
- `App\Contracts\Migration\ImportIdResolver` has a new method `targetIds(string $entity, array $sourceIds): array`
  (target id keyed by source id; a numeric source id comes back as an int key). It is a service contract implemented
  only by Migration's `SourceIds`; a test double of the resolver needs the method.
- `App\Support\Migration\ImportRegistry::register()` has a new last parameter `array $after = []`, passed by name:
  `ordered()` puts the entity after those entities when they are part of the same import and ignores them otherwise.
  An importer must be registered so that `get()` builds a new instance on each call.
- Documented vocabulary, no signature change: the entities `invoice` and `domain`; `ImportRecord::$children` keys
  `items` and `payments`, written and reverted by the parent's importer; `invoice_item` and `payment` are reserved
  count keys that no importer registers. Consumers of `MigrationRunCompleted::$counts` ignore unknown keys. The module
  that owns an entity may listen to `MigrationRunCompleted` to update its own projections, under the importers' quiet
  rules.
- Nothing calls the new capabilities, `targetIds()` or `after` yet; the import pipeline wires them in a later PR.
