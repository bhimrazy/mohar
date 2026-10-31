<?php

namespace App\Modules\Notifications\Jobs;

use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoiceModified;
use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\PaymentReceived;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Composes the emails of an invoice event whose template attaches the invoice PDF (#566), in a notifications worker
 * rather than in the request or automation run that raised it: the PDF render and the "attached" wording happen here,
 * together. Each message is then sent by its own SendEmailMessage job. A render failure is reported and drops only the
 * claim and the attachment. Any other failure fails the attempt and the job retries: the dedupe key keeps a retry from
 * logging a message twice, and a logged message whose send was never queued gets it queued.
 */
class ComposeEventEmails implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** As SendEmailMessage: below the connection's retry_after, so one event is never composed twice in parallel. */
    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $invoiceAtEvent  the invoice's `invoice_due` fields when the event was raised (receipts)
     */
    public function __construct(public InvoiceCreated|InvoicePaymentReminder|PaymentReceived|InvoiceModified $event, public array $invoiceAtEvent = [])
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
        $listener->composeQueued($this->event, $this->invoiceAtEvent);
    }
}
