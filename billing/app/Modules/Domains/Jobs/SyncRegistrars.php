<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\SyncDomains;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The hourly registrar sync, on the registrar queue so the scheduler process never waits on a registrar (ADR 0014,
 * ADR 0017 §2). It stops taking new domains after SYNC_BUDGET_SECONDS; the rest are oldest-first in the next run.
 */
class SyncRegistrars implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Seconds of work before the run stops taking new domains: one more registrar call still fits the timeout. */
    public const SYNC_BUDGET_SECONDS = 70;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(Queues::REGISTRAR);
    }

    public function handle(SyncDomains $sync): void
    {
        $sync->handle(budgetSeconds: self::SYNC_BUDGET_SECONDS);
    }
}
