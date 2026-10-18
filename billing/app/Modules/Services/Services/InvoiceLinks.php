<?php

namespace App\Modules\Services\Services;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        int $discount = 0,
    ): ServiceInvoice {
        $column = $item instanceof Service ? 'service_id' : 'service_addon_id';

        $link = ServiceInvoice::query()
            ->where($column, $item->id)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->first() ?? new ServiceInvoice([$column => $item->id, 'invoice_id' => $invoiceId, 'kind' => $kind, 'status' => $status]);

        if ($link->status !== InvoiceLinkStatus::Paid || ! $link->exists) {
            $link->fill(['period_start' => $periodStart, 'period_end' => $periodEnd, 'amount' => $amount, 'status' => $status]);

            if ($discount > 0) {
                $link->discount = $discount;
            }
        }

        $link->save();

        return $link;
    }

    /**
     * Mark an invoice's open links cancelled. A cancelled renewal that carried a recurring
     * promotion gives its promotion cycle back, so the re-issued period is discounted again.
     *
     * @return int links cancelled
     */
    public function cancelInvoice(int $invoiceId): int
    {
        return DB::transaction(function () use ($invoiceId): int {
            $links = ServiceInvoice::query()
                ->where('invoice_id', $invoiceId)
                ->where('status', InvoiceLinkStatus::Open->value)
                ->lockForUpdate()
                ->get();

            foreach ($links as $link) {
                $link->update(['status' => InvoiceLinkStatus::Cancelled]);

                if ($link->discount > 0 && $link->service_id !== null) {
                    Service::query()->whereKey($link->service_id)->whereNotNull('promotion_cycles_remaining')->increment('promotion_cycles_remaining');
                }
            }

            return $links->count();
        });
    }
}
