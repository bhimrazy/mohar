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
 * @property string|null $scope null|home_state|other_state
 * @property string $rate percent with 3 decimals, e.g. "20.000"
 * @property int $priority
 * @property CarbonImmutable|null $effective_from
 */
#[UseFactory(TaxRuleFactory::class)]
#[Fillable(['name', 'level', 'country', 'state', 'scope', 'rate', 'priority', 'effective_from'])]
class TaxRule extends Model
{
    public const HOME_STATE = 'home_state';

    public const OTHER_STATE = 'other_state';

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
        return ($this->country !== null ? 4 : 0) + ($this->state !== null || $this->scope !== null ? 2 : 0);
    }
}
