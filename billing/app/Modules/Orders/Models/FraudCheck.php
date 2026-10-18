<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\FraudDecision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $provider
 * @property string $score
 * @property FraudDecision $decision
 * @property list<string> $reasons
 * @property array<string, mixed>|null $raw
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['order_id', 'provider', 'score', 'decision', 'reasons', 'raw', 'duration_ms'])]
class FraudCheck extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'decision' => FraudDecision::class,
            'reasons' => 'array',
            'raw' => 'array',
            'score' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
