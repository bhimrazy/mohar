<?php

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Core\Models\Client;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(5, 200) * 100;

        return [
            'number' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'client_id' => Client::factory(),
            'kind' => OrderKind::New,
            'status' => OrderStatus::Pending,
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'setup_total' => 0,
            'tax_total' => 0,
            'total' => $subtotal,
            'payment_gateway' => 'bank_transfer',
            'invoice_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'ip_address' => fake()->ipv4(),
            'fraud_provider' => 'rules',
            'fraud_score' => 0,
            'fraud_decision' => FraudDecision::Pass,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function fraud(): static
    {
        return $this->state(['status' => OrderStatus::Fraud, 'fraud_decision' => FraudDecision::Fail]);
    }
}
