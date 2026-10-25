<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Jobs\Concerns\GuardsDomainRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Submit a paid transfer-in to the registrar. Unique per domain and never overlapping with another
 * registrar job or sync for it (they wait their turn, see GuardsDomainRegistrar); only errors known not to have executed are retried (see SubmitToRegistrar).
 */
class TransferDomain implements ShouldBeUnique, ShouldQueue
{
    use GuardsDomainRegistrar;
    use Queueable;

    /** Seconds: above the drivers' HTTP timeouts (10 s connect + 90 s), below the registrar pool's 200 s (ADR 0017 §3). */
    public int $timeout = 180;

    /** Seconds a queued duplicate is refused (released as soon as the job finishes); above retryUntil (24 h). */
    public int $uniqueFor = 90000;

    public function __construct(public int $domainId, public ?int $staffId = null)
    {
        $this->onQueue(Queues::REGISTRAR);
    }

    public function uniqueId(): string
    {
        return 'domain:'.$this->domainId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SubmitToRegistrar $submit): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain !== null) {
            $submit->handle($domain, $this->staffId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain !== null) {
            app(SubmitToRegistrar::class)->failed($domain, $domain->type, $this->failureText($exception));
        }
    }
}
