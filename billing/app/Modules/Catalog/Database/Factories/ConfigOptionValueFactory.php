<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigOptionValue>
 */
class ConfigOptionValueFactory extends Factory
{
    protected $model = ConfigOptionValue::class;

    public function definition(): array
    {
        $label = fake()->randomElement(['1 GB', '2 GB', '4 GB', '8 GB']);

        return [
            'config_option_id' => ConfigOption::factory(),
            'label' => $label,
            'module_value' => strtolower(str_replace(' ', '', $label)),
            'is_hidden' => false,
            'sort_order' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }

    /**
     * Add a price row (amount and setup fee in minor units).
     */
    public function withPrice(string $currency, BillingCycle $cycle, int $amount, int $setupFee = 0, bool $enabled = true): static
    {
        return $this->afterCreating(function (ConfigOptionValue $value) use ($currency, $cycle, $amount, $setupFee, $enabled): void {
            $value->prices()->create(['currency' => $currency, 'cycle' => $cycle, 'amount' => $amount, 'setup_fee' => $setupFee, 'enabled' => $enabled]);
        });
    }
}
