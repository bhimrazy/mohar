<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'product_group_id' => ProductGroup::factory(),
            'type' => ProductType::SharedHosting,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'tagline' => null,
            'short_description' => null,
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'payment_type' => PaymentType::Recurring,
            'is_hidden' => false,
            'is_retired' => false,
            'is_featured' => false,
            'is_taxable' => true,
            'requires_domain' => false,
            'stock_enabled' => false,
            'stock_quantity' => 0,
            'quantity_mode' => QuantityMode::Single,
            'recurring_cycles_limit' => 0,
            'fixed_term_days' => 0,
            'auto_setup' => AutoSetup::OnPayment,
            'provisioning_driver' => 'none',
            'server_group_id' => null,
            'driver_config' => [],
            'allow_config_option_upgrades' => false,
            'prorata_enabled' => false,
            'prorata_day' => 1,
            'prorata_charge_next_month_day' => 0,
            'free_domain' => FreeDomain::None,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }

    public function retired(): static
    {
        return $this->state(['is_retired' => true]);
    }

    public function free(): static
    {
        return $this->state(['payment_type' => PaymentType::Free]);
    }

    public function oneTime(): static
    {
        return $this->state(['payment_type' => PaymentType::OneTime]);
    }

    public function stock(int $quantity): static
    {
        return $this->state(['stock_enabled' => true, 'stock_quantity' => $quantity]);
    }

    /**
     * Add a price row (amount and setup fee in minor units).
     */
    public function withPrice(string $currency, BillingCycle $cycle, int $amount, int $setupFee = 0, bool $enabled = true): static
    {
        return $this->afterCreating(function (Product $product) use ($currency, $cycle, $amount, $setupFee, $enabled): void {
            $product->prices()->create(['currency' => $currency, 'cycle' => $cycle, 'amount' => $amount, 'setup_fee' => $setupFee, 'enabled' => $enabled]);
        });
    }
}
