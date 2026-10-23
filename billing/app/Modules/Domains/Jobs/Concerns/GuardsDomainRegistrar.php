<?php

namespace App\Modules\Domains\Jobs\Concerns;

use App\Modules\Domains\Jobs\Middleware\OnePerDomain;
use DateTimeInterface;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * For jobs that call the registrar about $domainId: they share one per-domain lock (see OnePerDomain).
 *
 * Laravel counts every release as an attempt, so waiting for the lock would use up a paid job's `tries`. These jobs
 * therefore set no `tries`: they are retried until retryUntil() and fail after $maxExceptions real errors or timeouts
 * (a crashed worker counts too). Attempts still rise with every wait; only the limit that applies has changed.
 *
 * retryUntil() is fixed when the job is dispatched, so it also covers time spent in the queue: it is 24 hours so a
 * registrar queue that is down or backlogged for hours still runs paid work when it returns. A job that reaches it
 * fails through failed(), which tells staff in plain words.
 *
 * On the `sync` queue driver a released job is not queued again, so a job that meets a held lock is dropped. Use a real
 * queue driver (the install pre-flight already warns about `sync`).
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

    /** Hours from dispatch; below uniqueFor, far above any holder (a job's 180 s timeout). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    /** The text staff see in last_error and the activity log: the queue's own messages name classes, so they are replaced. */
    private function failureText(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof MaxAttemptsExceededException => __('This did not run: it waited too long for another registrar action on this domain, or failed several times in a row. Check the domain at the registrar, then retry it.'),
            default => $exception->getMessage(),
        };
    }
}
