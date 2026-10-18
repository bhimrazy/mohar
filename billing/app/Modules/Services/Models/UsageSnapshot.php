<?php

namespace App\Modules\Services\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $service_id
 * @property int $disk_used_mb
 * @property int|null $disk_limit_mb
 * @property int $bw_used_mb
 * @property int|null $bw_limit_mb
 * @property CarbonImmutable $captured_at
 */
#[Table('service_usage_snapshots')]
#[WithoutTimestamps]
#[Fillable(['service_id', 'disk_used_mb', 'disk_limit_mb', 'bw_used_mb', 'bw_limit_mb', 'captured_at'])]
class UsageSnapshot extends Model
{
    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime'];
    }
}
