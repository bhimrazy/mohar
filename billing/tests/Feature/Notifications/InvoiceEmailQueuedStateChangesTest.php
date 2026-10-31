<?php

use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\PaymentReceived;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Jobs\ComposeEventEmails;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\NotificationMailer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/helpers.php';

/*
| Re-check of #600: the invoice changes between the event and the worker; and SendEmailMessage is unique per message.
*/

beforeEach(function () {
    Storage::fake('objects');
    seedEmailTemplates();
    $this->client = billingClient();
});

function runQueuedComposers(?Closure $filter = null): void
{
    Queue::pushed(ComposeEventEmails::class)
        ->filter(fn (ComposeEventEmails $job): bool => $filter === null || $filter($job->event))
        ->each(fn (ComposeEventEmails $job) => app()->call([unserialize(serialize($job)), 'handle']));
}

test('a receipt for a payment refunded before the worker runs still states its own payment, after the refund email', function () {
    $invoice = issueInvoice($this->client, [10_000]);
    Queue::fake();

    $payment = payInvoice($invoice, 10_000);
    app(RefundTransaction::class)->handle($payment, 10_000, RefundMode::RecordOnly);
    runQueuedComposers(fn (object $event): bool => $event instanceof PaymentReceived);

    $receipt = EmailMessage::query()->where('template_key', 'invoice.payment_confirmation')->sole();
    $refund = EmailMessage::query()->where('template_key', 'invoice.refunded')->sole();
    expect($receipt->body_text)->toContain('paid in full')
        ->and($refund->id)->toBeLessThan($receipt->id)
        ->and($invoice->fresh()->status->value)->toBe('refunded');
});

test('a reminder for a partly paid invoice is still sent', function () {
    $invoice = issueInvoice($this->client, [10_000]);
    Queue::fake();

    event(new InvoicePaymentReminder($invoice->id, $this->client->id, 'overdue_1'));
    payInvoice($invoice, 4_000);
    runQueuedComposers(fn (object $event): bool => $event instanceof InvoicePaymentReminder);

    expect(EmailMessage::query()->where('template_key', 'invoice.overdue_first')->sole()->body_text)->toContain('$60.00');
});

test('a client cannot be deleted while their invoice email is queued: invoices restrict the delete', function () {
    Queue::fake();
    issueInvoice($this->client, [10_000]);

    expect(fn () => $this->client->delete())->toThrow(QueryException::class);
});

test('an invoice cancelled before the worker runs gets no new-invoice email asking for payment', function () {
    Queue::fake();
    $invoice = issueInvoice($this->client, [10_000]);
    app(CancelInvoice::class)->handle($invoice);

    runQueuedComposers();

    $created = EmailMessage::query()->where('template_key', 'invoice.created')->first();
    expect($created)->toBeNull();
});

test('staff resend a sent email within the hour: it is sent again', function () {
    Mail::fake();
    issueInvoice($this->client, [10_000]); // sync queue: composed and sent now
    $message = EmailMessage::query()->where('template_key', 'invoice.created')->sole();
    expect($message->status)->toBe(EmailStatus::Sent);

    app(NotificationMailer::class)->resend($message->fresh());

    Mail::assertSentCount(2);
});

test('staff resend after the last SMTP attempt failed, within the hour: it is sent', function () {
    config(['notifications.mail_max_attempts' => 1, 'mail.default' => 'unreachable', 'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 2]]);
    issueInvoice($this->client, [10_000]);
    $message = EmailMessage::query()->where('template_key', 'invoice.created')->sole();
    expect($message->status)->toBe(EmailStatus::Failed);

    Mail::fake();
    app(NotificationMailer::class)->resend($message->fresh());

    Mail::assertSentCount(1);
    expect($message->fresh()->status)->toBe(EmailStatus::Sent);
});

test('staff resend while the first send is still waiting to retry: no second job, the waiting one sends it', function () {
    Queue::fake();
    issueInvoice($this->client, [10_000]);
    runQueuedComposers();
    $message = EmailMessage::query()->where('template_key', 'invoice.created')->sole();
    Queue::assertPushed(SendEmailMessage::class, 1);

    app(NotificationMailer::class)->resend($message);

    Queue::assertPushed(SendEmailMessage::class, 1);
    expect($message->fresh()->status)->toBe(EmailStatus::Queued);
});
