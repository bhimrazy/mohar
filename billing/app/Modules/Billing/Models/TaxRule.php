<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Database\Factories\TaxRuleFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $level 1|2
 * @property string|null $country
 * @property string|null $state
 * @property string $rate percent with 3 decimals, e.g. "20.000"
 * @property int $priority
 * @property CarbonImmutable|null $effective_from
 */
#[UseFactory(TaxRuleFactory::class)]
#[Fillable(['name', 'level', 'country', 'state', 'rate', 'priority', 'effective_from'])]
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'rate' => 'decimal:3',
            'priority' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /**
     * Specificity for resolution: country+state > country > all.
     */
    public function specificity(): int
    {
        return ($this->country !== null ? 2 : 0) + ($this->state !== null ? 1 : 0);
    }
}
