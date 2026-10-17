<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductGroup>
 */
class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'headline' => fake()->sentence(4),
            'tagline' => null,
            'description' => null,
            'sort_order' => 0,
            'is_hidden' => false,
            'allowed_gateways' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }

    /**
     * @param  list<string>  $gateways
     */
    public function gateways(array $gateways): static
    {
        return $this->state(['allowed_gateways' => $gateways]);
    }
}
