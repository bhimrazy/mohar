<?php

namespace App\Modules\Domains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoices that bill a domain (issued by this module or seen in InvoicePaid), so lifecycle
 * automation can find, cancel or replace the open renewal invoice of a cycle.
 *
 * @property int $id
 * @property int $domain_id
 * @property int $invoice_id
 * @property string $kind renewal|grace|redemption|order
 * @property CarbonImmutable $period_start
 * @property int $years
 * @property string $status open|paid|cancelled
 */
#[Fillable(['domain_id', 'invoice_id', 'kind', 'period_start', 'years', 'status'])]
class DomainInvoice extends Model
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'years' => 'integer', 'invoice_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
