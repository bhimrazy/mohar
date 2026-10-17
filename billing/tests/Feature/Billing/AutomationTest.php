<?php

use App\Events\Billing\InvoiceOverdue;
use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\LateFeeApplied;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentPending;
use App\Events\Billing\PayMethodDisabled;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

function runAutomation(string $date, ?string $only = null): void
{
    test()->travelTo(CarbonImmutable::parse($date)->setTime(3, 0));
    test()->artisan('automation:run', array_filter(['--date' => $date, '--only' => $only]))->assertSuccessful();
}

test('the before-due reminder is sent once and never repeated', function () {
    Event::fake([InvoicePaymentReminder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $invoice = issueInvoice(billingClient(), [5_000], CarbonImmutable::parse('2026-05-20'));

    runAutomation('2026-05-13', 'billing.reminders');
    runAutomation('2026-05-14', 'billing.reminders');
    runAutomation('2026-05-20', 'billing.reminders');
    $this->artisan('automation:run', ['--date' => '2026-05-20', '--only' => 'billing.reminders', '--force' => true])->assertSuccessful();

    Event::assertDispatchedTimes(InvoicePaymentReminder::class, 1);
    Event::assertDispatched(InvoicePaymentReminder::class, fn ($event) => $event->stage === 'before_due' && $event->invoiceId === $invoice->id);
    expect($invoice->fresh()->reminder_log)->toBe(['before_due' => '2026-05-13']);
});

test('overdue reminders follow the configured days and re-runs do not double-send', function () {
    Event::fake([InvoicePaymentReminder::class, InvoiceOverdue::class]);
    billingSettings(['reminders.before_due_days' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    issueInvoice(billingClient(), [5_000], CarbonImmutable::parse('2026-05-10'));

    foreach (['2026-05-11', '2026-05-12', '2026-05-14'] as $date) {
        runAutomation($date, 'billing.reminders');
    }
    $this->artisan('automation:run', ['--date' => '2026-05-14', '--only' => 'billing.reminders', '--force' => true])->assertSuccessful();

    Event::assertDispatchedTimes(InvoicePaymentReminder::class, 2);
    Event::assertDispatched(InvoicePaymentReminder::class, fn ($event) => $event->stage === 'overdue_2');
    Event::assertDispatchedTimes(InvoiceOverdue::class, 1);
    Event::assertDispatched(InvoiceOverdue::class, fn ($event) => $event->daysOverdue === 1);
});

test('reminders skip paid and payment-pending invoices', function () {
    Event::fake([InvoicePaymentReminder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-05')), 5_000);
    issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-05'))->update(['status' => InvoiceStatus::PaymentPending]);

    runAutomation('2026-05-07', 'billing.reminders');

    Event::assertNotDispatched(InvoicePaymentReminder::class);
});

test('a late fee is added once with the configured minimum', function () {
    Event::fake([LateFeeApplied::class]);
    billingSettings(['late_fee.amount' => 10, 'late_fee.minimum' => 500, 'late_fee.days_overdue' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $invoice = issueInvoice(billingClient(), [3_000], CarbonImmutable::parse('2026-05-10'));

    runAutomation('2026-05-16', 'billing.late_fees');
    expect($invoice->fresh()->balance)->toBe(3_000);

    runAutomation('2026-05-17', 'billing.late_fees');
    runAutomation('2026-05-18', 'billing.late_fees');

    expect($invoice->fresh()->balance)->toBe(3_500)
        ->and($invoice->adjustments()->where('reason', 'late_fee')->count())->toBe(1)
        ->and($invoice->adjustments()->where('reason', 'late_fee')->value('kind')->value)->toBe('debit');
    Event::assertDispatchedTimes(LateFeeApplied::class, 1);
});

test('late fees are not added to add-funds invoices or when disabled', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $invoice = issueInvoice(billingClient(), [3_000], CarbonImmutable::parse('2026-05-02'));

    runAutomation('2026-05-30', 'billing.late_fees');

    expect($invoice->fresh()->balance)->toBe(3_000);
});

test('auto-capture charges the default card the day before the due date', function () {
    sandboxGateway();
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    PayMethod::factory()->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-10'));

    runAutomation('2026-05-08', 'billing.auto_capture');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    runAutomation('2026-05-09', 'billing.auto_capture');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->transactions()->first()->gateway)->toBe('sandbox_card');
});

test('declined captures retry after 3 and 7 days and disable the card after 3 failures', function () {
    Event::fake([PaymentFailed::class, PayMethodDisabled::class]);
    sandboxGateway();
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    $card = PayMethod::factory()->withCard('4000000000000002')->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'));

    foreach (['2026-05-01', '2026-05-02', '2026-05-03', '2026-05-04', '2026-05-05', '2026-05-07', '2026-05-08', '2026-05-12'] as $date) {
        runAutomation($date, 'billing.auto_capture');
    }

    $attempts = $invoice->fresh()->capture_attempts;
    expect($attempts)->toBe(3)
        ->and($card->fresh()->failure_count)->toBe(3)
        ->and($card->fresh()->disabled_at)->not->toBeNull();
    Event::assertDispatchedTimes(PaymentFailed::class, 3);
    Event::assertDispatchedTimes(PayMethodDisabled::class, 1);
});

test('a pending capture moves the invoice to payment pending', function () {
    Event::fake([PaymentPending::class]);
    sandboxGateway();
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    PayMethod::factory()->withCard('4000000000000077')->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'));

    runAutomation('2026-05-01', 'billing.auto_capture');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PaymentPending);
    Event::assertDispatched(PaymentPending::class);
});

test('auto-cancel skips invoices with partial payments', function () {
    billingSettings(['auto_cancel.days_overdue' => 30]);
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    $untouched = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'));
    $partial = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'));
    payInvoice($partial, 1_000);

    runAutomation('2026-06-01', 'billing.auto_cancel');

    expect($untouched->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($partial->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('delivered quotes expire after their validity', function () {
    $quote = Quote::factory()->delivered()->create(['client_id' => billingClient()->id, 'valid_until' => '2026-05-10']);

    runAutomation('2026-05-11', 'billing.housekeeping');

    expect($quote->fresh()->stage->value)->toBe('expired');
});
