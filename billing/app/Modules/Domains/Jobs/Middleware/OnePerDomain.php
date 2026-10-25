<?php

namespace App\Modules\Domains\Jobs\Middleware;

use App\Modules\Domains\Support\DomainRegistrarLock;

/**
 * Runs a registrar job under the domain's shared lock. A job that finds it taken is released back to the queue after
 * a short, jittered delay (never deleted), so it runs once the holder is done. Releases count as attempts in
 * Laravel, which is why the jobs bound their life with retryUntil() and maxExceptions instead of tries.
 */
class OnePerDomain
{
    /** Seconds before a blocked job is looked at again (plus up to RELEASE_JITTER). */
    public const RELEASE_DELAY = 30;

    public const RELEASE_JITTER = 15;

    public function __construct(private int $domainId) {}

    /**
     * @param  mixed  $job  the queued command (it uses InteractsWithQueue)
     */
    public function handle($job, callable $next): void
    {
        $lock = DomainRegistrarLock::for($this->domainId);

        if (! $lock->get()) {
            $job->release(self::RELEASE_DELAY + random_int(0, self::RELEASE_JITTER));

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
