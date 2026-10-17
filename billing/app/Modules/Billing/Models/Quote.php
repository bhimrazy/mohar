<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Database\Factories\QuoteFactory;
use App\Modules\Billing\Enums\QuoteStage;
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
 * @property string|null $number
 * @property int $client_id
 * @property string $subject
 * @property QuoteStage $stage
 * @property string $currency
 * @property CarbonImmutable|null $valid_until
 * @property string|null $proposal_text
 * @property string|null $customer_notes
 * @property string|null $admin_notes
 * @property int $subtotal
 * @property string|null $tax1_name
 * @property string $tax1_rate
 * @property int $tax1_amount
 * @property string|null $tax2_name
 * @property string $tax2_rate
 * @property int $tax2_amount
 * @property int $total
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $declined_at
 * @property int|null $invoice_id
 * @property CarbonImmutable|null $created_at
 * @property-read Client $client
 * @property-read Invoice|null $invoice
 * @property-read Collection<int, QuoteItem> $items
 */
#[UseFactory(QuoteFactory::class)]
#[Fillable([
    'number', 'client_id', 'subject', 'stage', 'currency', 'valid_until', 'proposal_text', 'customer_notes', 'admin_notes',
    'subtotal', 'tax1_name', 'tax1_rate', 'tax1_amount', 'tax2_name', 'tax2_rate', 'tax2_amount', 'total',
    'delivered_at', 'accepted_at', 'declined_at', 'invoice_id',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected $attributes = ['stage' => 'draft'];

    protected function casts(): array
    {
        return [
            'stage' => QuoteStage::class,
            'valid_until' => 'date',
            'delivered_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'tax1_rate' => 'decimal:3',
            'tax2_rate' => 'decimal:3',
            'subtotal' => 'integer',
            'tax1_amount' => 'integer',
            'tax2_amount' => 'integer',
            'total' => 'integer',
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
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Quote #:id', ['id' => $this->id]);
    }
}
