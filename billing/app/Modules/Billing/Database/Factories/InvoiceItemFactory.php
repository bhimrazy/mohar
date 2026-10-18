<?php

namespace App\Modules\Billing\Database\Factories;

use App\Enums\BillableType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(1, 100) * 100;

        return [
            'invoice_id' => Invoice::factory(),
            'type' => BillableType::Manual,
            'description' => fake()->sentence(3),
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'taxable' => true,
        ];
    }
}
