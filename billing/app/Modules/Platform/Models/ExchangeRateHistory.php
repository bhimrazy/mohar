<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exchange rate that came into force: units of the currency per 1 default unit.
 *
 * @property int $id
 * @property string $currency
 * @property string $rate
 * @property string $source "manual", a rate source key, "review" or "rebase"
 * @property CarbonImmutable $effective_at
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
#[Table('exchange_rate_history')]
#[Fillable(['currency', 'rate', 'source', 'effective_at', 'user_id'])]
class ExchangeRateHistory extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:12',
            'effective_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
