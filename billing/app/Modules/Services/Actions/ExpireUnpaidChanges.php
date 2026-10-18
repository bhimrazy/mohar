<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Support\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation task (CANCELLATIONS): unpaid upgrades past their expiry are expired and their
 * invoices cancelled.
 */
class ExpireUnpaidChanges
{
    public function __construct(private Billing $billing) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        ServiceChange::query()
            ->where('status', ChangeStatus::PendingPayment->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $today->endOfDay())
            ->eachById(function (ServiceChange $change) use (&$count): void {
                $expired = DB::transaction(function () use ($change): bool {
                    $locked = ServiceChange::query()->lockForUpdate()->find($change->id);

                    if ($locked === null || $locked->status !== ChangeStatus::PendingPayment) {
                        return false;
                    }

                    $billable = $locked->invoice_id !== null && $this->billing->available();

                    if ($billable && ($this->billing->invoicer()->status($locked->invoice_id) === 'paid' || ! $this->billing->canCancel($locked->invoice_id))) {
                        return false;
                    }

                    $locked->update(['status' => ChangeStatus::Expired]);

                    if ($billable) {
                        $this->billing->invoicer()->cancel((int) $locked->invoice_id);
                    }

                    return true;
                });

                $count += $expired ? 1 : 0;
            });

        return $count;
    }
}
