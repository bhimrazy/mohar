<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Enums\QueueStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module command that failed after all retries and waits for staff (retry / resolve / dismiss).
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $command
 * @property string $kind failure|verify|reconcile
 * @property array<string, mixed>|null $args
 * @property string|null $last_error
 * @property int $attempts
 * @property QueueStatus $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service|null $service
 */
#[Table('module_queue')]
#[Fillable(['subject_type', 'subject_id', 'command', 'kind', 'args', 'last_error', 'attempts', 'status', 'resolved_by'])]
class ModuleQueueItem extends Model
{
    public const SUBJECT_SERVICE = 'service';

    /** The command failed after all retries. */
    public const KIND_FAILURE = 'failure';

    /** The outcome is unknown (worker died mid-call); check the panel before retrying. */
    public const KIND_VERIFY = 'verify';

    /** The driver call succeeded but the service changed meanwhile; local state was not updated. */
    public const KIND_RECONCILE = 'reconcile';

    protected function casts(): array
    {
        return [
            'args' => 'encrypted:array',
            'status' => QueueStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'subject_id')->withTrashed();
    }
}
