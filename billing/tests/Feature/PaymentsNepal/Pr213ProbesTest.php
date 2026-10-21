<?php

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

const P213_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/*';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret']);
});

/** @return array{0: mixed, 1: PaymentAttempt, 2: string} invoice, attempt, cancel url */
function p213Start(int $amount = 10_000): array
{
    [, $invoice] = nprInvoice([$amount]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location');
    $page = test()->get((string) $checkout);

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail(), (string) $page->inertiaProps('form.fields')['failure_url']];
}

/** @return array<string, mixed> */
function p213Body(PaymentAttempt $attempt, string $status, ?string $refId = null): array
{
    return ['product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => 100.0, 'status' => $status, 'ref_id' => $refId];
}

test('P1: with the late sweep off, a form posted after the 5-minute cut and then paid is still booked', function () {
    billingSettings(['gateways.late_lookup_hours' => 0]);
    [$invoice, $attempt] = p213Start();
    Http::fake([P213_STATUS => Http::sequence()->push(p213Body($attempt, 'NOT_FOUND'))->whenEmpty(Http::response(p213Body($attempt, 'COMPLETE', '0009PRB')))]);

    // The browser did not auto-post (slow network, blocked script); the poller sees NOT_FOUND at minute 6.
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    // The customer then posts the form and pays; eSewa now answers COMPLETE. They come back, and the poller runs.
    $this->travel(10)->minutes();
    $this->get(route('billing.gateways.return', [EsewaDriver::KEY, 'ref' => $attempt->reference]));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->count())->toBe(1);
});

test('P2: a closed attempt answers cancelled=1 returns with a provider lookup every 10 seconds', function () {
    [, $attempt, $cancelUrl] = p213Start();
    Http::fake([P213_STATUS => Http::response(p213Body($attempt, 'NOT_FOUND'))]);

    $this->get($cancelUrl);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed);

    for ($i = 0; $i < 6; $i++) {
        $this->travel(11)->seconds();
        $this->get($cancelUrl);
    }

    // 1 lookup per minute would be 2 here; 7 shows the floor applies to closed attempts too.
    expect(Http::recorded()->count())->toBe(2);
});

test('P3: a cancel return before the form reaches eSewa ends the attempt at once', function () {
    [$invoice, $attempt, $cancelUrl] = p213Start();
    Http::fake([P213_STATUS => Http::response(p213Body($attempt, 'NOT_FOUND'))]);

    $this->get($cancelUrl);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});
