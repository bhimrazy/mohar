<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    protected $model = Price::class;

    public function definition(): array
    {
        return [
            'priceable_type' => (new Product)->getMorphClass(),
            'priceable_id' => Product::factory(),
            'currency' => 'USD',
            'cycle' => BillingCycle::Monthly,
            'enabled' => true,
            'amount' => 1000,
            'setup_fee' => 0,
        ];
    }
}
