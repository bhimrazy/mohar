<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Core\Models\Client;
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
 * @property int $total minor units, positive; the kind gives the direction
 * @property string|null $report_rate
 * @property string $bs_period
 * @property int $print_count
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
    'tax2_amount', 'total', 'report_currency', 'report_rate', 'supply_class', 'seller_snapshot', 'buyer_snapshot',
    'bs_issue_date', 'bs_period', 'bs_fiscal_year', 'automatic', 'created_by', 'print_count', 'cbms_status', 'cbms_reference',
])]
class TaxNote extends Model
{
    /** The only columns that change after numbering (vat-register §9). */
    public const MUTABLE = ['print_count', 'cbms_status', 'cbms_reference', 'updated_at'];

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
