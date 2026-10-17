<?php

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Enums\QuoteStage;
use App\Modules\Billing\Models\Quote;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'subject' => 'Dedicated server proposal',
            'stage' => QuoteStage::Draft,
            'currency' => 'USD',
            'valid_until' => CarbonImmutable::today()->addDays(30),
            'subtotal' => 10000,
            'total' => 10000,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Quote $quote): void {
            if ($quote->items()->doesntExist()) {
                $quote->items()->create(['description' => 'Dedicated server setup', 'quantity' => 1, 'unit_amount' => $quote->subtotal, 'amount' => $quote->subtotal, 'taxable' => false]);
            }
        });
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => ['stage' => QuoteStage::Delivered, 'number' => 'Q-'.fake()->unique()->numberBetween(100, 99999), 'delivered_at' => now()]);
    }
}
