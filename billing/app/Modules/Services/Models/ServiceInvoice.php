<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which invoice bills which period of a service/addon. Kept in sync from Billing events so
 * automation can tell "open invoice for the overdue period" without reading Billing tables.
 *
 * @property int $id
 * @property int|null $service_id
 * @property int|null $service_addon_id
 * @property int $invoice_id
 * @property InvoiceLinkKind $kind
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int $amount
 * @property InvoiceLinkStatus $status
 */
#[Fillable(['service_id', 'service_addon_id', 'invoice_id', 'kind', 'period_start', 'period_end', 'amount', 'status'])]
class ServiceInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => InvoiceLinkKind::class,
            'status' => InvoiceLinkStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
