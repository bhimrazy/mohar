<?php

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret']);
});

function rr213Body(PaymentAttempt $attempt, string $status, ?string $refId = null): array
{
    return ['product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => 100.0, 'status' => $status, 'ref_id' => $refId];
}

test('R1: a short late window still books a payment taken inside the booking window after an early NOT_FOUND end', function (int $hours) {
    billingSettings(['gateways.late_lookup_hours' => $hours]);
    [, $invoice] = nprInvoice([10_000]);
    $checkout = $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location');
    $this->get((string) $checkout);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();

    $paid = false;
    Http::fake(['https://rc.esewa.com.np/api/epay/transaction/status/*' => function () use (&$paid, $attempt) {
        return Http::response(rr213Body($attempt, $paid ? 'COMPLETE' : 'NOT_FOUND', $paid ? '0009RR1' : null));
    }]);

    // Poller sees NOT_FOUND at minute 6 (form not yet posted): attempt ends as expired, lookups stay open.
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    // Customer posts the stale form and pays at minute 30; the browser never comes back. Scheduler keeps running.
    $this->travel(24)->minutes();
    $paid = true;
    foreach (range(1, 30) as $_) {
        $this->travel(5)->minutes();
        $this->artisan('billing:reconcile-payments');
    }

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->count())->toBe(1);
})->with(['1 hour' => 1, '2 hours' => 2]);
