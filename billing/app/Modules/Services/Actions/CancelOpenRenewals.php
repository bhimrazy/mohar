<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use App\Modules\Services\Services\InvoiceLinks;
use App\Modules\Services\Support\Billing;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;

/**
 * Cancels unpaid renewal invoices of a service (optionally also of its addons) for periods from
 * $from onwards. Renewal invoices bill one item each (ADR 0008), so cancelling the invoice cancels
 * exactly that item's period. Invoices with any payment or applied credit are left alone.
 *
 * Every item whose renewal was cancelled gets next_invoice_date moved back to the earliest
 * cancelled period, so the period is invoiced again once renewals resume (do_not_renew still
 * blocks it while a cancellation is pending).
 */
class CancelOpenRenewals
{
    public function __construct(private Billing $billing, private InvoiceLinks $links) {}

    /**
     * @return int number of invoices cancelled
     */
    public function handle(Service $service, ?CarbonImmutable $from = null, bool $includeAddons = true): int
    {
        $addonIds = $includeAddons ? $service->addons()->pluck('id')->all() : [];

        $links = ServiceInvoice::query()
            ->where('kind', InvoiceLinkKind::Renewal->value)
            ->where('status', InvoiceLinkStatus::Open->value)
            ->where(fn ($query) => $query->where('service_id', $service->id)->orWhereIn('service_addon_id', $addonIds))
            ->when($from !== null, fn ($query) => $query->whereDate('period_start', '>=', $from))
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        $cancelled = [];

        foreach ($links->groupBy('invoice_id') as $invoiceId => $invoiceLinks) {
            if ($this->billing->available()) {
                if (! $this->billing->canCancel((int) $invoiceId)) {
                    continue;
                }

                try {
                    $this->billing->invoicer()->cancel((int) $invoiceId, __('Cancelled by :module', ['module' => 'Services']));
                } catch (InvoiceNotCancellable) {
                    // Still payable: its links stay open and its renewal pointers are not rewound.
                    continue;
                }
            }

            $this->links->cancelInvoice((int) $invoiceId);
            $cancelled[] = $invoiceLinks;
        }

        $this->rewindPointers(collect($cancelled)->flatten(1)->all());

        return count($cancelled);
    }

    /**
     * @param  array<int, ServiceInvoice>  $links
     */
    private function rewindPointers(array $links): void
    {
        $earliest = [];

        foreach ($links as $link) {
            if ($link->period_start === null) {
                continue;
            }

            $key = $link->service_id !== null ? "s{$link->service_id}" : "a{$link->service_addon_id}";

            if (! isset($earliest[$key]) || $link->period_start->lt($earliest[$key])) {
                $earliest[$key] = $link->period_start;
            }
        }

        foreach ($earliest as $key => $start) {
            $model = str_starts_with($key, 's')
                ? Service::query()->find((int) substr($key, 1))
                : ServiceAddon::query()->find((int) substr($key, 1));

            if ($model !== null && ($model->next_invoice_date === null || $model->next_invoice_date->gt($start))) {
                $model->update(['next_invoice_date' => $start]);
            }
        }
    }
}
