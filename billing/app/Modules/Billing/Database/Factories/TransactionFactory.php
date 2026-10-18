<?php

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(5, 300) * 100;

        return [
            'client_id' => Client::factory(),
            'gateway' => 'bank_transfer',
            'gateway_txn_id' => 'TX-'.Str::upper(Str::random(10)),
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Succeeded,
            'amount_in' => $amount,
            'currency' => 'USD',
            'refundable_remaining' => $amount,
            'occurred_at' => now(),
        ];
    }
}
