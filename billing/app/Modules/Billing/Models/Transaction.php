<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Database\Factories\TransactionFactory;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $tax_note_id the credit note a refund became (ADR 0024 §5)
 * @property string|null $purpose `credit_refund` for a payout of account credit (ADR 0024 §5a); null otherwise
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
 * @property int|null $review_amount
 * @property CarbonImmutable|null $reviewed_at
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
    'refundable_remaining', 'parent_transaction_id', 'description', 'raw', 'created_by', 'occurred_at', 'review_amount',
    'reviewed_at', 'tax_note_id', 'purpose',
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
            'exchange_rate' => 'decimal:12',
            'amount_in' => 'integer',
            'amount_out' => 'integer',
            'fee' => 'integer',
            'gateway_amount' => 'integer',
            'refundable_remaining' => 'integer',
            'review_amount' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Chargebacks and provider refunds with a part nothing could absorb, not yet marked reviewed (the to-do).
     *
     * @return Builder<self>
     */
    public static function toReview(): Builder
    {
        return self::query()->whereNotNull('review_amount')->whereNull('reviewed_at');
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

    /** `purpose` of a refund that pays account credit out; such a row never touches an invoice. */
    public const PURPOSE_CREDIT_REFUND = 'credit_refund';

    public function isCreditRefund(): bool
    {
        return $this->purpose === self::PURPOSE_CREDIT_REFUND;
    }

    /**
     * Account credit that credit refunds of these payments paid out, net of the payouts they gave back. Such a payout is tied to its
     * refund row (not the payment), so "credit this payment placed" must subtract it or a later refund takes the same credit twice.
     *
     * @param  list<int>  $paymentIds
     */
    public static function creditPaidOut(array $paymentIds, string $currency): int
    {
        $refunds = self::query()->whereIn('parent_transaction_id', $paymentIds)->where('purpose', self::PURPOSE_CREDIT_REFUND)->select('id');

        return -(int) CreditLedgerEntry::query()->where('currency', $currency)->whereIn('transaction_id', $refunds)
            ->whereIn('reason', [CreditReason::CreditRefunded, CreditReason::CreditRefundReleased])->sum('amount');
    }

    public function isRefundable(): bool
    {
        return $this->type->isIncome() && $this->status === TransactionStatus::Succeeded && $this->refundable_remaining > 0;
    }

    /** Key in `raw` for what a gateway refund row remembers about its own request (ours; never a provider field). */
    public const REFUND_STATE = 'mohar';

    /** Key under REFUND_STATE for the provider refund id a released refund row held, kept off `gateway_txn_id`. */
    public const RELEASED_REFUND_ID = 'released_provider_refund_id';

    /** Key under REFUND_STATE: when the provider reported this refund as failed. A booked row carrying it is on staff review (once). */
    public const PROVIDER_FAILED_AT = 'provider_failed_at';

    /** Key under REFUND_STATE: the amount (document currency) the provider reported failed while the row was pending. */
    public const PROVIDER_FAILED_AMOUNT = 'provider_failed_amount';

    /** A pending refund older than this has outlived any provider call, so staff may check or settle it. */
    public const REFUND_STALE_MINUTES = 10;

    /**
     * A gateway refund that is not booked yet: its request is in flight, or the provider never confirmed it. The
     * amount stays reserved until the provider's callback, a check or staff settle it.
     */
    public function awaitsProviderConfirmation(): bool
    {
        return $this->type === TransactionType::Refund && $this->status === TransactionStatus::Pending;
    }

    /**
     * Pending refunds the provider has not confirmed and whose request is no longer running: the answer was an
     * error or pending, or the row is older than any call lasts.
     */
    public function canBeResolved(): bool
    {
        if (! $this->awaitsProviderConfirmation()) {
            return false;
        }

        return isset($this->raw[self::REFUND_STATE]['answer']) || $this->created_at === null || $this->created_at->lte(now()->subMinutes(self::REFUND_STALE_MINUTES));
    }

    /** A refund a provider that can be asked keeps leaving unknown, after this long, may be settled by hand. */
    public const REFUND_LOOKUP_STUCK_HOURS = 72;

    /**
     * A pending refund whose provider can be asked but whose last check answered "unknown", for three days: the way
     * out for staff who verified it in the provider's dashboard.
     */
    public function stuckAtLookup(): bool
    {
        $lookup = $this->raw[self::REFUND_STATE]['lookup'] ?? [];
        $stuckFrom = $this->created_at?->copy()->addHours(self::REFUND_LOOKUP_STUCK_HOURS);

        // The last Check must itself have come after the wait, so an early "unknown" cannot open the hatch.
        return $this->awaitsProviderConfirmation()
            && ($lookup['answer'] ?? null) === 'error'
            && $stuckFrom !== null && $stuckFrom->lte(now())
            && is_string($lookup['at'] ?? null) && CarbonImmutable::parse($lookup['at'])->gte($stuckFrom);
    }

    /**
     * Gateway refunds the provider has not confirmed (the "Refunds waiting for the provider" to-do and filter),
     * by default only those that have waited past the stale limit.
     *
     * @return Builder<Transaction>
     */
    public static function refundsAwaitingProvider(int $olderThanMinutes = self::REFUND_STALE_MINUTES): Builder
    {
        // Literals, so the planner can use the partial index transactions_refund_pending_index.
        return self::query()->whereRaw("type = 'refund' and status = 'pending'")
            ->when($olderThanMinutes > 0, fn ($query) => $query->where('created_at', '<=', now()->subMinutes($olderThanMinutes)));
    }
}
