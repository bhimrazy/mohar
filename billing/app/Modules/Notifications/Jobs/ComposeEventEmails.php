<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Composes the emails of an invoice event whose template attaches the invoice PDF (#566), in a notifications worker
 * rather than in the request or automation run that raised it: the PDF render and the "attached" wording happen here,
 * together. Each message is then sent by its own SendEmailMessage job. A render failure is reported and drops only the
 * claim and the attachment; the dedupe key makes a retried job (worker killed, timeout) log nothing twice.
 */
class ComposeEventEmails implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** As SendEmailMessage: below the connection's retry_after, so one event is never composed twice in parallel. */
    public int $timeout = 60;

    public function __construct(public object $event)
    {
        $this->tries = max(1, (int) config('notifications.mail_max_attempts', 3));
        $this->onQueue(Queues::DOCUMENT_EMAILS);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('notifications.mail_backoff_seconds', [60, 300, 1800])));
    }

    public function handle(SendEventEmails $listener): void
    {
        $listener->compose($this->event);
    }
}
