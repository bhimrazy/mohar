<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only credit ledger row; the client's balance is the latest balance_after per currency.
 *
 * @property int $id
 * @property int $client_id
 * @property string $currency
 * @property int $amount
 * @property int $balance_after
 * @property CreditReason $reason
 * @property int|null $invoice_id
 * @property int|null $transaction_id
 * @property string|null $description
 * @property int|null $staff_id
 * @property CarbonImmutable|null $created_at
 * @property-read Client $client
 * @property-read Invoice|null $invoice
 * @property-read User|null $staff
 */
#[Fillable(['client_id', 'currency', 'amount', 'balance_after', 'reason', 'invoice_id', 'transaction_id', 'description', 'staff_id'])]
class CreditLedgerEntry extends Model
{
    protected $table = 'credit_ledger';

    protected function casts(): array
    {
        return [
            'reason' => CreditReason::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
