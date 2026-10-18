<?php

namespace App\Modules\Billing\Database\Factories;

use App\Enums\BillableType;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw invoice rows for listings. Use the Invoicer/IssueInvoice action when the test needs real money flows.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $total = fake()->numberBetween(5, 500) * 100;

        return [
            'client_id' => Client::factory(),
            'number' => 'INV-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => InvoiceStatus::Unpaid,
            'currency' => 'USD',
            'issue_date' => CarbonImmutable::today()->subDays(3),
            'due_date' => CarbonImmutable::today()->addDays(11),
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
            'client_snapshot' => ['name' => fake()->name()],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            if ($invoice->items()->doesntExist()) {
                $invoice->items()->create([
                    'type' => BillableType::Manual,
                    'description' => 'Hosting services',
                    'quantity' => 1,
                    'unit_amount' => $invoice->subtotal,
                    'amount' => $invoice->subtotal,
                    'taxable' => false,
                ]);
            }
        });
    }

    public function draft(): static
    {
        return $this->state(['status' => InvoiceStatus::Draft, 'number' => null, 'issue_date' => null]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $attributes['total'],
            'balance' => 0,
            'paid_at' => now(),
        ]);
    }

    public function overdue(int $days = 5): static
    {
        return $this->state([
            'issue_date' => CarbonImmutable::today()->subDays($days + 14),
            'due_date' => CarbonImmutable::today()->subDays($days),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now(), 'balance' => 0]);
    }
}
