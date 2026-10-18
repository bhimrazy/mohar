<?php

namespace App\Modules\Services\Models;

use App\Models\User;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\CancellationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_id
 * @property CancellationType $type
 * @property string|null $reason
 * @property int|null $requested_by_user_id
 * @property bool $by_staff
 * @property CancellationStatus $status
 * @property CarbonImmutable $process_after
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Service $service
 * @property-read User|null $requestedBy
 */
#[Fillable(['service_id', 'type', 'reason', 'requested_by_user_id', 'by_staff', 'status', 'process_after', 'processed_at'])]
class CancellationRequest extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CancellationType::class,
            'status' => CancellationStatus::class,
            'by_staff' => 'boolean',
            'process_after' => 'immutable_date',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
