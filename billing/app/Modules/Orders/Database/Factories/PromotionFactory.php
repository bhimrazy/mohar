<?php

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO-####')),
            'type' => PromotionType::Percent,
            'value' => '10.00',
            'recurring' => false,
            'recur_count' => 0,
            'applies_to' => [],
            'requires' => [],
            'cycles' => null,
            'max_uses' => 0,
            'uses' => 0,
        ];
    }

    public function percent(string $value): static
    {
        return $this->state(['type' => PromotionType::Percent, 'value' => $value]);
    }

    public function fixed(string $value): static
    {
        return $this->state(['type' => PromotionType::Fixed, 'value' => $value]);
    }

    public function priceOverride(string $value): static
    {
        return $this->state(['type' => PromotionType::PriceOverride, 'value' => $value]);
    }

    public function freeSetup(): static
    {
        return $this->state(['type' => PromotionType::FreeSetup, 'value' => '0']);
    }

    /**
     * @param  list<int>  $productIds
     */
    public function forProducts(array $productIds): static
    {
        return $this->state(['applies_to' => ['product_ids' => $productIds]]);
    }
}
