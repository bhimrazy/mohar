<?php

use App\Events\Billing\InvoiceModified;
use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\PaymentReceived;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Notifications\Jobs\ComposeEventEmails;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\RecipientResolver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/helpers.php';

/*
| Invoice emails composed later, in a notifications worker (#566): what the worker reads is the invoice as it stands
| then, so the text must still fit the event that queued it.
*/

beforeEach(function () {
    Storage::fake('objects');
    seedEmailTemplates();
    $this->client = billingClient();
});

/** Runs the queued compose jobs as a worker would: from their serialised payload. */
function runComposeJobs(?Closure $filter = null): void
{
    Queue::pushed(ComposeEventEmails::class)
        ->filter(fn (ComposeEventEmails $job): bool => $filter === null || $filter($job->event))
        ->each(fn (ComposeEventEmails $job) => app()->call([unserialize(serialize($job)), 'handle']));
}

test('a reminder for an invoice paid before the worker composes it is not sent', function () {
    $invoice = issueInvoice($this->client, [10_000]);
    Queue::fake();

    event(new InvoicePaymentReminder($invoice->id, $this->client->id, 'overdue_1'));
    payInvoice($invoice, 10_000);
    runComposeJobs(fn (object $event): bool => $event instanceof InvoicePaymentReminder);

    expect(EmailMessage::query()->where('template_key', 'invoice.overdue_first')->exists())->toBeFalse();
});

test('a receipt composed after a later payment still states what its own payment left due', function () {
    $invoice = issueInvoice($this->client, [10_000]);
    Queue::fake();

    payInvoice($invoice, 4_000);
    payInvoice($invoice->fresh(), 6_000);
    $first = Queue::pushed(ComposeEventEmails::class)->first(fn (ComposeEventEmails $job): bool => $job->event instanceof PaymentReceived);
    app()->call([$first, 'handle']);

    $receipt = EmailMessage::query()->where('template_key', 'invoice.payment_confirmation')->sole();
    expect($receipt->body_text)->toContain('still due')
        ->and($receipt->body_text)->not->toContain('paid in full');
});

test('an invoice email whose invoice is gone when the worker runs is not sent with blank invoice fields', function () {
    $invoice = issueInvoice($this->client, [10_000]);
    Queue::fake();

    event(new InvoiceModified($invoice->id, $this->client->id));
    Invoice::query()->whereKey($invoice->id)->delete();
    runComposeJobs();

    expect(EmailMessage::query()->where('template_key', 'invoice.modified')->exists())->toBeFalse();
});

test('a compose job delivered twice, or read by two workers, logs and sends each message once', function () {
    Queue::fake();
    $invoice = issueInvoice($this->client, [10_000]);
    $job = Queue::pushed(ComposeEventEmails::class)->sole();
    $payload = serialize($job);

    app()->call([unserialize($payload), 'handle']);
    app()->call([unserialize($payload), 'handle']);

    expect(EmailMessage::query()->where('template_key', 'invoice.created')->where('related_id', $invoice->id)->count())->toBe(1);
    Queue::assertPushed(SendEmailMessage::class, 1);
});

test('the compose job carries the event ids and its occurrence id, no models', function () {
    Queue::fake();
    issueInvoice($this->client, [10_000]);
    $job = Queue::pushed(ComposeEventEmails::class)->sole();
    $payload = serialize($job);

    expect($payload)->not->toContain('Models\\')
        ->and($payload)->toContain($job->event->occurrenceId())
        ->and(unserialize($payload)->event->occurrenceId())->toBe($job->event->occurrenceId());
});

test('a transient failure while the worker composes reaches the queue, so the job is retried', function () {
    Queue::fake();
    $invoice = issueInvoice($this->client, [10_000]);
    $job = Queue::pushed(ComposeEventEmails::class)->sole();
    $this->partialMock(RecipientResolver::class, fn ($mock) => $mock->shouldReceive('forClient')->once()->andThrow(new RuntimeException('connection lost')));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
});
