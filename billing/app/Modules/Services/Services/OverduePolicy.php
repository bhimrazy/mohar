<?php

namespace App\Modules\Services\Services;

use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use App\Modules\Services\Support\Billing;
use Carbon\CarbonImmutable;

/**
 * Days overdue for suspension/termination (services.md §4.5): counted from next_due_date, and only
 * while an open, non-cancelled invoice bills the period starting on next_due_date.
 */
class OverduePolicy
{
    public function __construct(private Billing $billing) {}

    public function daysOverdue(Service|ServiceAddon $item, CarbonImmutable $today): ?int
    {
        $due = $item->next_due_date;

        if ($due === null || $due->gte($today)) {
            return null;
        }

        $link = ServiceInvoice::query()
            ->where($item instanceof Service ? 'service_id' : 'service_addon_id', $item->id)
            ->whereDate('period_start', $due)
            ->where('status', InvoiceLinkStatus::Open->value)
            ->first();

        if ($link === null || ! $this->stillOpen($link)) {
            return null;
        }

        return (int) $due->diffInDays($today);
    }

    /**
     * Confirm with Billing that the invoice is still unpaid; sync the local mirror when it is not.
     */
    private function stillOpen(ServiceInvoice $link): bool
    {
        if (! $this->billing->available()) {
            return true;
        }

        $status = $this->billing->invoicer()->status($link->invoice_id);

        if (in_array($status, Billing::OPEN_STATUSES, true)) {
            return true;
        }

        $link->update(['status' => $status === 'paid' ? InvoiceLinkStatus::Paid : InvoiceLinkStatus::Cancelled]);

        return false;
    }
}
