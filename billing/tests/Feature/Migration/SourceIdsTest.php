<?php

use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationSource;
use App\Modules\Migration\Support\SourceIds;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0019 amendment: ImportIdResolver::targetIds() resolves a chunk of source ids of one source and entity with one
 * query per thousand ids, leaving out ids the map does not hold.
 */
function mapSourceId(MigrationSource $source, string $entity, string $sourceId, int $targetId): void
{
    MigrationIdMap::query()->forceCreate(['migration_source_id' => $source->id, 'entity' => $entity, 'source_id' => $sourceId,
        'target_id' => $targetId, 'checksum' => str_repeat('0', 64), 'synced_at' => now()]);
}

test('targetIds() resolves many source ids of one source and entity in one query', function () {
    $source = MigrationSource::query()->forceCreate(['source_system' => 'csv', 'name' => 'Old billing']);
    $other = MigrationSource::query()->forceCreate(['source_system' => 'csv', 'name' => 'Older billing']);
    mapSourceId($source, 'client', '1001', 11);
    mapSourceId($source, 'client', 'email:abc', 12);
    mapSourceId($source, 'invoice', '1001', 21);
    mapSourceId($other, 'client', '1002', 31);
    $ids = new SourceIds($source->id);

    DB::enableQueryLog();
    expect($ids->targetIds('client', []))->toBe([])->and(DB::getQueryLog())->toBe([]);

    $targets = $ids->targetIds('client', ['1001', 'email:abc', '1002', '1001', 'missing']);

    // A numeric source id comes back as an int key, as PHP stores it; lookups by the source id still work.
    expect($targets)->toBe([1001 => 11, 'email:abc' => 12])
        ->and($targets['1001'])->toBe(11)
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and($ids->targetId('client', 'email:abc'))->toBe(12);
});

test('targetIds() takes a thousand ids per query and merges hits from both sides of a batch', function () {
    $source = MigrationSource::query()->forceCreate(['source_system' => 'csv', 'name' => 'Old billing']);
    mapSourceId($source, 'domain', 'd-1', 5);
    mapSourceId($source, 'domain', 'd-1000', 6);
    mapSourceId($source, 'domain', 'd-1001', 7);
    $ids = new SourceIds($source->id);
    $range = fn (int $count): array => array_map(fn (int $n): string => "d-{$n}", range(1, $count));

    DB::enableQueryLog();
    expect($ids->targetIds('domain', $range(1000)))->toBe(['d-1' => 5, 'd-1000' => 6])
        ->and(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    expect($ids->targetIds('domain', $range(1001)))->toBe(['d-1' => 5, 'd-1000' => 6, 'd-1001' => 7])
        ->and(DB::getQueryLog())->toHaveCount(2);
});
