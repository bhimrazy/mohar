<?php

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Submit a paid registration to the registrar. Unique per domain and never overlapping with another
 * registrar job for it; only errors known not to have executed are retried (see SubmitToRegistrar).
 */
class RegisterDomain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds a queued duplicate is refused (released as soon as the job finishes). */
    public int $uniqueFor = 3600;

    public function __construct(public int $domainId, public ?int $staffId = null) {}

    public function uniqueId(): string
    {
        return 'domain:'.$this->domainId;
    }

    /**
     * Never run two registrar submissions for the same domain at once (any command, any worker).
     *
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
