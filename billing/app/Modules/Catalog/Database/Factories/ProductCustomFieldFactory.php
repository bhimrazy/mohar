<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCustomField>
 */
class ProductCustomFieldFactory extends Factory
{
    protected $model = ProductCustomField::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => ucfirst(fake()->word().' '.fake()->word()),
            // Module keys are unique per product; set one explicitly with withModuleKey().
            'module_key' => null,
            'type' => CustomFieldType::Text,
            'options' => null,
            'description' => null,
            'validation_regex' => null,
            'is_required' => false,
            'is_admin_only' => false,
            'show_on_order' => true,
            'show_on_invoice' => false,
            'sort_order' => 0,
        ];
    }

    public function withModuleKey(?string $key = null): static
    {
        return $this->state(fn (): array => ['module_key' => $key ?? 'field_'.fake()->unique()->numberBetween(1, 1_000_000)]);
    }

    public function adminOnly(): static
    {
        return $this->state(['is_admin_only' => true]);
    }
}
