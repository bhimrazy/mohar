<?php

namespace App\Modules\Catalog\Models;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Database\Factories\PriceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One price row: amount and setup fee in minor units for a currency × billing cycle.
 *
 * @property int $id
 * @property string $priceable_type
 * @property int $priceable_id
 * @property string $currency
 * @property BillingCycle $cycle
 * @property bool $enabled
 * @property int $amount
 * @property int $setup_fee
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(PriceFactory::class)]
#[Fillable(['priceable_type', 'priceable_id', 'currency', 'cycle', 'enabled', 'amount', 'setup_fee'])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cycle' => BillingCycle::class,
            'enabled' => 'boolean',
            'amount' => 'integer',
            'setup_fee' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int}
     */
    public function toMatrixRow(): array
    {
        return [
            'currency' => $this->currency,
            'cycle' => $this->cycle->value,
            'enabled' => $this->enabled,
            'amount' => $this->amount,
            'setup_fee' => $this->setup_fee,
        ];
    }
}
