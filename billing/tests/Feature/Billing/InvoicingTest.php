<?php

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\PublishInvoice;
use App\Modules\Billing\Actions\ReopenInvoice;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

test('the invoicer issues an unpaid numbered invoice with a client snapshot', function () {
    Event::fake([InvoiceCreated::class]);
    $client = billingClient(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'city' => 'London']);

    $result = app(Invoicer::class)->issue(new InvoiceDraft($client->id, 'USD', [
        new InvoiceLineData('Web hosting', 1500, 2, billableType: BillableType::Service, billableId: 7, periodStart: CarbonImmutable::parse('2026-11-01'), periodEnd: CarbonImmutable::parse('2026-12-01')),
    ]));

    $invoice = Invoice::query()->findOrFail($result->invoiceId);
    expect($result)->status->toBe('unpaid')->total->toBe(3000)->number->toBe('1')
        ->and($invoice)->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(3000)
        ->and($invoice->issue_date->isToday())->toBeTrue()
        ->and($invoice->client_snapshot)->toMatchArray(['name' => 'Ada Lovelace', 'city' => 'London']);
    Event::assertDispatched(InvoiceCreated::class, fn (InvoiceCreated $event) => $event->invoiceId === $invoice->id && $event->clientId === $client->id);

    $client->update(['city' => 'Paris']);
    expect($invoice->fresh()->client_snapshot['city'])->toBe('London');
});

test('issued invoices are immutable', function () {
    $invoice = issueInvoice(billingClient());

    expect(fn () => $invoice->items()->first()->update(['unit_amount' => 1]))->toThrow(ImmutableInvoiceException::class)
        ->and(fn () => $invoice->update(['total' => 1]))->toThrow(ImmutableInvoiceException::class)
        ->and(fn () => $invoice->update(['number' => 'X-1']))->toThrow(ImmutableInvoiceException::class);

    $invoice->fresh()->update(['notes' => 'Editable', 'due_date' => '2027-01-01']);
    expect($invoice->fresh()->notes)->toBe('Editable');
});

test('consecutive publishes get consecutive numbers without gaps', function () {
    $client = billingClient();
    $first = issueInvoice($client, status: 'draft');
    $second = issueInvoice($client, status: 'draft');

    app(PublishInvoice::class)->handle($second);
    app(PublishInvoice::class)->handle($first);

    expect([$second->fresh()->number, $first->fresh()->number])->toBe(['1', '2']);
});

test('number format, padding and next number are applied', function () {
    billingSettings(['number_format' => 'INV-{YEAR}-{NUMBER}', 'number_padding' => 5, 'number_reset' => 'yearly']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    BillingSequence::query()->create(['sequence' => 'invoice', 'period' => '2026', 'next_number' => 42]);

    expect(issueInvoice(billingClient())->number)->toBe('INV-2026-00042');
});

test('yearly reset restarts the number in a new year', function () {
    billingSettings(['number_format' => 'INV-{YEAR}-{NUMBER}', 'number_reset' => 'yearly']);
    $client = billingClient();
    $this->travelTo(CarbonImmutable::parse('2026-12-31'));
    issueInvoice($client);
    issueInvoice($client);

    $this->travelTo(CarbonImmutable::parse('2027-01-01'));

    expect(issueInvoice($client)->number)->toBe('INV-2027-1');
});

test('proforma mode assigns the legal number only when paid', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $client = billingClient();
    $invoice = issueInvoice($client);

    expect($invoice)->number->toBeNull()->proforma_number->toBe('PF-1');

    $this->travelTo(now()->addDays(3));
    payInvoice($invoice, 10_000);

    expect($invoice->fresh())->number->toBe('1')->status->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->issue_date->isToday())->toBeTrue();
});

test('drafts are unnumbered and publishing sets issue and due dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    $draft = issueInvoice(billingClient(), status: 'draft');

    expect($draft)->number->toBeNull()->status->toBe(InvoiceStatus::Draft);

    app(PublishInvoice::class)->handle($draft);

    expect($draft->fresh())->number->toBe('1')
        ->and($draft->fresh()->issue_date->toDateString())->toBe('2026-03-01')
        ->and($draft->fresh()->due_date->toDateString())->toBe('2026-03-15');
});

test('a zero-total invoice is paid immediately', function () {
    Event::fake([InvoicePaid::class]);
    $invoice = issueInvoice(billingClient(), [0]);

    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('invoices must be in the client currency', function () {
    $client = billingClient();

    app(Invoicer::class)->issue(new InvoiceDraft($client->id, 'EUR', [new InvoiceLineData('X', 100)]));
})->throws(BillingException::class);

test('the period guard prevents invoicing the same billable period twice', function () {
    $client = billingClient();
    $period = CarbonImmutable::parse('2026-11-01');
    $line = new InvoiceLineData('Hosting', 1000, billableType: BillableType::Service, billableId: 9, periodStart: $period);
    $invoicer = app(Invoicer::class);

    $first = issueInvoice($client, [$line]);

    expect($invoicer->hasInvoiceForPeriod(BillableType::Service, 9, $period))->toBeTrue()
        ->and($invoicer->hasOpenInvoiceFor(BillableType::Service, 9))->toBeTrue()
        ->and(fn () => issueInvoice($client, [$line]))->toThrow(BillingException::class);

    $invoicer->cancel($first->id);

    expect($invoicer->hasInvoiceForPeriod(BillableType::Service, 9, $period))->toBeFalse()
        ->and($invoicer->hasOpenInvoiceFor(BillableType::Service, 9))->toBeFalse()
        ->and(issueInvoice($client, [$line])->status)->toBe(InvoiceStatus::Unpaid);
});

test('cancelling zeroes the balance with a credit note and dispatches InvoiceCancelled', function () {
    Event::fake([InvoiceCancelled::class]);
    $invoice = issueInvoice(billingClient());

    app(Invoicer::class)->cancel($invoice->id);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0)
        ->and(app(Invoicer::class)->status($invoice->id))->toBe('cancelled')
        ->and($invoice->adjustments()->where('reason', 'cancellation')->value('amount'))->toBe(10_000);
    Event::assertDispatched(InvoiceCancelled::class);
});

test('paid invoices cannot be cancelled by staff and the invoicer ignores them', function () {
    $invoice = issueInvoice(billingClient());
    payInvoice($invoice, 10_000);

    app(Invoicer::class)->cancel($invoice->id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    app(CancelInvoice::class)->handle($invoice);
})->throws(BillingException::class);

test('a cancelled invoice can be reopened', function () {
    $invoice = issueInvoice(billingClient());
    app(CancelInvoice::class)->handle($invoice);

    app(ReopenInvoice::class)->handle($invoice);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(10_000);
});

test('the invoicer reports missing invoices as null', function () {
    expect(app(Invoicer::class)->status(999_999))->toBeNull();
});
