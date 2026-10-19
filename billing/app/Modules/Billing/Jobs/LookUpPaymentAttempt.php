<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One gateway lookup for a pending hosted payment, queued by the reconciliation sweep so the scheduler never waits
 * on a gateway (ADR 0017 §2). One try: the next sweep looks again. Settling is idempotent and takes its own lock.
 */
class LookUpPaymentAttempt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** A queued duplicate for the same attempt is refused until this one finishes or the window ends. */
    public int $uniqueFor = 300;

    public function __construct(public int $attemptId)
    {
        $this->onQueue(Queues::PAYMENTS);
    }

    public function uniqueId(): string
    {
        return 'payment-attempt:'.$this->attemptId;
    }

    public function handle(SettlePaymentAttempt $settle): void
    {
        $attempt = PaymentAttempt::query()->find($this->attemptId);

        if ($attempt === null || ! $attempt->status->isOpen()) {
            return;
        }

        try {
            $settle->handle($attempt);
        } catch (Throwable $exception) {
            // A gateway that cannot answer now is asked again by the next sweep; no failed-job row every 5 minutes.
            report($exception);
        }
    }
}
