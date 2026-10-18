<?php

namespace App\Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Delete the rows a query matches in batches of primary keys, each its own short statement. Retention pruning
 * of log tables can match millions of rows the first time it runs; a single DELETE would hold its locks and
 * write the whole change to the WAL in one transaction. Hard deletes only: no model events, no soft deletes.
 *
 *   ChunkedDelete::run(ModuleCommand::query()->where('created_at', '<', $cutoff));
 */
final class ChunkedDelete
{
    public const BATCH = 5000;

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function run(EloquentBuilder|QueryBuilder $query, int $batch = self::BATCH): int
    {
        $key = $query instanceof EloquentBuilder ? $query->getModel()->getKeyName() : 'id';
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $deleted = 0;

        do {
            $ids = (clone $base)->reorder()->orderBy($key)->limit($batch)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            $deleted += $base->newQuery()->from($base->from)->whereIn($key, $ids)->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }
}
