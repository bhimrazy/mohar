<?php

namespace App\Modules\Orders\Console\Commands;

use App\Contracts\Core\CompanyClock;
use App\Modules\Orders\Actions\CancelStalePendingOrders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:cancel-stale-pending')]
#[Description('Cancel pending orders whose invoice stayed unpaid for orders.auto_cancel_unpaid_days')]
class CancelStalePending extends Command
{
    public function handle(CancelStalePendingOrders $cancel, CompanyClock $clock): int
    {
        $this->info("Cancelled {$cancel->handle($clock->todayDate())} stale orders.");

        return self::SUCCESS;
    }
}
