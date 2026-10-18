<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AddonKind;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    protected $model = Addon::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word().' '.fake()->word()),
            'description' => fake()->sentence(),
            'kind' => AddonKind::Feature,
            'payment_type' => PaymentType::Recurring,
            'is_taxable' => true,
            'show_on_order' => true,
            'is_hidden' => false,
            'is_retired' => false,
            'suspend_parent_on_overdue' => false,
            'weight' => 0,
            'allow_quantity' => false,
            'prorata_with_parent' => false,
            'auto_setup' => AutoSetup::OnPayment,
            'provisioning_driver' => 'none',
            'driver_config' => [],
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

    /**
     * Add a price row (amount and setup fee in minor units).
     */
    public function withPrice(string $currency, BillingCycle $cycle, int $amount, int $setupFee = 0, bool $enabled = true): static
    {
        return $this->afterCreating(function (Addon $addon) use ($currency, $cycle, $amount, $setupFee, $enabled): void {
            $addon->prices()->create(['currency' => $currency, 'cycle' => $cycle, 'amount' => $amount, 'setup_fee' => $setupFee, 'enabled' => $enabled]);
        });
    }
}
