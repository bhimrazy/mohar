<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'code' => 'USD',
            'name' => 'US Dollar',
            'prefix' => '$',
            'suffix' => '',
            'exchange_rate' => 1,
            'is_default' => true,
        ];
    }

    public function euro(): static
    {
        return $this->state(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'exchange_rate' => 0.92, 'is_default' => false]);
    }

    public function inr(): static
    {
        return $this->state(['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'exchange_rate' => 84, 'is_default' => false]);
    }

    public function npr(): static
    {
        return $this->state(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => 134.4, 'is_default' => false]);
    }
}
