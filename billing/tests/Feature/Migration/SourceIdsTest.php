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
    $targets = $ids->targetIds('client', ['1001', 'email:abc', '1002', '1001', 'missing']);

    expect($targets)->toEqual(['1001' => 11, 'email:abc' => 12])
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and($ids->targetIds('client', []))->toBe([])
        ->and($ids->targetId('client', 'email:abc'))->toBe(12);
});

test('targetIds() splits a long list into batches of a thousand', function () {
    $source = MigrationSource::query()->forceCreate(['source_system' => 'csv', 'name' => 'Old billing']);
    mapSourceId($source, 'domain', 'd-1500', 7);

    DB::enableQueryLog();
    $targets = (new SourceIds($source->id))->targetIds('domain', array_map(fn (int $n): string => "d-{$n}", range(1, 2500)));

    expect($targets)->toBe(['d-1500' => 7])->and(DB::getQueryLog())->toHaveCount(3);
});
