<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Models\Domain;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Renew a domain at the registrar after its renewal invoice was paid (or on staff request).
 * Unique per domain and cycle, never overlapping another registrar job for the domain; PerformRenewal
 * claims the cycle so a retry after an unknown outcome verifies instead of renewing again.
 */
class RenewDomain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds: above the drivers' HTTP timeouts (10 s connect + 90 s), below the registrar pool's 200 s (ADR 0017 §3). */
    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $domainId,
        public int $years,
        public ?string $fromExpiry = null,
        public ?int $invoiceId = null,
        public ?int $staffId = null,
    ) {
        $this->onQueue(Queues::REGISTRAR);
    }

    public function uniqueId(): string
    {
        return 'domain:'.$this->domainId.':renew:'.($this->fromExpiry ?? 'none');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('domain:'.$this->domainId))->dontRelease()->expireAfter(600)];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PerformRenewal $renewal): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain !== null) {
            $renewal->handle($domain, $this->years, $this->fromExpiry, $this->staffId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain !== null) {
            app(PerformRenewal::class)->failed($domain, $exception->getMessage());
        }
    }
}
