<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Core\CompanyClock;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Support\Billing;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation task (CANCELLATIONS): unpaid upgrades past their expiry are expired and their
 * invoices cancelled.
 */
class ExpireUnpaidChanges
{
    public function __construct(private Billing $billing, private CompanyClock $clock) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        ServiceChange::query()
            ->where('status', ChangeStatus::PendingPayment->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $this->clock->dayBounds($today)[1])
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

                    // The invoice goes first: a change whose invoice stayed payable is not expired.
                    if ($billable) {
                        try {
                            // The system expires the change: the credit note is filed, not emailed.
                            $this->billing->invoicer()->cancel((int) $locked->invoice_id, __('Cancelled by :module', ['module' => 'Services']), notify: false);
                        } catch (InvoiceNotCancellable) {
                            return false;
                        }
                    }

                    $locked->update(['status' => ChangeStatus::Expired]);

                    return true;
                });

                $count += $expired ? 1 : 0;
            });

        return $count;
    }
}
