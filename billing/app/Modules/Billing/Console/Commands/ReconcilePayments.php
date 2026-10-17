<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Services\PaymentReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:reconcile-payments')]
#[Description('Look up pending hosted payment attempts at their gateways and record confirmed payments')]
class ReconcilePayments extends Command
{
    public function handle(PaymentReconciler $reconciler): int
    {
        $this->components->info($reconciler->run().' attempts checked.');

        return self::SUCCESS;
    }
}
