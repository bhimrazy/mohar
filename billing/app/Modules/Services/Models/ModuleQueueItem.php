<?php

namespace App\Modules\Services\Models;

use App\Enums\ReconcileStatus;
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
 * @property ReconcileStatus|null $lookup_status what the last server check found (verify items)
 * @property string|null $lookup_message
 * @property CarbonImmutable|null $looked_up_at
 * @property int|null $lookup_command_id the module command (attempt) the last check described
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service|null $service
 */
#[Table('module_queue')]
#[Fillable(['subject_type', 'subject_id', 'command', 'kind', 'args', 'last_error', 'attempts', 'status', 'resolved_by', 'lookup_status', 'lookup_message', 'looked_up_at', 'lookup_command_id'])]
class ModuleQueueItem extends Model
{
    public const SUBJECT_SERVICE = 'service';

    /** The command failed after all retries. */
    public const KIND_FAILURE = 'failure';

    /** The outcome is unknown (worker died mid-call); check the panel before retrying. */
    public const KIND_VERIFY = 'verify';

    /** The driver call succeeded but the service changed meanwhile; local state was not updated. */
    public const KIND_RECONCILE = 'reconcile';

    /**
     * An unknown outcome is sticky (ADR 0009 §4): while a "verify" item is open, no later failure (job failure,
     * abandon, timeout, a failed retry) may turn it into another kind, because that would let a further create call
     * the driver again. Only resolving or dismissing the item ends it.
     */
    protected static function booted(): void
    {
        static::saving(function (ModuleQueueItem $item): void {
            $wasOpenVerify = $item->exists
                && $item->getOriginal('kind') === self::KIND_VERIFY
                && $item->getOriginal('status') === QueueStatus::Open;

            if ($wasOpenVerify && $item->status === QueueStatus::Open && $item->kind !== self::KIND_VERIFY) {
                $item->kind = self::KIND_VERIFY;
                $item->last_error = $item->getOriginal('last_error');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'args' => 'encrypted:array',
            'status' => QueueStatus::class,
            'lookup_status' => ReconcileStatus::class,
            'looked_up_at' => 'immutable_datetime',
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
