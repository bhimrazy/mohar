<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $notice
 * @property Carbon|null $dismissed_at
 */
#[Fillable(['user_id', 'notice', 'dismissed_at'])]
class StaffNotice extends Model
{
    /** Added in the upgrade that made the automation times company time (#337). */
    public const COMPANY_TIME_SETTINGS = 'company_time_settings';

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }
}
