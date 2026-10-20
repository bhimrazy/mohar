<?php

namespace App\Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a source record became: (source, type, source id) → target id, with the checksum of what was imported.
 *
 * @property int $id
 * @property int $migration_source_id
 * @property string $source_type
 * @property string $source_id
 * @property string $target_type
 * @property int $target_id
 * @property string $checksum
 * @property CarbonImmutable $synced_at
 */
class MigrationIdMap extends Model
{
    protected $table = 'migration_id_map';

    protected function casts(): array
    {
        return ['synced_at' => 'immutable_datetime'];
    }
}
