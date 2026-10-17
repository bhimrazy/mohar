<?php

namespace App\Modules\Services\Services;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;

/**
 * Maintains the local invoice ↔ period mirror (service_invoices). Recording the same
 * (invoice, item, period) twice updates one row; a paid row never goes back to open.
 */
class InvoiceLinks
{
    public function record(
        Service|ServiceAddon $item,
        int $invoiceId,
        InvoiceLinkKind $kind,
        ?CarbonImmutable $periodStart,
        ?CarbonImmutable $periodEnd,
        int $amount,
        InvoiceLinkStatus $status,
    ): ServiceInvoice {
        $column = $item instanceof Service ? 'service_id' : 'service_addon_id';

        $link = ServiceInvoice::query()
            ->where($column, $item->id)
            ->where('invoice_id', $invoiceId)
            ->when($periodStart !== null, fn ($query) => $query->whereDate('period_start', $periodStart), fn ($query) => $query->whereNull('period_start'))
            ->first() ?? new ServiceInvoice([$column => $item->id, 'invoice_id' => $invoiceId, 'kind' => $kind, 'status' => $status]);

        $link->fill(['period_start' => $periodStart, 'period_end' => $periodEnd, 'amount' => $amount]);

        if ($link->status !== InvoiceLinkStatus::Paid) {
            $link->status = $status;
        }

        $link->save();

        return $link;
    }

    public function markInvoice(int $invoiceId, InvoiceLinkStatus $status): int
    {
        return ServiceInvoice::query()->where('invoice_id', $invoiceId)->where('status', InvoiceLinkStatus::Open->value)->update(['status' => $status->value]);
    }
}
