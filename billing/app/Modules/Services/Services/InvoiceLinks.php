<?php

namespace App\Modules\Services\Services;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;

/**
 * Maintains the local invoice ↔ period mirror (service_invoices): one row per (invoice, item),
 * because an invoice bills at most one period of an item. Recording it again updates that row;
 * a paid row never goes back to open and keeps its period.
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
            ->orderBy('id')
            ->first() ?? new ServiceInvoice([$column => $item->id, 'invoice_id' => $invoiceId, 'kind' => $kind, 'status' => $status]);

        if ($link->status !== InvoiceLinkStatus::Paid || ! $link->exists) {
            $link->fill(['period_start' => $periodStart, 'period_end' => $periodEnd, 'amount' => $amount, 'status' => $status]);
        }

        $link->save();

        return $link;
    }

    public function markInvoice(int $invoiceId, InvoiceLinkStatus $status): int
    {
        return ServiceInvoice::query()->where('invoice_id', $invoiceId)->where('status', InvoiceLinkStatus::Open->value)->update(['status' => $status->value]);
    }
}
