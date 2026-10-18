<?php

namespace App\Modules\Orders\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(5, 50) * 100;

        return [
            'order_id' => Order::factory(),
            'item_type' => OrderItemType::Service,
            'item_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'product_id' => 1,
            'status' => OrderItemStatus::Pending,
            'description' => 'Starter Hosting',
            'domain' => fake()->domainName(),
            'cycle' => BillingCycle::Monthly,
            'quantity' => 1,
            'first_amount' => $amount,
            'recurring_amount' => $amount,
            'setup_fee' => 0,
            'discount_amount' => 0,
            'auto_setup' => 'payment',
            'stock_reserved' => false,
        ];
    }

    public function status(OrderItemStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function domain(string $name = 'example.com'): static
    {
        return $this->state([
            'item_type' => OrderItemType::Domain,
            'product_id' => null,
            'description' => "Domain registration: {$name}",
            'domain' => $name,
            'cycle' => null,
            'auto_setup' => null,
        ]);
    }
}
