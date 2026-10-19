<?php

namespace App\Modules\Services\Jobs;

use App\Modules\Services\Actions\VerifyUnknownOutcomes;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks a server whether one command with an unknown outcome ran, queued by the `services:verify-unknown` sweep so the
 * scheduler never waits on a panel (ADR 0017 §2). One try: the next sweep asks again while the item is open.
 */
class VerifyUnknownOutcome implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $itemId)
    {
        $this->onQueue(Queues::PROVISIONING);
    }

    public function uniqueId(): string
    {
        return 'verify-unknown:'.$this->itemId;
    }

    public function handle(VerifyUnknownOutcomes $verify): void
    {
        $item = ModuleQueueItem::query()->with(['service.server', 'service.client'])->find($this->itemId);

        if ($item === null) {
            return;
        }

        try {
            $verify->check($item);
        } catch (Throwable $exception) {
            // One item that cannot be checked never stops the others; the next sweep asks again.
            report($exception);
        }
    }
}
