<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Database\Factories\TaxRuleFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

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

    /**
     * Deletes the rule unless an invoice was calculated with it (`invoices.tax1_rule_id` or `tax2_rule_id`).
     *
     * @return int how many invoices use the rule; 0 when it was deleted
     */
    public function deleteUnlessUsed(): int
    {
        $used = Invoice::query()->where('tax1_rule_id', $this->id)->orWhere('tax2_rule_id', $this->id)->count();

        if ($used > 0) {
            return $used;
        }

        try {
            // The foreign key also refuses an invoice calculated with the rule after the count; the savepoint keeps an
            // outer PostgreSQL transaction usable after that refusal.
            $this->getConnection()->transaction(fn () => $this->delete());
        } catch (QueryException $exception) {
            if (! str_starts_with((string) ($exception->errorInfo[0] ?? ''), '23')) {
                throw $exception;
            }

            return 1;
        }

        return 0;
    }
}
