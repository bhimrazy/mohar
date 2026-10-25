<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Support\BikramSambatDate;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A numbered credit or debit note against one invoice (ADR 0024). It is numbered in the transaction that creates it, so
 * `created_at` is the instant of numbering. From then on only the print count and the reserved CBMS columns change;
 * the model refuses every other change and every delete. A mistaken note is reversed by an opposite note.
 *
 * @property int $id
 * @property string $kind credit|debit
 * @property string $number
 * @property int $invoice_id
 * @property int $client_id
 * @property string $currency
 * @property CarbonImmutable $issue_date
 * @property string $bs_issue_date
 * @property string $reason_code
 * @property string $reason_text client-visible
 * @property int $tax1_amount
 * @property int $tax2_amount
 * @property bool|null $tax1_is_vat whether the level is Nepal VAT, copied from the invoice; null = not classified
 * @property bool|null $tax2_is_vat
 * @property bool $automatic
 * @property int $total minor units, positive; the kind gives the direction
 * @property string|null $report_rate
 * @property string $bs_period
 * @property int $print_count
 * @property int|null $reverses_tax_note_id the credit note this debit note reverses
 * @property string|null $cbms_status
 * @property string|null $cbms_reference
 * @property-read Invoice $invoice
 * @property-read Client $client
 * @property-read User|null $creator
 * @property-read Collection<int, TaxNoteLine> $lines
 */
#[Fillable([
    'kind', 'number', 'invoice_id', 'client_id', 'currency', 'issue_date', 'issued_at', 'invoice_number',
    'invoice_bs_issue_date', 'reason_code', 'reason_text', 'taxable_amount', 'exempt_amount', 'tax1_amount',
    'tax2_amount', 'total', 'report_currency', 'report_rate', 'supply_class', 'tax1_is_vat', 'tax2_is_vat', 'seller_snapshot', 'buyer_snapshot',
    'bs_issue_date', 'bs_period', 'bs_fiscal_year', 'automatic', 'created_by', 'print_count', 'cbms_status', 'cbms_reference', 'reverses_tax_note_id',
])]
class TaxNote extends Model
{
    /** The only columns that change after numbering (vat-register §9). */
    public const MUTABLE = ['print_count', 'cbms_status', 'cbms_reference', 'updated_at'];

    /** Each reason code in words, as the client reads it on the note, the client area and the email. */
    private const REASONS = [
        'invoice_cancelled' => 'Invoice cancelled', 'lines_cancelled' => 'Lines cancelled', 'refunded' => 'Refunded',
        'refunded_at_provider' => 'Refunded by the payment provider', 'service_not_delivered' => 'Service not delivered',
        'price_correction' => 'Price correction', 'duplicate_invoice' => 'Duplicate invoice', 'discount_after_sale' => 'Discount after sale',
        'goodwill' => 'Goodwill', 'late_fee' => 'Late payment fee', 'reverses_credit_note' => 'Reverses a credit note', 'other' => 'Other',
    ];

    public function reasonLabel(): string
    {
        return __(self::REASONS[$this->reason_code] ?? 'Other');
    }

    /** A stored BS date (Y-m-d) as "28 Poush 2082 BS", or null. */
    public static function bsLabel(?string $bsDate): ?string
    {
        if ($bsDate === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bsDate, $parts) !== 1) {
            return null;
        }

        return (new BikramSambatDate((int) $parts[1], (int) $parts[2], (int) $parts[3]))->format();
    }

    public function kindLabel(): string
    {
        return $this->kind === 'credit' ? __('Credit Note') : __('Debit Note');
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'report_rate' => 'decimal:12',
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'automatic' => 'boolean',
            'taxable_amount' => 'integer',
            'exempt_amount' => 'integer',
            'tax1_amount' => 'integer',
            'tax2_amount' => 'integer',
            'tax1_is_vat' => 'boolean',
            'tax2_is_vat' => 'boolean',
            'total' => 'integer',
            'print_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TaxNote $note): void {
            if (array_diff(array_keys($note->getDirty()), self::MUTABLE) !== []) {
                throw ImmutableInvoiceException::numbered();
            }
        });

        static::deleting(fn () => throw ImmutableInvoiceException::numbered());
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TaxNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxNoteLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
