<?php

namespace App\Modules\Billing\Models;

use App\Contracts\Core\CompanyClock;
use App\Data\Billing\InvoiceLineData;
use App\Modules\Billing\Database\Factories\InvoiceFactory;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TaxExemptReason;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Core\Models\Client;
use App\Support\Money;
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
 * @property int $client_id
 * @property string|null $number
 * @property string|null $proforma_number
 * @property InvoiceStatus $status
 * @property InvoiceKind $kind
 * @property string $source
 * @property string $currency
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int $subtotal
 * @property int $discount_total
 * @property string|null $tax1_name
 * @property string $tax1_rate
 * @property int $tax1_amount
 * @property string|null $tax2_name
 * @property string $tax2_rate
 * @property int $tax2_amount
 * @property bool $tax_compound
 * @property bool $tax_inclusive
 * @property TaxExemptReason|null $tax_exempt_reason
 * @property int $total
 * @property int $amount_paid
 * @property int $credit_applied
 * @property int $adjustments_total
 * @property int $balance
 * @property string|null $payment_gateway
 * @property array<string, mixed>|null $client_snapshot
 * @property array<string, string|null>|null $seller_snapshot
 * @property string|null $notes
 * @property string|null $admin_notes
 * @property CarbonImmutable|null $last_capture_attempt_at
 * @property int $capture_attempts
 * @property CarbonImmutable|null $first_capture_failure_on
 * @property array<string, string>|null $reminder_log
 * @property CarbonImmutable|null $late_fee_applied_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property CarbonImmutable|null $numbered_at the instant the legal number was assigned; frozen with it
 * @property int|null $fiscal_taxable tax-invoice value at numbering: taxable, exempt, taxes, total (ADR 0024 §2)
 * @property int|null $fiscal_exempt
 * @property int|null $fiscal_tax1
 * @property int|null $fiscal_tax2
 * @property int|null $fiscal_total
 * @property string|null $report_currency
 * @property string|null $report_rate decimal string, 12 places
 * @property string|null $supply_class taxable|exempt|export
 * @property string|null $bs_issue_date
 * @property string|null $bs_period
 * @property string|null $bs_fiscal_year
 * @property int|null $tax1_rule_id the tax rule that won each level when the invoice was calculated (kept at rate 0)
 * @property int|null $tax2_rule_id
 * @property bool|null $fiscal_tax1_is_vat whether that level is Nepal VAT; null = not classified (VAT mode off, or numbered before it)
 * @property bool|null $fiscal_tax2_is_vat
 * @property int $credited_total value credited after numbering; written only by InvoiceSettlement::adjust
 * @property int $debited_total
 * @property int $version
 * @property int $print_count PDF downloads of the tax invoice while Nepal VAT mode is on (DocumentDownloads)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, InvoiceAdjustment> $adjustments
 * @property-read Collection<int, Transaction> $transactions
 */
#[UseFactory(InvoiceFactory::class)]
#[Fillable([
    'client_id', 'number', 'proforma_number', 'status', 'kind', 'source', 'currency', 'issue_date', 'due_date',
    'paid_at', 'cancelled_at', 'subtotal', 'discount_total', 'tax1_name', 'tax1_rate', 'tax1_amount', 'tax2_name',
    'tax2_rate', 'tax2_amount', 'tax_compound', 'tax_inclusive', 'tax_exempt_reason', 'tax1_rule_id', 'tax2_rule_id', 'total', 'amount_paid',
    'credit_applied', 'adjustments_total', 'balance', 'payment_gateway', 'client_snapshot', 'seller_snapshot', 'notes', 'admin_notes',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** Statuses whose balance is still owed (with balance > 0); see scopeOutstanding(). */
    public const OUTSTANDING_STATUSES = [InvoiceStatus::Unpaid->value, InvoiceStatus::Collections->value, InvoiceStatus::PaymentPending->value];

    /** Columns frozen once an invoice has been issued (§2: issued invoices are immutable). */
    private const FROZEN = ['client_id', 'currency', 'subtotal', 'discount_total', 'tax1_name', 'tax1_rate', 'tax1_amount',
        'tax2_name', 'tax2_rate', 'tax2_amount', 'tax_compound', 'tax_inclusive', 'total'];

    /**
     * Frozen with the number (ADR 0024 §1): once `numbered_at` is set none of these changes. The one change allowed is
     * the transition that assigns the number (`numbered_at` still empty). `credited_total` and `debited_total` are
     * caches that InvoiceSettlement::adjust keeps, so they are not listed.
     */
    private const FROZEN_WITH_NUMBER = ['number', 'numbered_at', 'issue_date', 'client_snapshot', 'seller_snapshot',
        'fiscal_taxable', 'fiscal_exempt', 'fiscal_tax1', 'fiscal_tax2', 'fiscal_total', 'report_currency', 'report_rate',
        'supply_class', 'bs_issue_date', 'bs_period', 'bs_fiscal_year', 'fiscal_tax1_is_vat', 'fiscal_tax2_is_vat'];

    /** Bookkeeping columns that do not change the rendered document (no PDF version bump). */
    private const UNVERSIONED = ['version', 'updated_at', 'last_capture_attempt_at', 'capture_attempts',
        'first_capture_failure_on', 'reminder_log', 'overdue_notified_at', 'admin_notes', 'print_count'];

    protected $attributes = [
        'status' => 'draft',
        'kind' => 'standard',
        'source' => 'manual',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'kind' => InvoiceKind::class,
            'tax_exempt_reason' => TaxExemptReason::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'tax1_rate' => 'decimal:3',
            'tax2_rate' => 'decimal:3',
            'tax_compound' => 'boolean',
            'fiscal_tax1_is_vat' => 'boolean',
            'fiscal_tax2_is_vat' => 'boolean',
            'tax_inclusive' => 'boolean',
            'client_snapshot' => 'array',
            'seller_snapshot' => 'array',
            'reminder_log' => 'array',
            'last_capture_attempt_at' => 'datetime',
            'first_capture_failure_on' => 'date',
            'late_fee_applied_at' => 'datetime',
            'numbered_at' => 'datetime',
            'fiscal_taxable' => 'integer',
            'fiscal_exempt' => 'integer',
            'fiscal_tax1' => 'integer',
            'fiscal_tax2' => 'integer',
            'fiscal_total' => 'integer',
            'report_rate' => 'decimal:12',
            'credited_total' => 'integer',
            'debited_total' => 'integer',
            'overdue_notified_at' => 'datetime',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax1_amount' => 'integer',
            'tax2_amount' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'credit_applied' => 'integer',
            'adjustments_total' => 'integer',
            'balance' => 'integer',
            'capture_attempts' => 'integer',
            'version' => 'integer',
            'print_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $wasIssued = $invoice->getOriginal('status') !== InvoiceStatus::Draft;

            if ($wasIssued && ($invoice->isDirty(self::FROZEN) || ($invoice->getOriginal('number') !== null && $invoice->isDirty('number')))) {
                throw ImmutableInvoiceException::make();
            }

            if ($invoice->getOriginal('numbered_at') !== null && $invoice->isDirty(self::FROZEN_WITH_NUMBER)) {
                throw ImmutableInvoiceException::numbered();
            }

            if (array_diff(array_keys($invoice->getDirty()), self::UNVERSIONED) !== []) {
                $invoice->version = $invoice->version + 1;
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->numbered_at !== null || $invoice->number !== null) {
                throw ImmutableInvoiceException::numbered();
            }
        });
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<InvoiceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class)->orderBy('id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Invoice number shown to people: legal number, else proforma number, else "Draft #id".
     */
    public function displayNumber(): string
    {
        return $this->number ?? $this->proforma_number ?? __('Draft #:id', ['id' => $this->id]);
    }

    /**
     * "overdue" is derived (unpaid and past due), never stored.
     */
    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        $today ??= app(CompanyClock::class)->todayDate();

        return $this->status === InvoiceStatus::Unpaid && $this->due_date !== null && $this->due_date->lt($today->startOfDay());
    }

    public function daysOverdue(?CarbonImmutable $today = null): int
    {
        $today ??= app(CompanyClock::class)->todayDate();

        return $this->isOverdue($today) && $this->due_date !== null ? (int) $this->due_date->diffInDays($today->startOfDay()) : 0;
    }

    /**
     * Status for display (sends "overdue" instead of "unpaid" when past due).
     */
    public function displayStatus(?CarbonImmutable $today = null): string
    {
        return $this->isOverdue($today) ? 'overdue' : $this->status->value;
    }

    /**
     * balance = total + Σ debit − Σ credit − amount_paid (§2).
     */
    public function recalculateBalance(): void
    {
        $this->balance = $this->total + $this->adjustments_total - $this->amount_paid;
    }

    public function money(int $amount): Money
    {
        return Money::of($amount, $this->currency);
    }

    public function balanceMoney(): Money
    {
        return $this->money($this->balance);
    }

    /**
     * Line DTOs for events such as InvoicePaid (cancelled lines excluded).
     *
     * @return list<InvoiceLineData>
     */
    public function lineData(): array
    {
        return array_values($this->items
            ->filter(fn (InvoiceItem $item): bool => $item->cancelled_at === null)
            ->map(fn (InvoiceItem $item): InvoiceLineData => $item->toLineData())
            ->all());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonImmutable $today = null): void
    {
        $query->where('status', InvoiceStatus::Unpaid)->whereDate('due_date', '<', ($today ?? app(CompanyClock::class)->todayDate())->toDateString());
    }

    /**
     * Still owed: unpaid, in collections or awaiting a payment confirmation, with a balance left.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', self::OUTSTANDING_STATUSES)->where('balance', '>', 0);
    }

    /**
     * Filter by display status, including the derived "overdue" and "outstanding".
     *
     * @param  Builder<self>  $query
     */
    public function scopeDisplayStatus(Builder $query, ?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        if ($status === 'outstanding') {
            $query->outstanding();

            return;
        }

        if ($status === 'overdue') {
            $query->overdue();

            return;
        }

        $query->where('status', $status);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->whereLike('number', '%'.$term.'%')->orWhereLike('proforma_number', '%'.$term.'%');

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
