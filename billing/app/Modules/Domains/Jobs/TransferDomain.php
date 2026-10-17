<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Submit a paid transfer-in to the registrar. Only transient errors are retried; timeouts and
 * explicit rejections end the job (see SubmitToRegistrar).
 */
class TransferDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $domainId, public ?int $staffId = null) {}

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
            app(SubmitToRegistrar::class)->failed($domain, $domain->type, $exception->getMessage());
        }
    }
}
