<?php

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\InvoiceCreated;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

/**
 * @return list<InvoiceLineData>
 */
function renewalLines(): array
{
    $start = CarbonImmutable::parse('2026-11-01');

    return [
        new InvoiceLineData('Hosting', 1_000, billableType: BillableType::Service, billableId: 1, periodStart: $start, periodEnd: $start->addMonth()),
        new InvoiceLineData('Promo', -100, billableType: BillableType::Promotion, billableId: 7),
        new InvoiceLineData('Backups', 500, billableType: BillableType::ServiceAddon, billableId: 2, periodStart: $start, periodEnd: $start->addMonth()),
    ];
}

function issueRenewal(int $clientId, string $reason = 'renewal'): void
{
    app(Invoicer::class)->issue(new InvoiceDraft($clientId, 'USD', renewalLines(), CarbonImmutable::parse('2026-11-01'), reason: $reason));
}

test('renewal lines raised together are grouped on one invoice by default', function () {
    $client = billingClient();

    issueRenewal($client->id);

    expect(Invoice::query()->where('client_id', $client->id)->count())->toBe(1)
        ->and(Invoice::query()->firstOrFail()->total)->toBe(1_400);
});

test('separate invoices split a renewal per item and keep promotions with their item', function () {
    billingSettings(['separate_invoices' => true]);
    $client = billingClient();

    issueRenewal($client->id);

    $totals = Invoice::query()->where('client_id', $client->id)->orderBy('id')->pluck('total')->all();
    expect($totals)->toBe([900, 500]);
});

test('a client override wins over the default, and orders are never split', function () {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'separate_invoices' => true]);

    issueRenewal($client->id, 'order');
    expect(Invoice::query()->where('client_id', $client->id)->count())->toBe(1);

    app(Invoicer::class)->cancel(Invoice::query()->where('client_id', $client->id)->value('id'));
    issueRenewal($client->id);
    expect(Invoice::query()->where('client_id', $client->id)->count())->toBe(3);
});

test('staff set the per-client invoicing preference', function () {
    actingAsStaff(['billing.credit.manage']);
    $client = billingClient();

    $this->put(route('admin.billing.clients.preferences', $client), ['separate_invoices' => true])->assertRedirect();

    expect(ClientFlag::query()->whereKey($client->id)->value('separate_invoices'))->toBeTrue();
});

test('InvoiceCreated carries the notify flag and the reason', function () {
    Event::fake([InvoiceCreated::class]);
    $client = billingClient();

    app(Invoicer::class)->issue(new InvoiceDraft($client->id, 'USD', [new InvoiceLineData('X', 100)], notify: false, reason: 'order'));

    Event::assertDispatched(InvoiceCreated::class, fn (InvoiceCreated $event) => $event->notify === false && $event->reason === 'order');
});
