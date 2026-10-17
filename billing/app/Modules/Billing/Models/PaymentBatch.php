<?php

namespace App\Modules\Billing\Models;

use App\Modules\Core\Models\Client;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mass payment of several invoices (§4.6). Gateways charge the batch total; settlement allocates by due date.
 *
 * @property int $id
 * @property int $client_id
 * @property string $currency
 * @property list<int> $invoice_ids
 * @property int $total
 * @property string $status pending|paid|failed
 * @property string|null $gateway
 * @property-read Client $client
 */
#[Fillable(['client_id', 'currency', 'invoice_ids', 'total', 'status', 'gateway'])]
class PaymentBatch extends Model
{
    protected function casts(): array
    {
        return ['invoice_ids' => 'array', 'total' => 'integer'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
