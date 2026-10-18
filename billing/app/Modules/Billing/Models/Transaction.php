<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Database\Factories\TransactionFactory;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $client_id
 * @property int|null $invoice_id
 * @property int|null $payment_batch_id
 * @property int|null $pay_method_id
 * @property string $gateway
 * @property string|null $gateway_txn_id
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property int $amount_in
 * @property int $amount_out
 * @property int $fee
 * @property string $currency
 * @property int|null $gateway_amount
 * @property string|null $gateway_currency
 * @property string|null $exchange_rate
 * @property int $refundable_remaining
 * @property int|null $parent_transaction_id
 * @property string|null $description
 * @property array<string, mixed>|null $raw
 * @property int|null $created_by
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property-read Client $client
 * @property-read Invoice|null $invoice
 * @property-read Transaction|null $parent
 * @property-read Collection<int, Transaction> $children
 * @property-read User|null $creator
 */
#[UseFactory(TransactionFactory::class)]
#[Fillable([
    'client_id', 'invoice_id', 'payment_batch_id', 'pay_method_id', 'gateway', 'gateway_txn_id', 'type', 'status',
    'amount_in', 'amount_out', 'fee', 'currency', 'gateway_amount', 'gateway_currency', 'exchange_rate',
    'refundable_remaining', 'parent_transaction_id', 'description', 'raw', 'created_by', 'occurred_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'raw' => 'array',
            'occurred_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
            'amount_in' => 'integer',
            'amount_out' => 'integer',
            'fee' => 'integer',
            'gateway_amount' => 'integer',
            'refundable_remaining' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'parent_transaction_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Transaction::class, 'parent_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRefundable(): bool
    {
        return $this->type->isIncome() && $this->status === TransactionStatus::Succeeded && $this->refundable_remaining > 0;
    }
}
