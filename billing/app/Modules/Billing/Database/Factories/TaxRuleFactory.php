<?php

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    public function definition(): array
    {
        return ['name' => 'VAT', 'level' => 1, 'country' => null, 'state' => null, 'rate' => '20.000', 'priority' => 0];
    }
}
