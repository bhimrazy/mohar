<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Services\PaymentReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:reconcile-payments {--only= : "open" (pending and unclear attempts) or "late" (closed attempts, for late payments); both when omitted}')]
#[Description('Look up hosted payment attempts at their gateways and record confirmed payments')]
class ReconcilePayments extends Command
{
    public function handle(PaymentReconciler $reconciler): int
    {
        $only = $this->option('only');

        if (! in_array($only, [null, 'open', 'late'], true)) {
            $this->components->error('--only must be "open" or "late".');

            return self::INVALID;
        }

        $open = $only !== 'late' ? $reconciler->run() : 0;
        $late = $only !== 'open' ? $reconciler->runLate() : 0;
        $this->components->info(($open + $late).' attempts checked.');

        return self::SUCCESS;
    }
}
