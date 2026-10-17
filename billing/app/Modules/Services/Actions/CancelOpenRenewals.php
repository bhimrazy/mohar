<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceInvoice;
use App\Modules\Services\Support\Billing;
use Carbon\CarbonImmutable;

/**
 * Cancels unpaid renewal invoices of a service (and its addons) for periods from $from onwards.
 *
 * Renewal invoices are issued per service (with its addons), so cancelling the invoice cancels
 * exactly this service's renewal lines.
 */
class CancelOpenRenewals
{
    public function __construct(private Billing $billing) {}

    /**
     * @return int number of invoices cancelled
     */
    public function handle(Service $service, ?CarbonImmutable $from = null): int
    {
        $addonIds = $service->addons()->pluck('id');

        $invoiceIds = ServiceInvoice::query()
            ->where('kind', InvoiceLinkKind::Renewal->value)
            ->where('status', InvoiceLinkStatus::Open->value)
            ->where(fn ($query) => $query->where('service_id', $service->id)->orWhereIn('service_addon_id', $addonIds))
            ->when($from !== null, fn ($query) => $query->whereDate('period_start', '>=', $from))
            ->distinct()
            ->pluck('invoice_id');

        foreach ($invoiceIds as $invoiceId) {
            if ($this->billing->available()) {
                $this->billing->invoicer()->cancel((int) $invoiceId);
            }

            ServiceInvoice::query()->where('invoice_id', $invoiceId)->update(['status' => InvoiceLinkStatus::Cancelled->value]);
        }

        return $invoiceIds->count();
    }
}
