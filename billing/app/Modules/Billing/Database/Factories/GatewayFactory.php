<?php

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Models\Gateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gateway>
 */
class GatewayFactory extends Factory
{
    protected $model = Gateway::class;

    public function definition(): array
    {
        return [
            'key' => 'bank_transfer',
            'display_name' => 'Bank transfer',
            'is_active' => true,
            'show_on_order_form' => true,
            'sort_order' => 0,
            'config' => ['instructions' => 'Transfer to IBAN DE00 1234 5678 9012 quoting {invoice}.'],
        ];
    }

    public function sandbox(string $secret = 'whsec_test'): static
    {
        return $this->state(['key' => 'sandbox_card', 'display_name' => 'Credit card', 'sort_order' => 1, 'config' => ['webhook_secret' => $secret, 'fee_percent' => '0']]);
    }

    public function manual(): static
    {
        return $this->state(['key' => 'manual', 'display_name' => 'Manual payment', 'show_on_order_form' => false, 'sort_order' => 2, 'config' => []]);
    }
}
