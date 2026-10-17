<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonImmutable $run_date
 * @property string $task
 * @property string $status
 * @property string|null $summary
 * @property int $duration_ms
 */
#[Fillable(['run_date', 'task', 'status', 'summary', 'duration_ms'])]
class AutomationRun extends Model
{
    protected function casts(): array
    {
        return ['run_date' => 'immutable_date'];
    }
}
