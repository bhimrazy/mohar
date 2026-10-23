<?php

namespace App\Modules\Domains\Jobs\Concerns;

use App\Modules\Domains\Jobs\Middleware\OnePerDomain;
use DateTimeInterface;

/**
 * For jobs that call the registrar about $domainId: they share one per-domain lock (see OnePerDomain).
 *
 * Laravel counts every release against `tries`, so waiting for the lock would use up a paid job's attempts. These
 * jobs therefore set no `tries`: they are retried until retryUntil() (long enough to outwait any holder, then they
 * fail visibly through failed()), and fail after $maxExceptions real errors or timeouts (a crashed worker counts too).
 */
trait GuardsDomainRegistrar
{
    /** Real failures (an exception, a timeout, a crash) before the job fails; waiting for the lock is not one. */
    public int $maxExceptions = 3;

    public bool $countCrashesAsExceptions = true;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new OnePerDomain($this->domainId)];
    }

    /** Minutes of waiting and retrying allowed; inside uniqueFor, far above any holder (a job's 180 s timeout). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
