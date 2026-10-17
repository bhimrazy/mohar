<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Support\Billing;
use Carbon\CarbonImmutable;

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
            ->orderBy('id')
            ->each(function (ServiceChange $change) use (&$count): void {
                if ($change->invoice_id !== null && $this->billing->available()) {
                    if ($this->billing->invoicer()->status($change->invoice_id) === 'paid') {
                        return;
                    }

                    $this->billing->invoicer()->cancel($change->invoice_id);
                }

                $change->update(['status' => ChangeStatus::Expired]);
                $count++;
            });

        return $count;
    }
}
