<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renew a domain at the registrar after its renewal invoice was paid (or on staff request).
 * $fromExpiry makes retries safe: a renewal already applied is never sent twice.
 */
class RenewDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $domainId,
        public int $years,
        public ?string $fromExpiry = null,
        public ?int $invoiceId = null,
        public ?int $staffId = null,
    ) {}

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
