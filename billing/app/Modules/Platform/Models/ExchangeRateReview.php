<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fetched rate that moved more than the allowed daily change and waits for staff.
 *
 * @property int $id
 * @property string $currency
 * @property string $current_rate
 * @property string $proposed_rate
 * @property string $change_percent
 * @property string $source
 * @property CarbonImmutable $effective_at
 * @property string $status pending|approved|rejected|superseded
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $decider
 */
#[Fillable(['currency', 'current_rate', 'proposed_rate', 'change_percent', 'source', 'effective_at', 'status', 'decided_by', 'decided_at'])]
class ExchangeRateReview extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Replaced by a newer fetch before anyone decided. */
    public const SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'current_rate' => 'decimal:6',
            'proposed_rate' => 'decimal:6',
            'change_percent' => 'decimal:4',
            'effective_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
