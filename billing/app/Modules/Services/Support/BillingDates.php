<?php

namespace App\Modules\Services\Support;

use App\Enums\BillingCycle;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Anchored period arithmetic and the "paid through" recomputation (services.md §4).
 */
class BillingDates
{
    public static function periodEnd(BillingCycle $cycle, CarbonImmutable $start, ?int $anchorDay): CarbonImmutable
    {
        return $cycle->advance($start, 1, $anchorDay);
    }

    public static function periodStartBefore(BillingCycle $cycle, CarbonImmutable $end, ?int $anchorDay): CarbonImmutable
    {
        return $cycle->advance($end, -1, $anchorDay);
    }

    /**
     * Start of the earliest unpaid period: walk forward from the current due date across
     * contiguous paid periods. Re-running with the same links never advances twice.
     *
     * @param  Collection<int, ServiceInvoice>  $links
     */
    public static function paidThrough(CarbonImmutable $currentDue, Collection $links): CarbonImmutable
    {
        $paid = $links
            ->filter(fn (ServiceInvoice $link): bool => $link->status === InvoiceLinkStatus::Paid && $link->period_start !== null && $link->period_end !== null)
            ->sortBy(fn (ServiceInvoice $link): string => $link->period_start?->toDateString() ?? '');

        $due = $currentDue;

        do {
            $advanced = false;

            foreach ($paid as $link) {
                /** @var CarbonImmutable $start */
                $start = $link->period_start;
                /** @var CarbonImmutable $end */
                $end = $link->period_end;

                if ($start->lte($due) && $end->gt($due)) {
                    $due = $end;
                    $advanced = true;
                }
            }
        } while ($advanced);

        return $due;
    }
}
